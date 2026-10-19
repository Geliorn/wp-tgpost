<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post + one channel config -> one Telegram message: format, HTML text,
 * link, picture. The counterpart of WPPF_Pin_Builder in wp-pinfeed.
 *
 * Generated infographics are plain files in uploads/wptp-images/<post_id>/,
 * named <channel_key>-<hash>.<ext>, where the hash covers everything that
 * changes the picture (preset, title, points, footer, background). Not
 * attachments on purpose: generated pictures don't belong in the media library.
 *
 * The text must fit Telegram's limit (1024 for a caption, 4096 for a text
 * message, or the channel's own). Cutting the rendered HTML would break tags,
 * so the template is re-rendered with every placeholder value capped at N
 * characters, N lowered until it fits: the longest value (the excerpt) gets
 * shorter, short ones (title, hashtags) stay whole.
 */
class WPTP_Message_Builder {

	/**
	 * Part of every picture's file hash. Bump it when the drawing code changes
	 * in a way that should replace already rendered pictures.
	 */
	const RENDER_VERSION = 1;

	const CAPTION_LIMIT = 1024;
	const TEXT_LIMIT    = 4096;

	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'delete_post_files' ) );
	}

	public static function base_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'wptp-images/';
	}

	public static function base_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . 'wptp-images/';
	}

	/**
	 * @param WP_Post $post
	 * @param array   $config
	 * @param bool    $allow_render Render a missing infographic now.
	 * @return array{status:string,format:string,text:string,link:string,image:array|null,variant:string,rendered:bool,notes:array}
	 *         status: ready | pending (points or picture not ready yet — try
	 *         again on the next tick) | skip (the channel says don't send).
	 */
	public static function build( WP_Post $post, array $config, $allow_render = true ) {
		$ctx = array(
			'post'   => $post,
			'config' => $config,
			'llm'    => WPTP_LLM_Text::cached( $post, $config ),
		);

		$message = array(
			'status'   => 'ready',
			'format'   => $config['format'],
			'text'     => '',
			'link'     => '',
			'image'    => null,
			'variant'  => 'text',
			'rendered' => false,
			'notes'    => array(),
		);

		if ( 'photo' === $config['format'] ) {
			$image               = self::image( $post, $config, $ctx, $allow_render );
			$message['notes']    = $image['notes'];
			$message['rendered'] = ! empty( $image['rendered'] );

			if ( 'ready' === $image['state'] ) {
				$message['image']   = $image['image'];
				$message['variant'] = $image['palette'];
			} elseif ( 'pending' === $image['state'] ) {
				$message['status'] = 'pending';
			} elseif ( 'skip' === $config['image_missing'] ) {
				$message['status']  = 'skip';
				$message['notes'][] = 'картинки нет, канал настроен не отправлять такие записи';
			} else {
				// No picture and none to be had: the text with a link preview
				// carries the page's own OG picture.
				$message['format']  = 'link_preview';
				$message['notes'][] = 'картинки нет — уйдёт текст с превью ссылки';
			}
		}

		// After the image: {palette} names the picture that actually went out.
		$ctx['palette']  = $message['variant'];
		$message['link'] = self::link( $post, $config, $ctx );
		$ctx['link']     = $message['link'];

		$limit = 'photo' === $message['format'] && $message['image'] ? self::CAPTION_LIMIT : self::TEXT_LIMIT;
		if ( $config['text_max'] > 0 ) {
			$limit = min( $limit, (int) $config['text_max'] );
		}
		$message['text'] = self::fit_text( $config['text_template'], $ctx, $limit );

		if ( '' === trim( wp_strip_all_tags( $message['text'] ) ) && 'photo' !== $message['format'] ) {
			$message['text'] = '<b>' . WPTP_Template::escape( get_the_title( $post ) ) . '</b>';
		}

		/**
		 * Last word on one message.
		 *
		 * @param array   $message
		 * @param WP_Post $post
		 * @param array   $config
		 */
		return (array) apply_filters( 'wptp_message', $message, $post, $config );
	}

	// ---------------------------------------------------------------- text

	/**
	 * Renders the template so that its visible length fits $limit. First only
	 * the longest placeholder (the excerpt, as a rule) is shortened; if that
	 * isn't enough, every value is capped — at the largest length that fits.
	 */
	public static function fit_text( $template, array $ctx, $limit ) {
		$html = WPTP_Template::render_html( $template, $ctx );
		if ( WPTP_Template::visible_length( $html ) <= $limit ) {
			return $html;
		}

		$longest = WPTP_Template::longest_placeholder( $template, $ctx );
		foreach ( $longest >= 0 ? array( $longest, -1 ) : array( -1 ) as $only ) {
			$best = self::largest_fit( $template, $ctx, $limit, $only );
			if ( '' !== $best ) {
				return $best;
			}
		}

		// The template's own text is longer than the limit — plain text, cut.
		$plain = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return WPTP_Template::escape( WPTP_Template::truncate( $plain, $limit ) );
	}

	/** Binary search for the largest value cap that fits; '' if none does. */
	private static function largest_fit( $template, array $ctx, $limit, $only ) {
		$low  = 20;
		$high = $limit;
		$best = '';
		while ( $low <= $high ) {
			$clip = (int) floor( ( $low + $high ) / 2 );
			$try  = WPTP_Template::render_html( $template, $ctx, $clip, $only );
			if ( WPTP_Template::visible_length( $try ) <= $limit ) {
				$best = $try;
				$low  = $clip + 1;
			} else {
				$high = $clip - 1;
			}
		}
		return $best;
	}

	private static function link( WP_Post $post, array $config, array $ctx ) {
		$url = get_permalink( $post );
		if ( ! $config['utm_enabled'] ) {
			return $url;
		}

		$params = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content' ) as $key ) {
			$value = trim( preg_replace( '/\s+/u', ' ', WPTP_Template::render( (string) $config[ $key ], $ctx ) ) );
			if ( '' !== $value ) {
				$params[ $key ] = rawurlencode( $value );
			}
		}
		return $params ? add_query_arg( $params, $url ) : $url;
	}

	// --------------------------------------------------------------- image

	/**
	 * @return array{state:string,image:array|null,notes:array,palette:string}
	 *         state: ready | pending (the model or the render hasn't finished
	 *         yet) | none (no picture and nothing to generate from)
	 */
	private static function image( WP_Post $post, array $config, array $ctx, $allow_render ) {
		$notes  = array();
		$pick   = WPTP_Image_Resolver::find( $post, $config );
		$labels = WPTP_Image_Resolver::sources();

		foreach ( $pick['skipped'] as $skip ) {
			$notes[] = 'пропущено горизонтальное: ' . ( $labels[ $skip['source'] ]['label'] ?? $skip['source'] );
		}

		if ( $pick['found'] ) {
			$found   = $pick['found'];
			$notes[] = 'изображение: ' . ( $labels[ $found['source'] ]['label'] ?? $found['source'] );
			return array( 'state' => 'ready', 'image' => self::describe( $found ), 'notes' => $notes, 'palette' => self::found_label( $post, $found ) );
		}

		if ( 'generate' !== $config['image_missing'] ) {
			$notes[] = 'изображения нет, генерация выключена';
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes, 'palette' => '' );
		}

		if ( ! WPTP_Image_Composer::text_available() ) {
			$notes[] = 'GD с FreeType недоступен — инфографику не собрать';
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes, 'palette' => '' );
		}

		$ig = WPTP_Infographic::points_for( $post, $config );
		if ( 'pending' === $ig['state'] ) {
			$notes[] = 'тезисы для инфографики ещё пишет модель';
			return array( 'state' => 'pending', 'image' => null, 'notes' => $notes, 'palette' => '' );
		}
		if ( 'none' === $ig['state'] ) {
			$notes[] = 'инфографики не будет: ' . $ig['reason'];
			return array( 'state' => 'none', 'image' => null, 'notes' => $notes, 'palette' => '' );
		}

		// A skipped horizontal picture can still be the infographic's background.
		$background = $pick['skipped'] ? $pick['skipped'][0] : null;
		$plan       = self::render_plan( $post, $config, $ctx, $background, $ig['points'] );
		$source     = 'manual' === $ig['source'] ? 'тезисы из блока «Telegram»' : 'тезисы от модели';

		if ( '' !== $plan['palette'] ) {
			$notes[] = 'цвета темы: ' . WPTP_Theme_Palette::get( $plan['palette'] )['title'];
		}
		if ( is_readable( $plan['path'] ) ) {
			$notes[] = 'инфографика (' . $source . ')';
			return array( 'state' => 'ready', 'image' => self::describe_file( $plan ), 'notes' => $notes, 'palette' => $plan['utm'] );
		}

		if ( ! $allow_render ) {
			$notes[] = 'инфографика ещё не собрана';
			return array( 'state' => 'pending', 'image' => null, 'notes' => $notes, 'palette' => '' );
		}

		$result = self::render( $plan, $background );
		if ( $result['ok'] ) {
			if ( '' !== $result['error'] ) {
				$notes[] = $result['error'];
			}
			$notes[] = 'инфографика собрана (' . $source . ')';
			return array( 'state' => 'ready', 'image' => self::describe_file( $plan ), 'notes' => $notes, 'rendered' => true, 'palette' => $plan['utm'] );
		}
		$notes[] = 'ошибка сборки инфографики: ' . $result['error'];
		WPTP_Log::error( 'Инфографика для записи #' . $post->ID . ' (' . $config['channel_key'] . '): ' . $result['error'] );
		return array( 'state' => 'none', 'image' => null, 'notes' => $notes, 'palette' => '' );
	}

	/**
	 * Where the generated infographic lives and what goes into it.
	 *
	 * @param array|null $background A skipped horizontal picture, for background_mode=source.
	 * @param array      $points     Infographic points.
	 */
	public static function render_plan( WP_Post $post, array $config, array $ctx, $background, array $points ) {
		list( $preset, $palette ) = self::colourise( WPTP_Infographic_Presets::get( $config['infographic_preset'] ), $post->ID );
		$title        = trim( preg_replace( '/\s+/u', ' ', WPTP_Template::render( $config['infographic_title'], $ctx ) ) );
		$footer       = trim( preg_replace( '/\s+/u', ' ', WPTP_Template::render( $config['footer_template'], $ctx ) ) );
		$needs_source = $background && 'source' === $preset['background_mode'];

		$hash = substr(
			md5(
				wp_json_encode(
					array(
						self::RENDER_VERSION,
						$preset,
						$title,
						$footer,
						$points,
						$needs_source ? array( $background['attachment_id'], $background['url'] ) : null,
						$needs_source && $background['attachment_id'] ? (string) get_post_modified_time( 'U', true, $background['attachment_id'] ) : '',
					)
				)
			),
			0,
			12
		);

		$ext  = WPTP_Image_Composer::extension_for( $preset['format'] );
		$name = sanitize_file_name( $config['channel_key'] ) . '-' . $hash . '.' . $ext;

		return array(
			'post_id'      => (int) $post->ID,
			'channel_key'  => $config['channel_key'],
			'preset'       => $preset,
			'title'        => $title,
			'footer'       => $footer,
			'points'       => $points,
			'needs_source' => $needs_source,
			'palette'      => $palette,
			'utm'          => '' !== $palette ? WPTP_Theme_Palette::utm_label( $palette ) : 'fixed-' . $config['infographic_preset'],
			'path'         => self::base_dir() . $post->ID . '/' . $name,
			'url'          => self::base_url() . $post->ID . '/' . $name,
		);
	}

	/**
	 * Theme colours for a preset that asks for them. A rotating preset gives
	 * each post the next palette once and keeps it in post meta, so the same
	 * post renders the same picture every time.
	 *
	 * @param bool $store false for previews: neither move the cursor nor save.
	 * @return array{0:array,1:string} preset, palette slug ('' = own colours)
	 */
	public static function colourise( array $preset, $post_id, $store = true ) {
		$source = $preset['palette_source'] ?? 'preset';
		$stored = $post_id ? (string) get_post_meta( $post_id, WPTP_Post_Meta::PALETTE, true ) : '';
		$slug   = WPTP_Theme_Palette::choose( $source, $stored, false, $store );
		$entry  = '' !== $slug ? WPTP_Theme_Palette::get( $slug ) : null;
		if ( ! $entry ) {
			return array( $preset, '' );
		}
		if ( $store && $post_id && 'rotate' === $source && $slug !== $stored ) {
			update_post_meta( $post_id, WPTP_Post_Meta::PALETTE, $slug );
		}
		return array( WPTP_Theme_Palette::apply( $preset, $entry, $preset['palette_layout'] ?? 'accent' ), $slug );
	}

	/** utm_content label of a picture the post already has. */
	private static function found_label( WP_Post $post, array $found ) {
		if ( 'gh_pin' === $found['source'] ) {
			// llm-agent-github-repos: dark-magma / fixed-dark.
			$label = (string) get_post_meta( $post->ID, '_gh_pin_utm', true );
			if ( '' !== $label ) {
				return $label;
			}
		}
		if ( ! empty( $found['attachment_id'] ) ) {
			// llm-agent-articles marks its infographics the same way.
			$label = (string) get_post_meta( (int) $found['attachment_id'], '_laa_utm', true );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return 'image-' . $found['source'];
	}

	public static function render( array $plan, $background ) {
		// No background picture is no reason to fail: the solid colour stays.
		$source = $plan['needs_source'] ? WPTP_Image_Resolver::local_path( $background ) : '';
		$fit    = WPTP_Infographic::render_fitting( $plan['preset'], $plan['points'], $plan['title'], $plan['footer'], $source ? $source : null, $plan['path'] );
		if ( $fit['ok'] ) {
			self::delete_stale( $plan );
		}
		return array(
			'ok'    => $fit['ok'],
			'error' => $fit['ok'] ? implode( '; ', $fit['notes'] ) : $fit['error'],
		);
	}

	/** Older renders of the same post for the same channel. */
	private static function delete_stale( array $plan ) {
		$prefix = sanitize_file_name( $plan['channel_key'] ) . '-';
		foreach ( (array) glob( dirname( $plan['path'] ) . '/' . $prefix . '*' ) as $file ) {
			if ( $file && $file !== $plan['path'] && 0 === strpos( basename( $file ), $prefix ) ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
	}

	public static function delete_post_files( $post_id ) {
		$dir = self::base_dir() . (int) $post_id;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	private static function describe( array $found ) {
		// The same file as the "full" URL (the "-scaled" copy of a huge upload).
		$path = $found['attachment_id'] ? (string) get_attached_file( $found['attachment_id'] ) : '';
		return array(
			'url'    => $found['url'],
			'path'   => $path && is_readable( $path ) ? $path : '',
			'width'  => (int) $found['width'],
			'height' => (int) $found['height'],
			'source' => $found['source'] ?? '',
		);
	}

	private static function describe_file( array $plan ) {
		return array(
			'url'    => $plan['url'],
			'path'   => $plan['path'],
			'width'  => (int) $plan['preset']['width'],
			'height' => (int) $plan['preset']['height'],
			'source' => 'generated',
		);
	}
}
