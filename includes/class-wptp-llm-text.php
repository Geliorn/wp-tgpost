<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Announcement title + text written by the model, for {llm_title} and
 * {llm_description}. Same as WPPF_LLM_Text in wp-pinfeed, with a prompt for
 * a Telegram channel post instead of a pin.
 *
 * Called by the background tick right before a message is sent (a message,
 * unlike a feed item, goes out once): the dispatcher asks for the text if the
 * channel wants it and there's budget left; if the model fails, the template
 * chain falls through to the next placeholder ({seo_description}, {excerpt}…).
 *
 * Cache: one meta array per post, keyed by a hash of the prompt-relevant
 * channel settings, with the post's modified time stored next to the answer —
 * editing the post or the channel's prompt makes the entry stale on its own.
 */
class WPTP_LLM_Text {

	const META        = '_wptp_llm';
	const MAX_ENTRIES = 6;

	const SYSTEM_PROMPT = 'Ты — редактор Telegram-канала, пишешь анонсы материалов сайта. Анонс читают в ленте канала с телефона: заголовок цепляет сутью, текст за несколько предложений объясняет, что внутри и зачем читать дальше. Пиши живо и конкретно, без канцелярита и кликбейта. Не используй хэштеги (их добавят отдельно), не вставляй ссылки, не используй markdown и HTML, не выдумывай факты, которых нет в исходных данных. Ответ — строго JSON-объект {"title": "...", "description": "..."} без пояснений.';

	public static function enabled( array $config ) {
		return ! empty( $config['llm_enabled'] ) && WPTP_Settings::llm_ready( $config['llm_provider'] );
	}

	public static function config_hash( array $config ) {
		return WPTP_Channel_Config::hash( $config, array( 'llm_provider', 'llm_language', 'llm_prompt', 'llm_keywords', 'llm_length' ) );
	}

	/** @return array{title:string,description:string}|null */
	public static function cached( WP_Post $post, array $config ) {
		if ( empty( $config['llm_enabled'] ) ) {
			return null;
		}

		$all  = self::all( $post->ID );
		$hash = self::config_hash( $config );
		if ( empty( $all[ $hash ] ) || ( $all[ $hash ]['modified'] ?? '' ) !== $post->post_modified_gmt ) {
			return null;
		}
		return array(
			'title'       => (string) $all[ $hash ]['title'],
			'description' => (string) $all[ $hash ]['description'],
		);
	}

	public static function needs_generation( WP_Post $post, array $config ) {
		return self::enabled( $config ) && null === self::cached( $post, $config ) && ! self::recently_failed( $post->ID, $config );
	}

	/**
	 * Calls the model and stores the answer.
	 *
	 * @return array{title:string,description:string}|null
	 */
	public static function generate( WP_Post $post, array $config ) {
		$client = new WPTP_LLM_Client( $config['llm_provider'] );
		$data   = $client->json( self::SYSTEM_PROMPT, self::user_prompt( $post, $config ), 800, 0.7 );

		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['description'] ) ) {
			$error = $client->last_error() ? $client->last_error() : 'в ответе нет title/description';
			WPTP_Log::error( 'LLM: запись #' . $post->ID . ' («' . get_the_title( $post ) . '»): ' . $error );
			self::mark_failed( $post->ID, $config );
			return null;
		}

		$answer = array(
			'title'       => WPTP_Template::truncate( WPTP_Template::plain( (string) $data['title'] ), 120 ),
			'description' => WPTP_Template::plain( (string) $data['description'] ),
		);

		$all                                = self::all( $post->ID );
		$all[ self::config_hash( $config ) ] = array_merge(
			$answer,
			array(
				'modified' => $post->post_modified_gmt,
				'at'       => time(),
				'provider' => $client->provider(),
			)
		);
		// Oldest first out: channels come and go, the meta must not grow forever.
		uasort( $all, function ( $a, $b ) {
			return (int) ( $b['at'] ?? 0 ) - (int) ( $a['at'] ?? 0 );
		} );
		$all = array_slice( $all, 0, self::MAX_ENTRIES, true );

		update_post_meta( $post->ID, self::META, wp_slash( $all ) );
		return $answer;
	}

	private static function user_prompt( WP_Post $post, array $config ) {
		$ctx = array(
			'post'   => $post,
			'config' => $config,
			'llm'    => null,
		);

		$desc_max = max( 150, (int) $config['llm_length'] );

		$facts = array(
			'Заголовок записи'  => get_the_title( $post ),
			'Краткое описание'  => WPTP_Template::resolve_chain( 'seo_description|short_description|excerpt', $ctx ),
			'Рубрики и метки'   => implode( ', ', WPTP_Template::term_names( $post, '*', 12 ) ),
			'Начало текста'     => WPTP_Template::truncate( WPTP_Template::content_text( $post ), 1500 ),
		);
		$facts['Канал'] = $config['channel_title'];

		$lines = array();
		foreach ( $facts as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		$rules = array(
			'Язык: ' . ( $config['llm_language'] ? $config['llm_language'] : 'русский' ) . '.',
			'title — до 90 символов, суть или польза материала.',
			'description — 2–4 предложения, до ' . $desc_max . ' символов: что это, кому полезно, что человек найдёт по ссылке. Абзацы разделяй пустой строкой.',
		);

		$keywords = WPTP_Template::render( (string) $config['llm_keywords'], $ctx );
		if ( '' !== $keywords ) {
			$rules[] = 'Если уместно, используй ключевые слова: ' . $keywords . '.';
		}
		if ( '' !== trim( (string) $config['llm_prompt'] ) ) {
			$rules[] = trim( (string) $config['llm_prompt'] );
		}

		return "Исходные данные:\n" . implode( "\n", $lines ) . "\n\nТребования:\n- " . implode( "\n- ", $rules );
	}

	private static function all( $post_id ) {
		$all = get_post_meta( $post_id, self::META, true );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * A post the model keeps failing on (bad key, refusal, garbage) must not
	 * eat the per-tick budget forever: back off for a few hours.
	 */
	private static function failed_key( $post_id, array $config ) {
		return 'wptp_llm_fail_' . $post_id . '_' . self::config_hash( $config );
	}

	private static function mark_failed( $post_id, array $config ) {
		set_transient( self::failed_key( $post_id, $config ), 1, 6 * HOUR_IN_SECONDS );
	}

	private static function recently_failed( $post_id, array $config ) {
		return (bool) get_transient( self::failed_key( $post_id, $config ) );
	}
}
