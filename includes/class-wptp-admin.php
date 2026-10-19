<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: overview with the queue, image presets (with a live
 * preview — same screen as in wp-pinfeed), settings (bots, connection, LLM,
 * images, background task) and the log. The channels themselves are the
 * `tgchannel` post type, listed under the same menu. All writes go through
 * admin-post.php with a nonce and manage_options.
 */
class WPTP_Admin {

	const MAIN_SLUG     = 'wptp-main';
	const PRESETS_SLUG  = 'wptp-presets';
	const SETTINGS_SLUG = 'wptp-settings';
	const LOG_SLUG      = 'wptp-log';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		foreach ( array( 'save_preset', 'delete_preset', 'import_presets', 'export_presets', 'import_pinfeed_presets', 'save_settings', 'save_bot', 'check_bot', 'delete_bot', 'discover', 'ping', 'check_channel', 'queue_action', 'import_llm', 'test_llm', 'regen_secret', 'run_tick', 'clear_log', 'upload_font' ) as $action ) {
			add_action( 'admin_post_wptp_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'wp_ajax_wptp_preview_preset', array( __CLASS__, 'ajax_preview_preset' ) );

		add_filter( 'manage_' . WPTP_CPT::CHANNEL . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . WPTP_CPT::CHANNEL . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page( 'Telegram-каналы', 'Telegram', 'manage_options', self::MAIN_SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-megaphone', 58 );
		add_submenu_page( self::MAIN_SLUG, 'Обзор и очередь', 'Обзор', 'manage_options', self::MAIN_SLUG, array( __CLASS__, 'page_overview' ) );
		// The `tgchannel` list and "add new" are attached by show_in_menu.
		add_submenu_page( self::MAIN_SLUG, 'Пресеты картинок', 'Пресеты картинок', 'manage_options', self::PRESETS_SLUG, array( __CLASS__, 'page_presets' ) );
		add_submenu_page( self::MAIN_SLUG, 'Настройки', 'Настройки', 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::MAIN_SLUG, 'Журнал', 'Журнал', 'manage_options', self::LOG_SLUG, array( __CLASS__, 'page_log' ) );
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$ours    = false !== strpos( (string) $hook, 'wptp-' ) || ( $screen && WPTP_CPT::CHANNEL === $screen->post_type );
		$editing = $screen && 'post' === $screen->base;
		if ( ! $ours && ! $editing ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'wptp-admin', WPTP_PLUGIN_URL . 'assets/css/admin.css', array(), WPTP_VERSION );
		wp_enqueue_script( 'wptp-admin', WPTP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), WPTP_VERSION, true );
		wp_localize_script(
			'wptp-admin',
			'wptpAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( 'wptp_preview_preset' ),
			)
		);
	}

	// -------------------------------------------------------- channel list

	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['wptp_chat']  = 'Канал';
				$out['wptp_when']  = 'Когда';
				$out['wptp_stats'] = 'Отправлено / в очереди';
			}
		}
		unset( $out['date'] );
		return $out;
	}

	public static function column( $column, $post_id ) {
		$config = WPTP_Channel_Config::get( $post_id );
		if ( ! $config ) {
			return;
		}

		switch ( $column ) {
			case 'wptp_chat':
				self::chat_cell( $config );
				break;
			case 'wptp_when':
				echo esc_html( self::when_text( $config ) );
				break;
			case 'wptp_stats':
				echo (int) WPTP_Queue::count( $post_id, WPTP_Queue::SENT ) . ' / ' . (int) WPTP_Queue::count( $post_id, WPTP_Queue::PENDING );
				$failed = WPTP_Queue::count( $post_id, WPTP_Queue::FAILED );
				if ( $failed ) {
					echo ' <span class="wptp-warn">ошибок: ' . (int) $failed . '</span>';
				}
				break;
		}
	}

	private static function chat_cell( array $config ) {
		$bot = WPTP_Bots::get( $config['bot_id'] );
		echo '<code>' . esc_html( '' !== $config['chat_id'] ? $config['chat_id'] : '—' ) . '</code>';
		echo '<br><small>' . esc_html( $bot ? WPTP_Bots::label( $bot ) : 'бот не выбран' ) . '</small>';
		$check = get_post_meta( $config['channel_id'], WPTP_Channel_Config::CHECK, true );
		if ( is_array( $check ) && ! empty( $check['at'] ) && ! $check['ok'] ) {
			echo '<br><span class="wptp-warn">' . esc_html( $check['error'] ) . '</span>';
		}
		if ( ! $config['published'] ) {
			echo '<br><em>черновик</em>';
		} elseif ( ! $config['enabled'] ) {
			echo '<br><span class="wptp-warn">выключен</span>';
		}
	}

	private static function when_text( array $config ) {
		$parts = array();
		if ( $config['on_publish'] ) {
			$parts[] = 'при публикации' . ( $config['delay_minutes'] ? ' +' . $config['delay_minutes'] . ' мин' : '' );
		}
		if ( $config['rotation_enabled'] ) {
			$parts[] = 'ротация' . ( $config['rotation_every_hours'] ? ' раз в ' . $config['rotation_every_hours'] . ' ч' : '' );
		}
		if ( $config['min_interval_minutes'] ) {
			$parts[] = 'не чаще 1 в ' . $config['min_interval_minutes'] . ' мин';
		}
		if ( '' !== $config['quiet_from'] && '' !== $config['quiet_to'] ) {
			$parts[] = 'тишина ' . $config['quiet_from'] . '–' . $config['quiet_to'];
		}
		if ( $config['daily_limit'] ) {
			$parts[] = 'до ' . $config['daily_limit'] . ' в сутки';
		}
		return $parts ? implode( ', ', $parts ) : 'ничего не включено';
	}

	// -------------------------------------------------------------- pages

	private static function notice() {
		$messages = array(
			'saved'        => 'Сохранено.',
			'deleted'      => 'Удалено.',
			'imported'     => 'Импортировано.',
			'nothing'      => 'Нечего импортировать.',
			'secret'       => 'Новый секрет сгенерирован — обновите команды во внешнем кроне.',
			'tick'         => 'Тик выполнен: ',
			'cleared'      => 'Журнал очищен.',
			'font'         => 'Шрифт загружен.',
			'font_error'   => 'Шрифт не загружен: нужен файл .ttf или .otf.',
			'llm_ok'       => 'Модель ответила: ',
			'llm_error'    => 'Ошибка LLM: ',
			'import_error' => 'Не удалось прочитать JSON с пресетами.',
			'bot_ok'       => 'Бот работает.',
			'bot_error'    => 'Бот: ',
			'discovered'   => 'Список чатов бота — ниже.',
			'ping_ok'      => 'Telegram отвечает: ',
			'ping_error'   => 'Нет связи с Telegram: ',
			'sent'         => 'Готово: ',
			'send_error'   => 'Не отправлено: ',
		);
		$key = isset( $_GET['wptp_msg'] ) ? sanitize_key( $_GET['wptp_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		$extra = isset( $_GET['wptp_extra'] ) ? sanitize_text_field( wp_unslash( $_GET['wptp_extra'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$class = in_array( $key, array( 'llm_error', 'font_error', 'import_error', 'nothing', 'bot_error', 'ping_error', 'send_error' ), true ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $key ] . $extra ) . '</p></div>';
	}

	private static function redirect( $page, $msg, array $extra = array(), $anchor = '' ) {
		$args = array_merge(
			array(
				'page'     => $page,
				'wptp_msg' => $msg,
			),
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав' );
		}
		check_admin_referer( $action );
	}

	public static function page_overview() {
		echo '<div class="wrap wptp-wrap"><h1>Telegram</h1>';
		self::notice();

		if ( ! WPTP_Bots::all() ) {
			echo '<div class="notice notice-info inline"><p>Сначала добавьте бота: создайте его в <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, вставьте токен в <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '#bots">Настройках</a> и добавьте бота администратором в каналы.</p></div>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . WPTP_CPT::CHANNEL ) ) . '">Добавить канал</a> <a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . WPTP_CPT::CHANNEL ) ) . '">Все каналы</a></p>';

		$channels = get_posts(
			array(
				'post_type'      => WPTP_CPT::CHANNEL,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<h2>Каналы</h2>';
		if ( ! $channels ) {
			echo '<p>Каналов пока нет.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Канал</th><th>Куда</th><th>Когда</th><th>Отправлено (сутки / всего)</th><th>В очереди</th><th>Ротация: осталось</th><th>Сейчас</th></tr></thead><tbody>';
			foreach ( $channels as $channel ) {
				$config = WPTP_Channel_Config::get( $channel->ID );
				$gate   = WPTP_Dispatcher::gate( $config );
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $channel->ID ) ) . '"><strong>' . esc_html( get_the_title( $channel ) ) . '</strong></a></td><td>';
				self::chat_cell( $config );
				echo '</td><td>' . esc_html( self::when_text( $config ) ) . '</td>';
				echo '<td>' . (int) WPTP_Queue::count( $channel->ID, WPTP_Queue::SENT, time() - DAY_IN_SECONDS ) . ' / ' . (int) WPTP_Queue::count( $channel->ID, WPTP_Queue::SENT ) . '</td>';
				echo '<td><a href="' . esc_url( add_query_arg( array( 'page' => self::MAIN_SLUG, 'channel' => $channel->ID ), admin_url( 'admin.php' ) ) ) . '">' . (int) WPTP_Queue::count( $channel->ID, WPTP_Queue::PENDING ) . '</a></td>';
				echo '<td>' . ( $config['rotation_enabled'] ? (int) WPTP_Query::count( $config, 'rotation' ) : '—' ) . '</td>';
				echo '<td>' . ( '' !== $gate ? esc_html( $gate ) : 'может отправлять' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		self::render_queue();

		$tick = get_option( WPTP_Scheduler::LAST_TICK_OPTION );
		echo '<h2>Фоновая задача</h2><p>';
		echo is_array( $tick ) ? 'Последний тик: ' . esc_html( wp_date( 'd.m.Y H:i', (int) $tick['time'] ) . ' — ' . $tick['summary'] ) : 'Ещё не запускалась.';
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_run_tick' );
		echo '<input type="hidden" name="action" value="wptp_run_tick"><button class="button">Запустить сейчас</button></form>';

		if ( ! WPTP_Image_Composer::text_available() ) {
			echo '<div class="notice notice-warning inline"><p>GD с FreeType недоступен — инфографика не будет генерироваться; записи без картинки уйдут текстом с превью ссылки.</p></div>';
		}
		echo '</div>';
	}

	private static function render_queue() {
		$channel_id = isset( $_GET['channel'] ) ? absint( $_GET['channel'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$status     = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$statuses   = WPTP_Queue::statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			$status = '';
		}

		echo '<h2 id="queue">Очередь и история' . ( $channel_id ? ': ' . esc_html( get_the_title( $channel_id ) ) : '' ) . '</h2>';
		echo '<p class="wptp-tabs">';
		$base = array( 'page' => self::MAIN_SLUG, 'channel' => $channel_id ? $channel_id : null );
		echo '<a class="button' . ( '' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( $base, admin_url( 'admin.php' ) ) . '#queue' ) . '">Все</a> ';
		foreach ( $statuses as $key => $label ) {
			echo '<a class="button' . ( $key === $status ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( array_merge( $base, array( 'status' => $key ) ), admin_url( 'admin.php' ) ) . '#queue' ) . '">' . esc_html( $label ) . '</a> ';
		}
		if ( $channel_id ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MAIN_SLUG ) . '#queue' ) . '">все каналы</a>';
		}
		echo '</p>';

		$rows = WPTP_Queue::recent( 100, $channel_id, $status );
		if ( ! $rows ) {
			echo '<p>Пусто.</p>';
			return;
		}

		$kinds  = WPTP_Queue::kinds();
		$titles = array();
		echo '<table class="widefat striped wptp-queue"><thead><tr><th>Запись</th><th>Канал</th><th>Как</th><th>Статус</th><th>Время</th><th>Подробности</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$cid = (int) $row->channel_id;
			if ( ! isset( $titles[ $cid ] ) ) {
				$titles[ $cid ] = get_the_title( $cid );
			}
			$time = WPTP_Queue::SENT === $row->status ? (int) $row->sent_at : max( (int) $row->scheduled_at, (int) $row->next_try_at );
			echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( (int) $row->post_id ) ) . '">' . esc_html( get_the_title( (int) $row->post_id ) ) . '</a> <small>#' . (int) $row->post_id . '</small></td>';
			echo '<td>' . esc_html( $titles[ $cid ] ) . '</td>';
			echo '<td>' . esc_html( $kinds[ $row->kind ] ?? $row->kind ) . '</td>';
			echo '<td class="wptp-st-' . esc_attr( $row->status ) . '">' . esc_html( $statuses[ $row->status ] ?? $row->status ) . ( (int) $row->attempts ? ' <small>(попыток: ' . (int) $row->attempts . ')</small>' : '' ) . '</td>';
			echo '<td>' . ( $time ? esc_html( wp_date( 'd.m.Y H:i', $time ) ) : '—' ) . '</td>';
			echo '<td>';
			if ( $row->message_url ) {
				echo '<a href="' . esc_url( $row->message_url ) . '" target="_blank" rel="noopener">пост в Telegram</a>' . ( $row->variant ? ' · <small>' . esc_html( $row->variant ) . '</small>' : '' );
			}
			if ( $row->error ) {
				echo '<small>' . esc_html( (string) $row->error ) . '</small>';
			}
			echo '</td><td>';
			$actions = array();
			if ( in_array( $row->status, array( WPTP_Queue::PENDING, WPTP_Queue::FAILED ), true ) ) {
				$actions['send'] = 'Отправить сейчас';
			}
			if ( WPTP_Queue::PENDING === $row->status ) {
				$actions['skip'] = 'Пропустить';
			}
			if ( WPTP_Queue::SENT !== $row->status ) {
				$actions['delete'] = 'Убрать из очереди';
			}
			foreach ( $actions as $action => $label ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=wptp_queue_action&do=' . $action . '&row=' . (int) $row->id ), 'wptp_queue_' . (int) $row->id );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a><br>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">«Убрать из очереди» удаляет строку целиком: запись снова сможет попасть в канал (при ротации). «Пропустить» — запись в этот канал больше не пойдёт.</p>';
	}

	public static function handle_queue_action() {
		$id = isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав' );
		}
		check_admin_referer( 'wptp_queue_' . $id );

		$row = WPTP_Queue::get( $id );
		$do  = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$msg = 'saved';
		$extra = array();

		if ( $row ) {
			switch ( $do ) {
				case 'send':
					$result = WPTP_Dispatcher::send_now( (int) $row->post_id, (int) $row->channel_id );
					$msg    = $result['ok'] ? 'sent' : 'send_error';
					$extra  = array( 'wptp_extra' => rawurlencode( $result['message'] ) );
					break;
				case 'skip':
					WPTP_Queue::mark_skipped( $id, 'пропущено вручную' );
					break;
				case 'delete':
					if ( WPTP_Queue::SENT !== $row->status ) {
						WPTP_Queue::delete( $id );
					}
					break;
			}
		}

		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( array_merge( array( 'wptp_msg' => $msg ), $extra ), $back ? remove_query_arg( array( 'wptp_msg', 'wptp_extra' ), $back ) : admin_url( 'admin.php?page=' . self::MAIN_SLUG ) ) );
		exit;
	}

	public static function handle_check_channel() {
		$id = isset( $_GET['channel'] ) ? absint( $_GET['channel'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав' );
		}
		check_admin_referer( 'wptp_check_channel_' . $id );
		WPTP_Dispatcher::check_channel( $id );
		wp_safe_redirect( get_edit_post_link( $id, 'url' ) );
		exit;
	}

	// ------------------------------------------------------------- presets

	private static function preset_groups() {
		return array(
			'canvas'  => 'Полотно и фон',
			'heading' => 'Заголовок',
			'items'   => 'Тезисы: раскладка и блоки',
			'text'    => 'Тезисы: маркер и текст',
			'footer'  => 'Подпись внизу и логотип',
		);
	}

	private static function preset_labels() {
		return array(
			'name'                => array( 'Название', 'canvas' ),
			'palette_source'      => array( 'Цвета', 'canvas' ),
			'palette_layout'      => array( 'Цвета темы: раскладка', 'canvas' ),
			'width'               => array( 'Ширина, px', 'canvas' ),
			'height'              => array( 'Высота, px', 'canvas' ),
			'padding'             => array( 'Поля, px', 'canvas' ),
			'background'          => array( 'Цвет фона', 'canvas' ),
			'background_mode'     => array( 'Фон', 'canvas' ),
			'fit'                 => array( 'Вписывание картинки фона', 'canvas' ),
			'focal'               => array( 'Фокус обрезки', 'canvas' ),
			'grayscale'           => array( 'Фон ч/б', 'canvas' ),
			'brightness'          => array( 'Яркость фона (−255…255)', 'canvas' ),
			'blur'                => array( 'Размытие фона, проходов', 'canvas' ),
			'tint_hex'            => array( 'Затемнение: цвет', 'canvas' ),
			'tint_alpha'          => array( 'Затемнение: непрозрачность, %', 'canvas' ),
			'gradient_enabled'    => array( 'Градиент', 'canvas' ),
			'gradient_direction'  => array( 'Направление градиента', 'canvas' ),
			'gradient_from_hex'   => array( 'Градиент: цвет в начале', 'canvas' ),
			'gradient_from_alpha' => array( 'Градиент: непрозрачность в начале, %', 'canvas' ),
			'gradient_to_hex'     => array( 'Градиент: цвет в конце', 'canvas' ),
			'gradient_to_alpha'   => array( 'Градиент: непрозрачность в конце, %', 'canvas' ),
			'font'                => array( 'Шрифт', 'canvas' ),
			'format'              => array( 'Формат файла', 'canvas' ),
			'quality'             => array( 'Качество', 'canvas' ),
			'accent_enabled'      => array( 'Акцентная полоса', 'heading' ),
			'accent_color'        => array( 'Цвет полосы', 'heading' ),
			'accent_width'        => array( 'Длина полосы', 'heading' ),
			'accent_height'       => array( 'Толщина полосы', 'heading' ),
			'accent_gap'          => array( 'Отступ под полосой', 'heading' ),
			'title_enabled'       => array( 'Заголовок', 'heading' ),
			'title_size'          => array( 'Кегль', 'heading' ),
			'title_min_size'      => array( 'Минимальный кегль', 'heading' ),
			'title_color'         => array( 'Цвет', 'heading' ),
			'title_align'         => array( 'Выравнивание', 'heading' ),
			'title_max_lines'     => array( 'Максимум строк', 'heading' ),
			'title_line_height'   => array( 'Интерлиньяж', 'heading' ),
			'title_gap'           => array( 'Отступ под заголовком', 'heading' ),
			'layout'              => array( 'Раскладка', 'items' ),
			'columns'             => array( 'Колонок (для сетки)', 'items' ),
			'gap_x'               => array( 'Зазор по горизонтали', 'items' ),
			'gap_y'               => array( 'Зазор по вертикали', 'items' ),
			'plate_enabled'       => array( 'Плашка под пунктом', 'items' ),
			'plate_color'         => array( 'Цвет плашки', 'items' ),
			'plate_alpha'         => array( 'Непрозрачность плашки, %', 'items' ),
			'plate_radius'        => array( 'Скругление', 'items' ),
			'plate_padding'       => array( 'Внутренний отступ', 'items' ),
			'plate_border_width'  => array( 'Обводка: толщина', 'items' ),
			'plate_border_color'  => array( 'Обводка: цвет', 'items' ),
			'badge_style'         => array( 'Маркер', 'text' ),
			'badge_size'          => array( 'Размер маркера', 'text' ),
			'badge_bg'            => array( 'Цвет маркера', 'text' ),
			'badge_color'         => array( 'Цвет цифры', 'text' ),
			'badge_gap'           => array( 'Отступ от маркера', 'text' ),
			'label_enabled'       => array( 'Метка над тезисом (1–3 слова)', 'text' ),
			'label_size'          => array( 'Кегль метки', 'text' ),
			'label_color'         => array( 'Цвет метки', 'text' ),
			'label_uppercase'     => array( 'Метка ПРОПИСНЫМИ', 'text' ),
			'label_gap'           => array( 'Отступ под меткой', 'text' ),
			'text_size'           => array( 'Кегль тезиса', 'text' ),
			'text_min_size'       => array( 'Минимальный кегль тезиса', 'text' ),
			'text_color'          => array( 'Цвет тезиса', 'text' ),
			'text_line_height'    => array( 'Интерлиньяж тезиса', 'text' ),
			'footer_enabled'      => array( 'Подпись внизу', 'footer' ),
			'footer_size'         => array( 'Кегль подписи', 'footer' ),
			'footer_color'        => array( 'Цвет подписи', 'footer' ),
			'footer_align'        => array( 'Выравнивание подписи', 'footer' ),
			'footer_gap'          => array( 'Отступ над подписью', 'footer' ),
			'logo_id'             => array( 'Логотип (PNG с прозрачностью)', 'footer' ),
			'logo_position'       => array( 'Позиция логотипа', 'footer' ),
			'logo_width'          => array( 'Ширина логотипа, % полотна', 'footer' ),
			'logo_alpha'          => array( 'Непрозрачность логотипа, %', 'footer' ),
			'logo_offset_x'       => array( 'Отступ логотипа X', 'footer' ),
			'logo_offset_y'       => array( 'Отступ логотипа Y', 'footer' ),
		);
	}

	private static function presets_url( $preset = '' ) {
		$args = array( 'page' => self::PRESETS_SLUG );
		if ( '' !== $preset ) {
			$args['preset'] = $preset;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function page_presets() {
		$presets = WPTP_Infographic_Presets::all();
		$current = isset( $_GET['preset'] ) ? sanitize_key( $_GET['preset'] ) : (string) key( $presets ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = 'new' === $current;
		$preset  = $is_new ? WPTP_Infographic_Presets::schema() : WPTP_Infographic_Presets::get( $current );

		echo '<div class="wrap wptp-wrap"><h1>Пресеты картинок</h1>';
		self::notice();

		echo '<p class="description">Как выглядит инфографика, которую плагин генерирует для записей без подходящего изображения: заголовок и нумерованные тезисы списком или сеткой. Стартовые пресеты — квадрат 1080×1080 (в ленте Telegram вертикаль 2:3 занимает слишком много места), размер меняется в поле «Ширина / Высота». Все тезисы — одним кеглем; не влезающий тезис модель переписывает короче, иначе он убирается целиком. Поля те же, что в WP PinFeed: пресеты переносятся между плагинами без правок.</p>';

		echo '<p class="wptp-tabs">';
		foreach ( $presets as $id => $p ) {
			$btn = $id === $current ? 'button button-primary' : 'button';
			echo '<a class="' . esc_attr( $btn ) . '" href="' . esc_url( self::presets_url( $id ) ) . '">' . esc_html( $p['name'] ) . '</a> ';
		}
		echo '<a class="button' . ( $is_new ? ' button-primary' : '' ) . '" href="' . esc_url( self::presets_url( 'new' ) ) . '">+ Новый</a></p>';

		echo '<div class="wptp-preset-layout"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wptp-preset-form">';
		wp_nonce_field( 'wptp_save_preset' );
		echo '<input type="hidden" name="action" value="wptp_save_preset"><input type="hidden" name="preset_id" value="' . esc_attr( $is_new ? '' : $current ) . '">';

		$labels = self::preset_labels();
		foreach ( self::preset_groups() as $group => $title ) {
			echo '<div class="wptp-section"><h3>' . esc_html( $title ) . '</h3><table class="form-table">';
			foreach ( $labels as $key => $meta ) {
				if ( $meta[1] !== $group ) {
					continue;
				}
				echo '<tr><th>' . esc_html( $meta[0] ) . '</th><td>';
				self::preset_field( $key, $preset[ $key ] ?? '' );
				echo '</td></tr>';
			}
			echo '</table></div>';
		}

		submit_button( 'Сохранить пресет' );
		echo '</form>';

		echo '<div class="wptp-preview-pane"><h3>Предпросмотр</h3>';
		echo '<p><label>ID записи для примера <input type="number" class="small-text wptp-preview-post" placeholder="последняя"></label> <button type="button" class="button wptp-preview-btn">Собрать</button></p>';
		echo '<div class="wptp-preview-box"><img class="wptp-preview-img" alt="" style="display:none"><p class="wptp-preview-note description">Собирается с текущими (ещё не сохранёнными) значениями формы. Тезисы — из блока «Telegram» записи или уже готовые от модели, иначе — пример.</p></div>';

		if ( ! $is_new ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Удалить пресет?\')">';
			wp_nonce_field( 'wptp_delete_preset' );
			echo '<input type="hidden" name="action" value="wptp_delete_preset"><input type="hidden" name="preset_id" value="' . esc_attr( $current ) . '"><button class="button-link wptp-danger">Удалить пресет</button></form>';
		}

		echo '<h3>Перенос между сайтами</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_export_presets' );
		echo '<input type="hidden" name="action" value="wptp_export_presets"><button class="button">Скачать все пресеты (JSON)</button></form>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_import_presets' );
		echo '<input type="hidden" name="action" value="wptp_import_presets"><p><input type="file" name="presets_json" accept=".json,application/json"> <button class="button">Импорт</button></p><p class="description">Подходит и JSON, скачанный в WP PinFeed. ID логотипов при переносе между сайтами не совпадут — перевыберите их после импорта.</p></form>';
		if ( WPTP_Infographic_Presets::pinfeed_available() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wptp_import_pinfeed_presets' );
			echo '<input type="hidden" name="action" value="wptp_import_pinfeed_presets"><p><button class="button">Импортировать пресеты из WP PinFeed</button></p><p class="description">Копии с префиксом «pf-» в ID; размер остаётся как в PinFeed (обычно вертикальный 1000×1500).</p></form>';
		}

		echo '</div></div></div>';
	}

	private static function preset_field( $key, $value ) {
		$name      = 'preset[' . $key . ']';
		$positions = WPTP_Image_Util::positions();
		$aligns    = array(
			'left'   => 'По левому краю',
			'center' => 'По центру',
			'right'  => 'По правому краю',
		);

		$selects = array(
			'layout'             => WPTP_Infographic_Presets::layouts(),
			'fit'                => WPTP_Image_Util::fit_modes(),
			'focal'              => $positions,
			'caption_position'   => $positions,
			'footer_position'    => $positions,
			'logo_position'      => $positions,
			'gradient_direction' => WPTP_Image_Util::gradient_directions(),
			'caption_align'      => $aligns,
			'title_align'        => $aligns,
			'footer_align'       => $aligns,
			'badge_style'        => WPTP_Infographic_Presets::badge_styles(),
			'background_mode'    => WPTP_Infographic_Presets::background_modes(),
			'palette_source'     => WPTP_Theme_Palette::sources(),
			'palette_layout'     => WPTP_Theme_Palette::layouts(),
			'format'             => array(
				'jpg'  => 'JPEG',
				'webp' => 'WebP',
				'png'  => 'PNG',
			),
		);

		if ( isset( $selects[ $key ] ) ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $selects[ $key ] as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $value, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>';
			return;
		}

		if ( 'caption_font' === $key || 'font' === $key ) {
			echo '<select name="' . esc_attr( $name ) . '"><option value="">Автоматически</option>';
			foreach ( WPTP_Image_Composer::uploaded_fonts() as $font ) {
				echo '<option value="' . esc_attr( $font['path'] ) . '"' . selected( (string) $value, $font['path'], false ) . '>' . esc_html( $font['name'] ) . '</option>';
			}
			echo '</select> <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '#fonts">Загрузить шрифт</a>';
			return;
		}

		if ( 'logo_id' === $key ) {
			WPTP_Channel_Config::media_field( $name, (int) $value );
			return;
		}

		if ( preg_match( '/(_enabled|grayscale|_autofit|_uppercase)$/', $key ) ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> Да</label>';
			return;
		}

		if ( preg_match( '/(_hex|_color|^background|^badge_bg)$/', $key ) ) {
			echo '<input type="text" class="wptp-color" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		if ( 'name' === $key ) {
			echo '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			return;
		}

		$step = preg_match( '/_line_height$/', $key ) ? '0.05' : '1';
		echo '<input type="number" step="' . esc_attr( $step ) . '" class="small-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}

	public static function handle_save_preset() {
		self::guard( 'wptp_save_preset' );
		$class  = 'WPTP_Infographic_Presets';
		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$id     = $class::save( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '', $values );
		self::redirect( self::PRESETS_SLUG, 'saved', array( 'preset' => $id ) );
	}

	public static function handle_delete_preset() {
		self::guard( 'wptp_delete_preset' );
		$class = 'WPTP_Infographic_Presets';
		$class::delete( isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '' );
		self::redirect( self::PRESETS_SLUG, 'deleted' );
	}

	public static function handle_export_presets() {
		self::guard( 'wptp_export_presets' );
		$class = 'WPTP_Infographic_Presets';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wptp-infographic-presets-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $class::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_import_presets() {
		self::guard( 'wptp_import_presets' );
		$class = 'WPTP_Infographic_Presets';
		$file  = isset( $_FILES['presets_json']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['presets_json']['tmp_name'] ) : '';
		$data  = $file && is_uploaded_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore
		if ( ! is_array( $data ) ) {
			self::redirect( self::PRESETS_SLUG, 'import_error' );
		}
		foreach ( $data as $id => $preset ) {
			if ( is_array( $preset ) ) {
				$class::save( (string) $id, $preset );
			}
		}
		self::redirect( self::PRESETS_SLUG, 'imported' );
	}

	public static function handle_import_pinfeed_presets() {
		self::guard( 'wptp_import_pinfeed_presets' );
		$count = WPTP_Infographic_Presets::import_from_pinfeed();
		self::redirect( self::PRESETS_SLUG, $count ? 'imported' : 'nothing' );
	}

	/** Demo points for the preview when the sample post has none of its own. */
	private static function sample_points() {
		return array(
			array( 'label' => 'Установка', 'text' => 'Сервер ставится одной командой и сразу виден в списке инструментов.' ),
			array( 'label' => 'Настройка', 'text' => 'Пути и ключи задаются в одном конфиге, без правки кода.' ),
			array( 'label' => 'Проверка', 'text' => 'Тестовый запрос показывает, что модель получила доступ к инструменту.' ),
			array( 'label' => 'Ошибки', 'text' => 'Чаще всего мешают неверный путь к файлу и забытый перезапуск клиента.' ),
			array( 'label' => 'Безопасность', 'text' => 'Давайте серверу доступ только к нужным папкам и сервисам.' ),
		);
	}

	/** Renders the posted (unsaved) preset onto a sample post and returns the image URL. */
	public static function ajax_preview_preset() {
		check_ajax_referer( 'wptp_preview_preset', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Недостаточно прав' );
		}

		$values = isset( $_POST['preset'] ) && is_array( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$preset = WPTP_Infographic_Presets::sanitize( $values );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$latest = get_posts(
				array(
					'post_type'      => WPTP_Post_Meta::post_types(),
					'posts_per_page' => 1,
					'post_status'    => 'publish',
				)
			);
			$post   = $latest ? $latest[0] : null;
		}

		$config = array_merge(
			WPTP_Channel_Config::normalise( array() ),
			array(
				'channel_id'    => 0,
				'channel_key'   => 'preview',
				'channel_slug'  => 'preview',
				'channel_title' => 'Предпросмотр',
			)
		);

		// For a "picture of the post" background: its image, else the first one in the text.
		$background = null;
		if ( $post ) {
			$background = WPTP_Image_Resolver::from_source( 'thumbnail', $post, $config );
			if ( ! $background ) {
				$background = WPTP_Image_Resolver::from_source( 'content', $post, $config );
			}
		}
		$source = $background ? WPTP_Image_Resolver::local_path( $background ) : '';
		$title  = $post ? get_the_title( $post ) : 'Как подключить MCP-сервер: 5 шагов';
		$footer = WPTP_Template::resolve( 'site_domain', array( 'config' => $config ) );
		$path   = WPTP_Message_Builder::base_dir() . 'preview/infographic-' . get_current_user_id() . '.' . WPTP_Image_Composer::extension_for( $preset['format'] );
		$note   = $post ? 'Запись #' . $post->ID . ' «' . $title . '»' : 'Без записи';

		$ig = $post ? WPTP_Infographic::points_for( $post, $config ) : array( 'state' => 'none' );
		if ( 'ready' === $ig['state'] ) {
			$points = $ig['points'];
			$note  .= 'manual' === $ig['source'] ? ', тезисы из блока «Telegram»' : ', тезисы от модели';
		} else {
			$points = self::sample_points();
			$note  .= ', тезисы — пример (у записи их ещё нет)';
		}
		// Theme colours as this post would get them, without claiming a palette.
		list( $preset, $palette ) = WPTP_Message_Builder::colourise( $preset, $post ? $post->ID : 0, false );
		if ( '' !== $palette ) {
			$note .= ', цвета темы: ' . WPTP_Theme_Palette::get( $palette )['title'] . ' (utm_content=' . WPTP_Theme_Palette::utm_label( $palette ) . ')';
		}
		$res = WPTP_Infographic::render_fitting( $preset, $points, $title, $footer, $source ? $source : null, $path );
		$err = $res['ok'] ? implode( '; ', $res['notes'] ) : $res['error'];

		if ( ! $res['ok'] ) {
			wp_send_json_error( $err );
		}
		wp_send_json_success(
			array(
				'url'  => WPTP_Message_Builder::base_url() . 'preview/' . basename( $path ) . '?v=' . time(),
				'note' => $note . ( '' !== $err ? '. ' . $err : '' ),
			)
		);
	}

	// ------------------------------------------------------------ settings

	public static function page_settings() {
		$s = WPTP_Settings::get_all();

		echo '<div class="wrap wptp-wrap"><h1>Настройки</h1>';
		self::notice();

		self::render_bots();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_save_settings' );
		echo '<input type="hidden" name="action" value="wptp_save_settings">';

		// ---- connection
		echo '<div class="wptp-section" id="connection"><h3>Соединение с Telegram</h3>';
		echo '<p class="description">Только для запросов к Bot API — остальной сайт ходит в сеть как обычно. MTProxy не подходит: он работает с протоколом приложений Telegram (MTProto), а боты публикуют через обычный HTTPS. Если MTProxy уже стоит на своём сервере, поднимите там SOCKS5 и укажите его здесь.</p><table class="form-table">';
		echo '<tr><th>Прокси</th><td><select name="s[proxy_type]">';
		foreach ( WPTP_Settings::proxy_types() as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['proxy_type'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		$problem = WPTP_Bot_API::proxy_problem();
		if ( '' !== $problem ) {
			echo ' <span class="wptp-warn">' . esc_html( $problem ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><th>Адрес и порт</th><td><input type="text" class="regular-text code" name="s[proxy_host]" value="' . esc_attr( (string) $s['proxy_host'] ) . '" placeholder="proxy.example.com" autocomplete="off"> : <input type="number" class="small-text" name="s[proxy_port]" value="' . esc_attr( (string) $s['proxy_port'] ) . '" placeholder="1080"></td></tr>';
		echo '<tr><th>Логин и пароль</th><td><input type="text" class="regular-text" name="s[proxy_user]" value="' . esc_attr( (string) $s['proxy_user'] ) . '" autocomplete="off"> <input type="password" class="regular-text" name="s[proxy_pass]" value="' . esc_attr( (string) $s['proxy_pass'] ) . '" autocomplete="new-password"><p class="description">Если прокси без авторизации — оставьте пустыми.</p></td></tr>';
		echo '<tr><th>Адрес Bot API</th><td><input type="text" class="regular-text code" name="s[api_base]" value="' . esc_attr( (string) $s['api_base'] ) . '"><p class="description">По умолчанию <code>https://api.telegram.org</code>. Можно указать свой сервер <a href="https://github.com/tdlib/telegram-bot-api" target="_blank" rel="noopener">telegram-bot-api</a> или обратный прокси (nginx, Cloudflare Worker), который передаёт запросы на api.telegram.org.</p></td></tr>';
		echo '<tr><th>Таймаут запроса, с</th><td><input type="number" class="small-text" name="s[api_timeout]" value="' . esc_attr( (string) $s['api_timeout'] ) . '"></td></tr>';
		echo '</table></div>';

		// ---- sending
		echo '<div class="wptp-section"><h3>Отправка</h3><table class="form-table">';
		echo '<tr><th>Сообщений за тик</th><td><input type="number" class="small-text" name="s[sends_per_tick]" value="' . esc_attr( (string) $s['sends_per_tick'] ) . '"><p class="description">На все каналы вместе. Telegram допускает около 20 сообщений в минуту в один канал.</p></td></tr>';
		echo '<tr><th>Картинку отправлять</th><td><select name="s[photo_upload]">';
		foreach ( array(
			'auto'   => 'Автоматически: ссылкой, а с локального сайта — файлом',
			'always' => 'Всегда файлом',
			'never'  => 'Всегда ссылкой',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['photo_upload'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p class="description">По ссылке Telegram скачивает картинку сам (до 5 МБ) — сайт должен быть доступен из интернета. Если скачать не удалось, плагин сам повторит отправку файлом (до 10 МБ).</p></td></tr>';
		echo '</table></div>';

		// ---- LLM
		echo '<div class="wptp-section"><h3>LLM</h3><table class="form-table">';
		echo '<tr><th>Провайдер по умолчанию</th><td><select name="s[llm_provider]">';
		foreach ( WPTP_Settings::llm_providers() as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['llm_provider'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>' . ( WPTP_Settings::llm_ready() ? ' <span class="wptp-ok">ключи заполнены</span>' : ' <span class="wptp-warn">ключи не заполнены</span>' ) . '</td></tr>';

		$fields = array(
			'yandex_api_key'       => array( 'Yandex: API-ключ', 'password' ),
			'yandex_folder_id'     => array( 'Yandex: folder ID', 'text' ),
			'yandex_model'         => array( 'Yandex: модель', 'text' ),
			'deepseek_api_key'     => array( 'DeepSeek: API-ключ', 'password' ),
			'deepseek_model'       => array( 'DeepSeek: модель', 'text' ),
			'qwen_api_key'         => array( 'Qwen: API-ключ', 'password' ),
			'qwen_model'           => array( 'Qwen: модель', 'text' ),
			'qwen_base_url'        => array( 'Qwen: base URL', 'text' ),
			'timeweb_agent_id'     => array( 'Timeweb: ID агента', 'text' ),
			'timeweb_token'        => array( 'Timeweb: токен', 'password' ),
			'timeweb_proxy_source' => array( 'Timeweb: x-proxy-source', 'text' ),
			'openrouter_api_key'   => array( 'OpenRouter: API-ключ', 'password' ),
			'openrouter_model'     => array( 'OpenRouter: модель', 'text' ),
			'llm_timeout'          => array( 'Таймаут запроса, с', 'number' ),
			'llm_per_tick'         => array( 'Запросов к модели за тик', 'number' ),
		);
		foreach ( $fields as $key => $meta ) {
			echo '<tr><th>' . esc_html( $meta[0] ) . '</th><td><input type="' . esc_attr( $meta[1] ) . '" class="' . ( 'number' === $meta[1] ? 'small-text' : 'regular-text' ) . '" name="s[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" autocomplete="off"></td></tr>';
		}
		echo '</table></div>';

		// ---- images
		echo '<div class="wptp-section"><h3>Картинки и тексты</h3><table class="form-table">';
		echo '<tr><th>Путь к шрифту TTF/OTF</th><td><input type="text" class="regular-text code" name="s[caption_font_path]" value="' . esc_attr( (string) $s['caption_font_path'] ) . '"><p class="description">Пусто — загруженный ниже, затем системный (DejaVu, Noto, Liberation). Шрифты из папок llm-agent-articles и wp-pinfeed тоже подхватываются.</p></td></tr>';
		echo '<tr><th>SEO-плагин</th><td><select name="s[seo_plugin]">';
		foreach ( array(
			'auto'     => 'Определить автоматически',
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'none'     => 'Нет',
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['seo_plugin'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p class="description">Откуда брать {seo_title} и {seo_description}. Сейчас: ' . esc_html( WPTP_Settings::seo_plugin() ) . '.</p></td></tr>';
		echo '</table></div>';

		submit_button( 'Сохранить настройки' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_ping' );
		echo '<input type="hidden" name="action" value="wptp_ping"><button class="button">Проверить соединение с Telegram</button> <span class="description">getMe первого бота через текущие настройки соединения (сначала сохраните их).</span></form>';

		// ---- import / test LLM
		echo '<div class="wptp-section"><h3>Ключи LLM из других плагинов сети</h3>';
		$sources = WPTP_Integrations::llm_import_sources();
		if ( $sources ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wptp_import_llm' );
			echo '<input type="hidden" name="action" value="wptp_import_llm"><select name="source">';
			foreach ( $sources as $key => $source ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $source['label'] ) . '</option>';
			}
			echo '</select> <button class="button">Скопировать провайдера и ключи</button></form>';
		} else {
			echo '<p>Ни llm-agent-articles, ни llm-agent-github-repos, ни wp-pinfeed с заполненными ключами на этом сайте не найдено.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( 'wptp_test_llm' );
		echo '<input type="hidden" name="action" value="wptp_test_llm"><button class="button">Проверить соединение с моделью</button></form></div>';

		// ---- fonts
		echo '<div class="wptp-section" id="fonts"><h3>Шрифты</h3>';
		$fonts = WPTP_Image_Composer::uploaded_fonts();
		echo '<p>Найдено: ' . ( $fonts ? esc_html( implode( ', ', wp_list_pluck( $fonts, 'name' ) ) ) : 'нет загруженных' ) . '. Используется сейчас: <code>' . esc_html( WPTP_Image_Composer::resolve_font() ? WPTP_Image_Composer::resolve_font() : 'не найден' ) . '</code></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_upload_font' );
		echo '<input type="hidden" name="action" value="wptp_upload_font"><input type="file" name="font" accept=".ttf,.otf"> <button class="button">Загрузить</button></form></div>';

		// ---- cron
		echo '<div class="wptp-section"><h3>Фоновая задача</h3>';
		echo '<p>По умолчанию работает WP-Cron раз в 10 минут, плюс разовый запуск к моменту, когда подходит время записи. На сайте с малой посещаемостью WP-Cron срабатывает только при заходах — лучше внешний крон:</p>';
		foreach ( WPTP_Scheduler::get_trigger_commands() as $label => $cmd ) {
			echo '<p><strong>' . esc_html( $label ) . '</strong><br><input type="text" class="large-text code" readonly value="' . esc_attr( $cmd ) . '" onclick="this.select()"></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_regen_secret' );
		echo '<input type="hidden" name="action" value="wptp_regen_secret"><button class="button" onclick="return confirm(\'Старые команды перестанут работать. Продолжить?\')">Сгенерировать новый секрет</button></form></div>';

		echo '</div>';
	}

	private static function render_bots() {
		$bots = WPTP_Bots::all();

		echo '<div class="wptp-section" id="bots"><h3>Боты</h3>';
		echo '<p class="description">Бот создаётся в <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> (/newbot → токен). Один бот публикует в любое число каналов: добавьте его в каждый канал администратором с правом «Публикация сообщений». Токен хранится в базе и в форме не показывается.</p>';

		if ( $bots ) {
			echo '<table class="widefat striped"><thead><tr><th>Бот</th><th>Токен</th><th>Проверка</th><th>Каналов</th><th></th></tr></thead><tbody>';
			$usage = array();
			foreach ( get_posts( array( 'post_type' => WPTP_CPT::CHANNEL, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $channel_id ) {
				$bot_id            = (string) get_post_meta( $channel_id, WPTP_Channel_Config::PREFIX . 'bot_id', true );
				$usage[ $bot_id ]  = ( $usage[ $bot_id ] ?? 0 ) + 1;
			}
			foreach ( $bots as $id => $bot ) {
				echo '<tr><td><strong>' . esc_html( WPTP_Bots::label( $bot ) ) . '</strong></td>';
				echo '<td><code>' . esc_html( WPTP_Bot_API::masked_token( $bot['token'] ) ) . '</code></td>';
				echo '<td>' . ( $bot['checked_at'] ? ( '' === $bot['check_error'] ? '<span class="wptp-ok">работает</span>' : '<span class="wptp-warn">' . esc_html( $bot['check_error'] ) . '</span>' ) . '<br><small>' . esc_html( wp_date( 'd.m.Y H:i', (int) $bot['checked_at'] ) ) . '</small>' : '—' ) . '</td>';
				echo '<td>' . (int) ( $usage[ $id ] ?? 0 ) . '</td><td>';
				foreach ( array( 'check_bot' => 'Проверить', 'discover' => 'Найти каналы бота' ) as $action => $label ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wptp-inline-form">';
					wp_nonce_field( 'wptp_' . $action );
					echo '<input type="hidden" name="action" value="wptp_' . esc_attr( $action ) . '"><input type="hidden" name="bot_id" value="' . esc_attr( $id ) . '"><button class="button button-small">' . esc_html( $label ) . '</button></form> ';
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wptp-inline-form" onsubmit="return confirm(\'Удалить бота? Каналы с ним перестанут отправлять.\')">';
				wp_nonce_field( 'wptp_delete_bot' );
				echo '<input type="hidden" name="action" value="wptp_delete_bot"><input type="hidden" name="bot_id" value="' . esc_attr( $id ) . '"><button class="button-link wptp-danger">Удалить</button></form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$found = get_transient( 'wptp_discover_' . get_current_user_id() );
		if ( is_array( $found ) ) {
			delete_transient( 'wptp_discover_' . get_current_user_id() );
			echo '<div class="wptp-discover"><h4>Чаты, где бот появлялся за последние сутки</h4>';
			if ( ! $found ) {
				echo '<p>Не найдено. Telegram хранит события 24 часа: удалите бота из канала и добавьте снова (или опубликуйте в канале любое сообщение) и повторите.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Название</th><th>ID для поля «Канал»</th><th>Тип</th><th>Бот в чате</th></tr></thead><tbody>';
				foreach ( $found as $chat ) {
					$value = '' !== $chat['username'] ? '@' . $chat['username'] : $chat['id'];
					echo '<tr><td>' . esc_html( $chat['title'] ) . '</td><td><input type="text" readonly class="regular-text code" value="' . esc_attr( $value ) . '" onclick="this.select()"></td><td>' . esc_html( $chat['type'] ) . '</td><td>' . esc_html( $chat['status'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		echo '<h4>' . ( $bots ? 'Добавить или заменить токен' : 'Добавить бота' ) . '</h4>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_save_bot' );
		echo '<input type="hidden" name="action" value="wptp_save_bot"><table class="form-table">';
		if ( $bots ) {
			echo '<tr><th>Бот</th><td><select name="bot_id"><option value="">— новый бот —</option>';
			foreach ( $bots as $id => $bot ) {
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( WPTP_Bots::label( $bot ) ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '<tr><th>Название</th><td><input type="text" class="regular-text" name="bot_name" placeholder="для админки; пусто — имя из Telegram"></td></tr>';
		echo '<tr><th>Токен</th><td><input type="password" class="regular-text code" name="bot_token" autocomplete="new-password" placeholder="123456789:AA…"><p class="description">Для существующего бота пусто — токен не меняется.</p></td></tr>';
		echo '</table>';
		submit_button( 'Сохранить и проверить', 'secondary' );
		echo '</form></div>';
	}

	public static function handle_save_bot() {
		self::guard( 'wptp_save_bot' );
		$result = WPTP_Bots::save(
			isset( $_POST['bot_id'] ) ? sanitize_key( $_POST['bot_id'] ) : '',
			isset( $_POST['bot_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_name'] ) ) : '',
			isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : ''
		);
		if ( $result['ok'] ) {
			WPTP_Log::add( 'Бот сохранён и проверен: ' . WPTP_Bots::label( WPTP_Bots::get( $result['id'] ) ) );
			self::redirect( self::SETTINGS_SLUG, 'bot_ok', array(), 'bots' );
		}
		self::redirect( self::SETTINGS_SLUG, 'bot_error', array( 'wptp_extra' => rawurlencode( $result['error'] ) ), 'bots' );
	}

	public static function handle_check_bot() {
		self::guard( 'wptp_check_bot' );
		$result = WPTP_Bots::check( isset( $_POST['bot_id'] ) ? sanitize_key( $_POST['bot_id'] ) : '' );
		if ( $result['ok'] ) {
			self::redirect( self::SETTINGS_SLUG, 'bot_ok', array(), 'bots' );
		}
		self::redirect( self::SETTINGS_SLUG, 'bot_error', array( 'wptp_extra' => rawurlencode( $result['error'] ) ), 'bots' );
	}

	public static function handle_delete_bot() {
		self::guard( 'wptp_delete_bot' );
		WPTP_Bots::delete( isset( $_POST['bot_id'] ) ? sanitize_key( $_POST['bot_id'] ) : '' );
		self::redirect( self::SETTINGS_SLUG, 'deleted', array(), 'bots' );
	}

	public static function handle_discover() {
		self::guard( 'wptp_discover' );
		$result = WPTP_Bots::discover( isset( $_POST['bot_id'] ) ? sanitize_key( $_POST['bot_id'] ) : '' );
		if ( ! $result['ok'] ) {
			self::redirect( self::SETTINGS_SLUG, 'bot_error', array( 'wptp_extra' => rawurlencode( $result['error'] ) ), 'bots' );
		}
		set_transient( 'wptp_discover_' . get_current_user_id(), $result['chats'], 5 * MINUTE_IN_SECONDS );
		self::redirect( self::SETTINGS_SLUG, 'discovered', array(), 'bots' );
	}

	public static function handle_ping() {
		self::guard( 'wptp_ping' );
		$bots = WPTP_Bots::all();
		if ( ! $bots ) {
			self::redirect( self::SETTINGS_SLUG, 'ping_error', array( 'wptp_extra' => rawurlencode( 'нет ни одного бота' ) ), 'connection' );
		}
		$id      = (string) key( $bots );
		$started = microtime( true );
		$result  = WPTP_Bots::check( $id );
		$took    = sprintf( ' (%.1f с)', microtime( true ) - $started );
		if ( $result['ok'] ) {
			self::redirect( self::SETTINGS_SLUG, 'ping_ok', array( 'wptp_extra' => rawurlencode( WPTP_Settings::api_base() . $took ) ), 'connection' );
		}
		self::redirect( self::SETTINGS_SLUG, 'ping_error', array( 'wptp_extra' => rawurlencode( $result['error'] . $took ) ), 'connection' );
	}

	public static function handle_save_settings() {
		self::guard( 'wptp_save_settings' );
		$input  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values = array();

		foreach ( WPTP_Settings::llm_keys() as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		if ( ! isset( WPTP_Settings::llm_providers()[ $values['llm_provider'] ?? '' ] ) ) {
			$values['llm_provider'] = 'deepseek';
		}

		foreach ( array( 'llm_timeout', 'llm_per_tick', 'sends_per_tick', 'api_timeout' ) as $key ) {
			$values[ $key ] = max( 0, (int) ( $input[ $key ] ?? 0 ) );
		}
		$values['proxy_type']        = isset( WPTP_Settings::proxy_types()[ $input['proxy_type'] ?? '' ] ) ? $input['proxy_type'] : 'none';
		$values['proxy_host']        = sanitize_text_field( (string) ( $input['proxy_host'] ?? '' ) );
		$values['proxy_port']        = (string) max( 0, min( 65535, (int) ( $input['proxy_port'] ?? 0 ) ) );
		$values['proxy_user']        = sanitize_text_field( (string) ( $input['proxy_user'] ?? '' ) );
		$values['proxy_pass']        = (string) ( $input['proxy_pass'] ?? '' );
		$base                        = esc_url_raw( trim( (string) ( $input['api_base'] ?? '' ) ), array( 'https', 'http' ) );
		$values['api_base']          = '' !== $base ? untrailingslashit( $base ) : 'https://api.telegram.org';
		$values['photo_upload']      = in_array( $input['photo_upload'] ?? '', array( 'auto', 'always', 'never' ), true ) ? $input['photo_upload'] : 'auto';
		$values['caption_font_path'] = sanitize_text_field( (string) ( $input['caption_font_path'] ?? '' ) );
		$values['seo_plugin']        = in_array( $input['seo_plugin'] ?? '', array( 'auto', 'yoast', 'rankmath', 'none' ), true ) ? $input['seo_plugin'] : 'auto';

		WPTP_Settings::update( $values );
		self::redirect( self::SETTINGS_SLUG, 'saved' );
	}

	public static function handle_import_llm() {
		self::guard( 'wptp_import_llm' );
		$count = WPTP_Integrations::import_llm( isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '' );
		self::redirect( self::SETTINGS_SLUG, $count ? 'imported' : 'nothing' );
	}

	public static function handle_test_llm() {
		self::guard( 'wptp_test_llm' );
		$client = new WPTP_LLM_Client();
		$answer = $client->json( 'Ответь строго JSON-объектом.', 'Верни {"ok": true, "word": "канал"}', 50, 0 );
		if ( is_array( $answer ) ) {
			self::redirect( self::SETTINGS_SLUG, 'llm_ok', array( 'wptp_extra' => rawurlencode( $client->provider() . ' — ' . wp_json_encode( $answer, JSON_UNESCAPED_UNICODE ) ) ) );
		}
		self::redirect( self::SETTINGS_SLUG, 'llm_error', array( 'wptp_extra' => rawurlencode( mb_substr( $client->last_error(), 0, 200 ) ) ) );
	}

	public static function handle_regen_secret() {
		self::guard( 'wptp_regen_secret' );
		WPTP_Settings::regenerate_secret();
		self::redirect( self::SETTINGS_SLUG, 'secret' );
	}

	public static function handle_run_tick() {
		self::guard( 'wptp_run_tick' );
		$summary = WPTP_Scheduler::run_tick();
		self::redirect( self::MAIN_SLUG, 'tick', array( 'wptp_extra' => rawurlencode( $summary ) ) );
	}

	public static function handle_upload_font() {
		self::guard( 'wptp_upload_font' );
		$name = isset( $_FILES['font']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['font']['name'] ) ) : '';
		$tmp  = isset( $_FILES['font']['tmp_name'] ) ? (string) $_FILES['font']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $tmp || ! is_uploaded_file( $tmp ) || ! preg_match( '/\.(ttf|otf)$/i', $name ) ) {
			self::redirect( self::SETTINGS_SLUG, 'font_error' );
		}
		// A font file starts with one of these four signatures; anything else
		// is not a font, whatever its extension says.
		$magic = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore
		if ( ! in_array( $magic, array( "\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf' ), true ) ) {
			self::redirect( self::SETTINGS_SLUG, 'font_error' );
		}

		$dir = WPTP_Image_Composer::fonts_dir();
		wp_mkdir_p( $dir );
		move_uploaded_file( $tmp, trailingslashit( $dir ) . $name );
		self::redirect( self::SETTINGS_SLUG, 'font' );
	}

	// ------------------------------------------------------------------ log

	public static function page_log() {
		echo '<div class="wrap wptp-wrap"><h1>Журнал</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wptp_clear_log' );
		echo '<input type="hidden" name="action" value="wptp_clear_log"><button class="button">Очистить</button></form>';

		$entries = WPTP_Log::get_recent();
		if ( ! $entries ) {
			echo '<p>Записей нет.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th style="width:150px">Время</th><th style="width:70px">Уровень</th><th>Сообщение</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( 'd.m.Y H:i:s', (int) $entry['time'] ) ) . '</td><td>' . esc_html( $entry['level'] ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function handle_clear_log() {
		self::guard( 'wptp_clear_log' );
		WPTP_Log::clear();
		self::redirect( self::LOG_SLUG, 'cleared' );
	}
}
