<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel settings. The fields table is the single source of truth — the
 * metabox, the save handler and get() all read it, so adding a knob is one
 * line here plus one row of markup. Same approach as WPPF_Feed_Config in
 * wp-pinfeed; the selection, text, LLM, link and picture blocks are the same
 * fields, the destination and schedule blocks are Telegram's own.
 *
 * Every schedule limit is optional: an empty field / 0 switches it off.
 */
class WPTP_Channel_Config {

	const PREFIX = '_wptp_';
	const NONCE  = 'wptp_channel_nonce';

	/** key => array(type, default) */
	public static function fields() {
		return array(
			'enabled'               => array( 'bool', 1 ),

			// ---------------------------------------------------- destination
			'bot_id'                => array( 'text', '' ),
			'chat_id'               => array( 'text', '' ),   // @username or -100…

			// -------------------------------------------------- what goes in
			'post_types'            => array( 'list', array( 'post' ) ),
			// [{taxonomy, terms (slugs, comma-separated), operator IN|NOT IN|AND|EXISTS|NOT EXISTS, children}]
			'tax_rules'             => array( 'rules', array() ),
			'tax_relation'          => array( 'text', 'AND' ),
			'exclude_ids'           => array( 'text', '' ),

			// ---------------------------------------------------------- when
			'on_publish'            => array( 'bool', 1 ),
			'delay_minutes'         => array( 'int', 0 ),
			'rotation_enabled'      => array( 'bool', 0 ),
			'rotation_every_hours'  => array( 'int', 24 ),
			'rotation_order'        => array( 'text', 'oldest' ), // oldest | newest | rand | meta_num
			'rotation_meta_key'     => array( 'text', '' ),
			'rotation_min_age_days' => array( 'int', 0 ),
			'rotation_max_age_days' => array( 'int', 0 ),
			'min_interval_minutes'  => array( 'int', 0 ),
			'quiet_from'            => array( 'text', '' ),   // HH:MM, site time
			'quiet_to'              => array( 'text', '' ),
			'daily_limit'           => array( 'int', 0 ),

			// ------------------------------------------------------- message
			'format'                => array( 'text', 'photo' ), // photo | link_preview | text
			'text_template'         => array( 'textarea', "<b>{tg_title|llm_title|seo_title|title}</b>\n\n{tg_text|llm_description|seo_description|short_description|excerpt|content:600}\n\n{hashtags:post_tag:5}" ),
			'text_max'              => array( 'int', 0 ),     // 0 = Telegram's limit (1024 caption / 4096 text)
			'button_text'           => array( 'text', 'Читать на сайте' ),
			'disable_notification'  => array( 'bool', 0 ),

			// ----------------------------------------------------------- LLM
			'llm_enabled'           => array( 'bool', 0 ),
			'llm_provider'          => array( 'text', 'inherit' ),
			'llm_language'          => array( 'text', 'русский' ),
			'llm_length'            => array( 'int', 500 ),
			'llm_prompt'            => array( 'textarea', '' ),
			'llm_keywords'          => array( 'text', '' ),

			// ---------------------------------------------------------- link
			'utm_enabled'           => array( 'bool', 1 ),
			'utm_source'            => array( 'text', 'telegram' ),
			'utm_medium'            => array( 'text', 'social' ),
			'utm_campaign'          => array( 'text', '{channel_slug}' ),
			'utm_content'           => array( 'text', '{palette}' ),

			// --------------------------------------------------------- image
			// Ordered chain, first hit wins. See WPTP_Image_Resolver::sources().
			'image_sources'         => array( 'list', array( 'gh_pin', 'thumbnail', 'content' ) ),
			'image_meta_key'        => array( 'text', '' ),         // for the "meta" source: attachment ID or URL
			'image_horizontal'      => array( 'text', 'use' ),      // use | skip — wider-than-tall pictures
			'image_missing'         => array( 'text', 'generate' ), // generate | text | skip — no picture found
			'infographic_preset'    => array( 'text', 'ig-list' ),
			'infographic_points'    => array( 'int', 4 ),
			'infographic_title'     => array( 'text', '{tg_title|llm_title|title}' ),
			'footer_template'       => array( 'text', '{site_domain}' ),
		);
	}

	/** Runtime state — written by the dispatcher and the check, never by the form. */
	const LAST_ROTATION = '_wptp_last_rotation_at';
	const CHECK         = '_wptp_check';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . WPTP_CPT::CHANNEL, array( __CLASS__, 'save_channel' ), 10, 2 );
	}

	// ------------------------------------------------------------- reading

	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $key => $spec ) {
			$out[ $key ] = $spec[1];
		}
		return $out;
	}

	public static function raw( $channel_id ) {
		$raw = array();
		foreach ( self::fields() as $key => $spec ) {
			$value = get_post_meta( $channel_id, self::PREFIX . $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$raw[ $key ] = $value;
			}
		}
		return $raw;
	}

	/** Typed, defaulted settings of a `tgchannel` post — everything downstream reads this. */
	public static function get( $channel_id ) {
		$post = get_post( $channel_id );
		if ( ! $post || WPTP_CPT::CHANNEL !== $post->post_type ) {
			return null;
		}

		$config                  = self::normalise( self::raw( $post->ID ) );
		$config['channel_id']    = (int) $post->ID;
		// post_name keeps non-Latin slugs percent-encoded; {channel_slug} is text.
		$config['channel_slug']  = $post->post_name ? urldecode( $post->post_name ) : 'channel-' . $post->ID;
		$config['channel_title'] = get_the_title( $post );
		$config['channel_key']   = 'ch-' . $post->ID;
		$config['published']     = 'publish' === $post->post_status;

		return $config;
	}

	/** Published, enabled channels with a bot and a chat. */
	public static function active() {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'      => WPTP_CPT::CHANNEL,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$config = self::get( $id );
			if ( $config && $config['enabled'] && '' !== $config['chat_id'] && WPTP_Bots::get( $config['bot_id'] ) ) {
				$out[ (int) $id ] = $config;
			}
		}
		return $out;
	}

	/** Casts raw stored values to their declared types, filling in defaults. */
	public static function normalise( array $raw ) {
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			list( $type, $default ) = $spec;

			if ( ! array_key_exists( $key, $raw ) ) {
				$out[ $key ] = $default;
				continue;
			}

			$value = $raw[ $key ];
			switch ( $type ) {
				case 'int':
					$out[ $key ] = max( 0, (int) $value );
					break;
				case 'bool':
					$out[ $key ] = (bool) (int) $value;
					break;
				case 'list':
					$out[ $key ] = self::to_list( $value );
					break;
				case 'rules':
					$out[ $key ] = self::to_rules( $value );
					break;
				default:
					$out[ $key ] = (string) $value;
			}
		}

		// Only sources that still exist (and, for "gh_pin", whose plugin is present).
		$out['image_sources']    = array_values( array_intersect( $out['image_sources'], array_keys( WPTP_Image_Resolver::sources() ) ) );
		$out['image_horizontal'] = 'skip' === $out['image_horizontal'] ? 'skip' : 'use';
		$out['image_missing']    = in_array( $out['image_missing'], array( 'generate', 'text', 'skip' ), true ) ? $out['image_missing'] : 'generate';
		$out['format']           = in_array( $out['format'], array( 'photo', 'link_preview', 'text' ), true ) ? $out['format'] : 'photo';
		$out['rotation_order']   = in_array( $out['rotation_order'], array( 'oldest', 'newest', 'rand', 'meta_num' ), true ) ? $out['rotation_order'] : 'oldest';
		$out['tax_relation']     = 'OR' === strtoupper( $out['tax_relation'] ) ? 'OR' : 'AND';
		$out['quiet_from']       = self::to_time( $out['quiet_from'] );
		$out['quiet_to']         = self::to_time( $out['quiet_to'] );
		if ( empty( $out['post_types'] ) ) {
			$out['post_types'] = array( 'post' );
		}

		return $out;
	}

	private static function to_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$value = array_map( 'trim', array_map( 'strval', (array) $value ) );
		return array_values( array_unique( array_filter( $value, 'strlen' ) ) );
	}

	private static function to_rules( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}

		$out = array();
		foreach ( (array) $value as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['taxonomy'] ) ) {
				continue;
			}
			$operator = strtoupper( (string) ( $rule['operator'] ?? 'IN' ) );
			$out[]    = array(
				'taxonomy' => sanitize_key( $rule['taxonomy'] ),
				'terms'    => (string) ( $rule['terms'] ?? '' ),
				'operator' => in_array( $operator, array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ), true ) ? $operator : 'IN',
				'children' => empty( $rule['children'] ) ? 0 : 1,
			);
		}
		return $out;
	}

	/** "9:5" / "09:05" -> "09:05"; anything else -> "" (off). */
	private static function to_time( $value ) {
		if ( ! preg_match( '/^\s*(\d{1,2})[:.](\d{2})\s*$/', (string) $value, $m ) ) {
			return '';
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		return ( $h < 24 && $i < 60 ) ? sprintf( '%02d:%02d', $h, $i ) : '';
	}

	/**
	 * "@name", "t.me/name", "https://t.me/name" -> "@name"; numeric ids
	 * (-100…) stay as they are. Private invite links (t.me/+…) can't be used
	 * by bots — the numeric id is needed then.
	 */
	public static function normalise_chat_id( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z0-9_]{4,})/?$#i', $value, $m ) ) {
			return '@' . $m[1];
		}
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^@?([A-Za-z0-9_]{4,})$/', $value, $m ) ) {
			return '@' . $m[1];
		}
		return '';
	}

	/**
	 * Hash of everything that changes the text or image of a message, so the
	 * caches (LLM answers, rendered images) can tell a stale entry from a
	 * fresh one without being cleared by hand.
	 */
	public static function hash( array $config, array $keys ) {
		$subset = array();
		foreach ( $keys as $key ) {
			$subset[ $key ] = $config[ $key ] ?? null;
		}
		return substr( md5( wp_json_encode( $subset ) ), 0, 12 );
	}

	// ------------------------------------------------------------- writing

	/** Sanitises posted form values (`$input` = already unslashed $_POST['wptp']) into the stored shape. */
	public static function sanitize_input( array $input ) {
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			list( $type ) = $spec;
			$value        = $input[ $key ] ?? null;

			switch ( $type ) {
				case 'bool':
					$out[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'int':
					$out[ $key ] = max( 0, (int) $value );
					break;
				case 'list':
					$list        = self::to_list( is_array( $value ) ? $value : (string) $value );
					$out[ $key ] = array_map( 'sanitize_key', $list );
					break;
				case 'rules':
					$rules = array();
					foreach ( (array) $value as $rule ) {
						if ( ! is_array( $rule ) || empty( $rule['taxonomy'] ) ) {
							continue;
						}
						$operator = (string) ( $rule['operator'] ?? 'IN' );
						$terms    = sanitize_text_field( (string) ( $rule['terms'] ?? '' ) );
						if ( '' === $terms && ! in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
							continue;
						}
						$rules[] = array(
							'taxonomy' => sanitize_key( $rule['taxonomy'] ),
							'terms'    => $terms,
							'operator' => $operator,
							'children' => empty( $rule['children'] ) ? 0 : 1,
						);
					}
					$out[ $key ] = self::to_rules( $rules );
					break;
				case 'textarea':
					// The message template keeps Telegram's tags — sanitize_textarea_field would strip them.
					$out[ $key ] = 'text_template' === $key
						? trim( str_replace( "\r\n", "\n", wp_check_invalid_utf8( (string) $value ) ) )
						: sanitize_textarea_field( (string) $value );
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$out['chat_id']    = self::normalise_chat_id( $out['chat_id'] );
		$out['quiet_from'] = self::to_time( $out['quiet_from'] );
		$out['quiet_to']   = self::to_time( $out['quiet_to'] );

		// The order of the image chain comes from a separate hidden field the
		// sortable list maintains; checkboxes alone lose it.
		if ( isset( $input['image_sources_order'] ) ) {
			$order   = self::to_list( sanitize_text_field( (string) $input['image_sources_order'] ) );
			$checked = $out['image_sources'];
			$out['image_sources'] = array_values( array_filter( $order, function ( $s ) use ( $checked ) {
				return in_array( $s, $checked, true );
			} ) );
		}

		return $out;
	}

	public static function add_meta_boxes() {
		add_meta_box( 'wptp_channel_status', 'Состояние', array( __CLASS__, 'render_status_box' ), WPTP_CPT::CHANNEL, 'side', 'high' );
		add_meta_box( 'wptp_channel_settings', 'Настройки канала', array( __CLASS__, 'render_settings_box' ), WPTP_CPT::CHANNEL, 'normal', 'high' );
	}

	public static function render_status_box( $post ) {
		$config = self::get( $post->ID );
		if ( ! $config ) {
			return;
		}

		if ( '' === $config['chat_id'] || ! WPTP_Bots::get( $config['bot_id'] ) ) {
			echo '<p>Выберите бота и укажите канал, затем сохраните.</p>';
		} else {
			$check = get_post_meta( $post->ID, self::CHECK, true );
			if ( is_array( $check ) && ! empty( $check['at'] ) ) {
				echo '<p>' . ( $check['ok'] ? '<span class="wptp-ok">Бот может публиковать</span>' : '<span class="wptp-warn">' . esc_html( $check['error'] ) . '</span>' );
				if ( ! empty( $check['title'] ) ) {
					echo '<br>' . esc_html( $check['title'] ) . ( ! empty( $check['username'] ) ? ' · <a href="' . esc_url( 'https://t.me/' . $check['username'] ) . '" target="_blank" rel="noopener">@' . esc_html( $check['username'] ) . '</a>' : '' );
				}
				echo '<br><span class="description">проверено ' . esc_html( wp_date( 'd.m.Y H:i', (int) $check['at'] ) ) . '</span></p>';
			}
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=wptp_check_channel&channel=' . $post->ID ), 'wptp_check_channel_' . $post->ID );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">Проверить доступ бота</a></p>';
		}

		if ( 'publish' !== $post->post_status ) {
			echo '<p class="description">Черновик канала ничего не отправляет — опубликуйте его, когда настройки готовы.</p>';
		} elseif ( ! $config['enabled'] ) {
			echo '<p class="wptp-warn">Канал выключен.</p>';
		}

		echo '<p><strong>Подходит записей:</strong> ' . (int) WPTP_Query::count( $config ) . '<br>';
		if ( $config['rotation_enabled'] ) {
			echo '<strong>Осталось для ротации:</strong> ' . (int) WPTP_Query::count( $config, 'rotation' ) . '<br>';
		}
		echo '<strong>Отправлено:</strong> ' . (int) WPTP_Queue::count( $post->ID, WPTP_Queue::SENT ) . ', за сутки — ' . (int) WPTP_Queue::count( $post->ID, WPTP_Queue::SENT, time() - DAY_IN_SECONDS ) . '<br>';
		echo '<strong>В очереди:</strong> ' . (int) WPTP_Queue::count( $post->ID, WPTP_Queue::PENDING ) . '<br>';
		echo '<strong>С ошибкой:</strong> ' . (int) WPTP_Queue::count( $post->ID, WPTP_Queue::FAILED ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . WPTP_Admin::MAIN_SLUG . '&channel=' . $post->ID ) ) . '">Очередь канала</a></p>';
	}

	public static function render_settings_box( $post ) {
		wp_nonce_field( 'wptp_save_channel', self::NONCE );
		$raw = self::raw( $post->ID );
		if ( 'auto-draft' === $post->post_status && '' !== WPTP_Bots::default_id() ) {
			$raw['bot_id'] = WPTP_Bots::default_id();
		}
		self::render_form( self::normalise( $raw ), 'auto-draft' === $post->post_status );
	}

	public static function save_channel( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'wptp_save_channel' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input  = isset( $_POST['wptp'] ) && is_array( $_POST['wptp'] ) ? wp_unslash( $_POST['wptp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$before = self::raw( $post_id );
		$values = self::sanitize_input( $input );

		$profile = isset( $input['apply_profile'] ) ? sanitize_key( $input['apply_profile'] ) : '';
		if ( '' !== $profile ) {
			$values = array_merge( $values, WPTP_Integrations::profile( $profile ) );
		}

		// update_post_meta() unslashes its value; slash it, or a "\" in a template is lost.
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, self::PREFIX . $key, wp_slash( $value ) );
		}

		// A new destination needs a new check.
		if ( ( $before['chat_id'] ?? '' ) !== $values['chat_id'] || ( $before['bot_id'] ?? '' ) !== $values['bot_id'] ) {
			delete_post_meta( $post_id, self::CHECK );
			if ( '' !== $values['chat_id'] && WPTP_Bots::get( $values['bot_id'] ) ) {
				WPTP_Dispatcher::check_channel( $post_id );
			}
		}
	}

	// ---------------------------------------------------------------- form

	/**
	 * @param array $c      Normalised config.
	 * @param bool  $is_new Show the profile picker prominently.
	 */
	public static function render_form( array $c, $is_new = false ) {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$profiles   = WPTP_Integrations::profiles();
		$bots       = WPTP_Bots::choices();

		echo '<div class="wptp-form">';

		// ---- profile
		if ( $profiles ) {
			echo '<div class="wptp-section wptp-profile' . ( $is_new ? ' is-new' : '' ) . '"><h3>Быстрый старт</h3>';
			echo '<p><select name="wptp[apply_profile]"><option value="">— не применять профиль —</option>';
			foreach ( $profiles as $key => $profile ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $profile['label'] ) . '</option>';
			}
			echo '</select></p><p class="description">Профиль перезапишет типы записей, шаблон текста и цепочку картинок готовыми значениями под плагин сети — при сохранении. Остальное можно поправить после.</p></div>';
		}

		// ---- destination
		echo '<div class="wptp-section"><h3>Куда</h3><table class="form-table">';
		self::row_checkbox( 'enabled', 'Канал включён', $c['enabled'], 'Выключенный канал ничего не отправляет; очередь сохраняется.' );
		echo '<tr><th>Бот</th><td>';
		if ( $bots ) {
			echo '<select name="wptp[bot_id]"><option value="">—</option>';
			foreach ( $bots as $id => $label ) {
				echo '<option value="' . esc_attr( $id ) . '"' . selected( $c['bot_id'], $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="wptp[bot_id]" value=""><span class="wptp-warn">Ботов нет.</span>';
		}
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . WPTP_Admin::SETTINGS_SLUG ) ) . '#bots">Боты</a>';
		echo '<p class="description">Один бот может публиковать в любое число каналов. Его нужно добавить администратором канала с правом «Публикация сообщений».</p></td></tr>';
		self::row_text( 'chat_id', 'Канал', $c['chat_id'], '<code>@username</code> или ссылка <code>t.me/username</code> для публичного канала; для закрытого — числовой ID <code>-100…</code> (кнопка «Найти каналы бота» в настройках покажет его).' );
		echo '</table></div>';

		// ---- what goes in
		echo '<div class="wptp-section"><h3>Какие записи</h3><table class="form-table">';

		echo '<tr><th>Типы записей</th><td>';
		foreach ( $post_types as $type ) {
			$checked = in_array( $type->name, $c['post_types'], true );
			echo '<label class="wptp-inline"><input type="checkbox" name="wptp[post_types][]" value="' . esc_attr( $type->name ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( $type->labels->singular_name ) . ' <code>' . esc_html( $type->name ) . '</code></label>';
		}
		echo '</td></tr>';

		echo '<tr><th>Условия по таксономиям</th><td>';
		echo '<table class="wptp-rules widefat striped"><thead><tr><th>Таксономия</th><th>Условие</th><th>Термины (слаги через запятую)</th><th>С дочерними</th><th></th></tr></thead><tbody>';
		$rules   = $c['tax_rules'];
		$rules[] = array(
			'taxonomy' => '',
			'terms'    => '',
			'operator' => 'IN',
			'children' => 1,
		);
		foreach ( $rules as $i => $rule ) {
			self::rule_row( $i, $rule, $taxonomies );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button wptp-add-rule">+ Условие</button></p>';
		echo '<p><label>Связь условий: <select name="wptp[tax_relation]">';
		foreach ( array( 'AND' => 'И — все условия сразу', 'OR' => 'ИЛИ — любое из условий' ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['tax_relation'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p class="description">«Любой из» = IN, «Ни один из» = NOT IN, «Все сразу» = AND, «Есть любой термин» / «Нет терминов» не требуют списка. Слаги, а не ID — конфиг переносим между сайтами сети.</p>';
		echo '</td></tr>';

		self::row_text( 'exclude_ids', 'Исключить ID записей', $c['exclude_ids'], 'Через запятую. Плюс у любой записи есть флажок «Не публиковать в Telegram».' );
		echo '</table></div>';

		// ---- when
		echo '<div class="wptp-section"><h3>Когда</h3>';
		echo '<p class="wptp-flow">Каждая запись уходит в канал <strong>не больше одного раза</strong> — и при публикации, и при ротации. Все ограничения необязательны: пусто или 0 — без ограничения.</p>';
		echo '<table class="form-table">';
		self::row_checkbox( 'on_publish', 'При публикации записи', $c['on_publish'], 'Новая подходящая запись встаёт в очередь в момент публикации (в том числе отложенной).' );
		self::row_number( 'delay_minutes', 'Задержка после публикации, минут', $c['delay_minutes'], 'Успеют проставиться обложка, SEO-поля и метки. Например, llm-agent-articles прикрепляет обложку уже после публикации — для него нужно 15–30.' );

		self::row_checkbox( 'rotation_enabled', 'Ротация старых записей', $c['rotation_enabled'], 'Раз в N часов в канал уходит одна подходящая запись, которая сюда ещё не отправлялась. Когда такие кончатся, ротация остановится сама.' );
		self::row_number( 'rotation_every_hours', 'Ротация: раз в, часов', $c['rotation_every_hours'], '0 — без своего интервала: запись за каждый проход фоновой задачи (раз в 10 минут), если её не сдерживают интервал и лимит ниже.' );
		echo '<tr><th>Ротация: порядок</th><td><select name="wptp[rotation_order]">';
		foreach ( array(
			'oldest'   => 'Сначала старые',
			'newest'   => 'Сначала новые',
			'rand'     => 'Случайно',
			'meta_num' => 'По числовому мета-полю (по убыванию)',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['rotation_order'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <input type="text" name="wptp[rotation_meta_key]" value="' . esc_attr( $c['rotation_meta_key'] ) . '" placeholder="мета-ключ, напр. _gh_stars" class="regular-text"></td></tr>';
		echo '<tr><th>Ротация: возраст записи, дней</th><td>не моложе <input type="number" min="0" class="small-text" name="wptp[rotation_min_age_days]" value="' . esc_attr( (string) $c['rotation_min_age_days'] ) . '"> не старше <input type="number" min="0" class="small-text" name="wptp[rotation_max_age_days]" value="' . esc_attr( (string) $c['rotation_max_age_days'] ) . '"><p class="description">0 — без ограничения.</p></td></tr>';

		self::row_number( 'min_interval_minutes', 'Не чаще одного поста в, минут', $c['min_interval_minutes'], 'Для всего канала. Если в очереди скопились записи (например, импорт сотни репозиториев), они уйдут по одной с этим интервалом.' );
		echo '<tr><th>Тихие часы</th><td>с <input type="text" class="small-text" name="wptp[quiet_from]" value="' . esc_attr( $c['quiet_from'] ) . '" placeholder="23:00"> до <input type="text" class="small-text" name="wptp[quiet_to]" value="' . esc_attr( $c['quiet_to'] ) . '" placeholder="08:00"><p class="description">Время сайта (' . esc_html( wp_timezone_string() ) . '). В эти часы ничего не отправляется — записи ждут утра.</p></td></tr>';
		self::row_number( 'daily_limit', 'Не больше постов в сутки', $c['daily_limit'], 'Календарные сутки по времени сайта. Не уместившееся уходит на следующий день.' );
		echo '</table></div>';

		// ---- message
		echo '<div class="wptp-section"><h3>Сообщение</h3><table class="form-table">';
		echo '<tr><th>Формат</th><td>';
		foreach ( array(
			'photo'        => 'Картинка + подпись (до 1024 символов)',
			'link_preview' => 'Текст + превью ссылки (до 4096; Telegram строит превью из OG-тегов страницы)',
			'text'         => 'Только текст, без превью (до 4096)',
		) as $v => $l ) {
			echo '<label class="wptp-block"><input type="radio" name="wptp[format]" value="' . esc_attr( $v ) . '"' . checked( $c['format'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '</td></tr>';
		self::row_textarea( 'text_template', 'Текст', $c['text_template'], 'Разметка Telegram разрешена (см. подстановки ниже). Не влезающий в предел текст сокращается по границе слова — у самой длинной подстановки, строка хэштегов остаётся целой.', 7 );
		self::row_number( 'text_max', 'Предел длины, символов', $c['text_max'], '0 — предел Telegram: 1024 для подписи к картинке, 4096 для текста.' );
		self::row_text( 'button_text', 'Кнопка-ссылка под постом', $c['button_text'], 'Пусто — без кнопки (тогда добавьте <code>&lt;a href="{link}"&gt;…&lt;/a&gt;</code> в текст).' );
		self::row_checkbox( 'disable_notification', 'Без звука', $c['disable_notification'], 'Подписчики получат уведомление без звука.' );
		echo '<tr><th>Подстановки</th><td>' . WPTP_Template::help_html() . '</td></tr>'; // phpcs:ignore
		echo '</table></div>';

		// ---- LLM
		echo '<div class="wptp-section"><h3>Анонс от модели (LLM)</h3><table class="form-table">';
		self::row_checkbox( 'llm_enabled', 'Писать анонс моделью', $c['llm_enabled'], 'Модель пишет {llm_title} и {llm_description} по заголовку, описанию и тексту записи — перед отправкой, в фоне. Ответ кэшируется в мете записи. Если модель не ответила, сработает следующий вариант цепочки шаблона.' );
		echo '<tr><th>Провайдер</th><td><select name="wptp[llm_provider]"><option value="inherit"' . selected( $c['llm_provider'], 'inherit', false ) . '>Как в настройках плагина</option>';
		foreach ( WPTP_Settings::llm_providers() as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $c['llm_provider'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( ! WPTP_Settings::llm_ready() ) {
			echo ' <span class="wptp-warn">Ключи провайдера не заполнены — см. «Настройки».</span>';
		}
		echo '</td></tr>';
		self::row_text( 'llm_language', 'Язык', $c['llm_language'] );
		self::row_number( 'llm_length', 'Длина анонса, символов', $c['llm_length'], 'Ориентир для модели. Подпись к картинке — не больше 1024 вместе с заголовком и хэштегами.' );
		self::row_text( 'llm_keywords', 'Ключевые слова', $c['llm_keywords'], 'Через запятую, подстановки работают (например, {primary_keyword}).' );
		self::row_textarea( 'llm_prompt', 'Дополнительные требования', $c['llm_prompt'], 'Например: «без эмодзи», «обращение на ты», «в конце — вопрос к читателю».' );
		echo '</table></div>';

		// ---- link
		echo '<div class="wptp-section"><h3>Ссылка</h3><table class="form-table">';
		self::row_checkbox( 'utm_enabled', 'Добавлять UTM-метки', $c['utm_enabled'] );
		self::row_text( 'utm_source', 'utm_source', $c['utm_source'] );
		self::row_text( 'utm_medium', 'utm_medium', $c['utm_medium'] );
		self::row_text( 'utm_campaign', 'utm_campaign', $c['utm_campaign'], 'Подстановки работают: {channel_slug}, {post_type}.' );
		self::row_text( 'utm_content', 'utm_content', $c['utm_content'], '{palette} — вариант картинки: dark-magma / light-indigo (цвета темы), fixed-&lt;пресет&gt; (свои цвета пресета), image-thumbnail, text (без картинки). Так в Метрике видно, что кликают лучше.' );
		echo '</table></div>';

		// ---- image
		echo '<div class="wptp-section"><h3>Картинка</h3>';
		echo '<p class="wptp-flow">Для формата «Картинка + подпись». Порядок: <strong>1)</strong> источники сверху вниз — берётся первое найденное изображение → <strong>2)</strong> горизонтальное берётся или пропускается → <strong>3)</strong> если изображения нет — инфографика (тезисы пишет модель), текст с превью ссылки или запись пропускается.</p>';
		echo '<table class="form-table">';

		echo '<tr><th>1. Источники изображения</th><td>';
		echo '<ul class="wptp-sortable">';
		$all_sources = WPTP_Image_Resolver::sources();
		$order       = array_merge( $c['image_sources'], array_diff( array_keys( $all_sources ), $c['image_sources'] ) );
		foreach ( $order as $source ) {
			if ( ! isset( $all_sources[ $source ] ) ) {
				continue;
			}
			$checked = in_array( $source, $c['image_sources'], true );
			echo '<li data-source="' . esc_attr( $source ) . '"><span class="dashicons dashicons-menu"></span><label><input type="checkbox" name="wptp[image_sources][]" value="' . esc_attr( $source ) . '"' . checked( $checked, true, false ) . '> <strong>' . esc_html( $all_sources[ $source ]['label'] ) . '</strong><br><span class="description">' . esc_html( $all_sources[ $source ]['help'] ) . '</span></label>';
			if ( 'meta' === $source ) {
				echo '<br><input type="text" class="regular-text code" name="wptp[image_meta_key]" value="' . esc_attr( $c['image_meta_key'] ) . '" placeholder="мета-ключ">';
			}
			echo '</li>';
		}
		echo '</ul><input type="hidden" name="wptp[image_sources_order]" class="wptp-sortable-order" value="' . esc_attr( implode( ',', $order ) ) . '">';
		echo '<p class="description">Перетаскивайте строки, чтобы поменять порядок; снимите флажок, чтобы источник не использовался.</p></td></tr>';

		echo '<tr><th>2. Горизонтальные изображения</th><td>';
		foreach ( array(
			'use'  => 'Брать как есть',
			'skip' => 'Пропускать — искать в следующем источнике, иначе шаг 3',
		) as $v => $l ) {
			echo '<label class="wptp-block"><input type="radio" name="wptp[image_horizontal]" value="' . esc_attr( $v ) . '"' . checked( $c['image_horizontal'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">Горизонтальное — шире, чем высота (например, обложка 1200×800). В Telegram такие выглядят хорошо, поэтому по умолчанию берутся.</p></td></tr>';

		echo '<tr><th>3. Если изображения нет</th><td>';
		foreach ( array(
			'generate' => 'Сгенерировать инфографику',
			'text'     => 'Отправить текст с превью ссылки',
			'skip'     => 'Не отправлять запись',
		) as $v => $l ) {
			echo '<label class="wptp-block"><input type="radio" name="wptp[image_missing]" value="' . esc_attr( $v ) . '"' . checked( $c['image_missing'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">Инфографика: заголовок и нумерованные тезисы. Тезисы — из блока «Telegram» записи, иначе их пишет модель по тексту записи (ключи — в «Настройках»). Если модель недоступна или текста в записи мало — уйдёт текст с превью ссылки.';
		if ( ! WPTP_Settings::llm_ready() ) {
			echo ' <span class="wptp-warn">Ключи модели не заполнены — генерация не сработает.</span>';
		}
		echo '</p></td></tr>';

		echo '<tr><th>Пресет инфографики</th><td><select name="wptp[infographic_preset]">';
		foreach ( WPTP_Infographic_Presets::all() as $id => $preset ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $c['infographic_preset'], $id, false ) . '>' . esc_html( $preset['name'] . ' (' . WPTP_Infographic_Presets::layouts()[ $preset['layout'] ] . ', ' . $preset['width'] . '×' . $preset['height'] . ')' ) . '</option>';
		}
		echo '</select> <a href="' . esc_url( admin_url( 'admin.php?page=' . WPTP_Admin::PRESETS_SLUG ) ) . '">Пресеты</a></td></tr>';
		self::row_number( 'infographic_points', 'Тезисов', $c['infographic_points'], '3–8. На квадрате 1080×1080 хорошо читаются 4.' );
		self::row_text( 'infographic_title', 'Заголовок инфографики', $c['infographic_title'], 'Те же подстановки, что для текста.' );
		self::row_text( 'footer_template', 'Подпись внизу', $c['footer_template'], 'Обычно домен сайта. Пусто — без подписи.' );
		echo '</table></div>';

		echo '</div>';
	}

	private static function rule_row( $i, array $rule, array $taxonomies ) {
		$name = 'wptp[tax_rules][' . (int) $i . ']';
		echo '<tr class="wptp-rule">';
		echo '<td><select name="' . esc_attr( $name ) . '[taxonomy]"><option value="">—</option>';
		foreach ( $taxonomies as $tax ) {
			echo '<option value="' . esc_attr( $tax->name ) . '"' . selected( $rule['taxonomy'], $tax->name, false ) . '>' . esc_html( $tax->labels->singular_name . ' (' . $tax->name . ')' ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><select name="' . esc_attr( $name ) . '[operator]">';
		foreach ( array(
			'IN'         => 'Любой из',
			'NOT IN'     => 'Ни один из',
			'AND'        => 'Все сразу',
			'EXISTS'     => 'Есть любой термин',
			'NOT EXISTS' => 'Нет терминов',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $rule['operator'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="text" class="widefat" name="' . esc_attr( $name ) . '[terms]" value="' . esc_attr( $rule['terms'] ) . '" placeholder="mcp, ai-agent"></td>';
		echo '<td><input type="checkbox" name="' . esc_attr( $name ) . '[children]" value="1"' . checked( ! empty( $rule['children'] ), true, false ) . '></td>';
		echo '<td><button type="button" class="button-link wptp-remove-rule" aria-label="Удалить условие">✕</button></td>';
		echo '</tr>';
	}

	// ------------------------------------------------------- field helpers

	private static function row_text( $key, $label, $value, $help = '', $class = 'regular-text' ) {
		echo '<tr><th><label for="wptp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="wptp-' . esc_attr( $key ) . '" class="' . esc_attr( $class ) . '" name="wptp[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_textarea( $key, $label, $value, $help = '', $rows = 4 ) {
		echo '<tr><th><label for="wptp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="wptp-' . esc_attr( $key ) . '" class="large-text code" rows="' . (int) $rows . '" name="wptp[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_number( $key, $label, $value, $help = '' ) {
		echo '<tr><th><label for="wptp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" min="0" id="wptp-' . esc_attr( $key ) . '" class="small-text" name="wptp[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '">';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row_checkbox( $key, $label, $value, $help = '' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="wptp[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) $value, true, false ) . '> Да</label>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Attachment picker: hidden ID + preview + buttons, wired up in admin.js. */
	public static function media_field( $name, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$thumb         = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		echo '<div class="wptp-media">';
		echo '<input type="hidden" class="wptp-media-id" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $attachment_id ) . '">';
		echo '<img class="wptp-media-preview" src="' . esc_url( (string) $thumb ) . '" alt=""' . ( $thumb ? '' : ' style="display:none"' ) . '>';
		echo '<button type="button" class="button wptp-media-pick">Выбрать</button> ';
		echo '<button type="button" class="button-link wptp-media-clear"' . ( $attachment_id ? '' : ' style="display:none"' ) . '>Убрать</button>';
		echo '</div>';
	}
}
