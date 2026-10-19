<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post overrides, one small metabox on every public post type:
 * hand-written announcement ({tg_title} / {tg_text}), infographic points,
 * an opt-out from all channels — and the post's status in every channel,
 * with "send now". There is no picture field on purpose: a post's own
 * picture for Telegram comes from a meta field (image source "meta").
 */
class WPTP_Post_Meta {

	const TITLE   = '_wptp_title';
	const TEXT    = '_wptp_text';
	const EXCLUDE = '_wptp_exclude';
	const THESES  = '_wptp_theses_manual';
	/** Theme palette slug the generated infographic was drawn in (rotating presets). */
	const PALETTE = '_wptp_palette';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_post_wptp_send_now', array( __CLASS__, 'handle_send_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return (array) apply_filters( 'wptp_metabox_post_types', array_values( $types ) );
	}

	public static function add_meta_box() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'wptp_post', 'Telegram', array( __CLASS__, 'render' ), $type, 'side', 'default' );
		}
	}

	public static function render( $post ) {
		wp_nonce_field( 'wptp_post_meta', 'wptp_post_meta_nonce' );

		self::render_status( $post );

		echo '<p><label for="wptp-title"><strong>Заголовок анонса</strong></label><br>';
		echo '<input type="text" id="wptp-title" class="widefat" name="wptp_title" value="' . esc_attr( (string) get_post_meta( $post->ID, self::TITLE, true ) ) . '"></p>';

		echo '<p><label for="wptp-text"><strong>Текст анонса</strong></label><br>';
		echo '<textarea id="wptp-text" class="widefat" rows="4" name="wptp_text">' . esc_textarea( (string) get_post_meta( $post->ID, self::TEXT, true ) ) . '</textarea></p>';
		echo '<p class="description">Пусто — текст соберётся по шаблону канала ({tg_title} / {tg_text} в начале цепочки).</p>';

		echo '<p><label for="wptp-theses"><strong>Тезисы для инфографики</strong></label><br>';
		echo '<textarea id="wptp-theses" class="widefat" rows="5" name="wptp_theses" placeholder="Метка: тезис одним предложением">' . esc_textarea( (string) get_post_meta( $post->ID, self::THESES, true ) ) . '</textarea></p>';
		echo '<p class="description">По пункту на строку, «Метка: текст» или просто текст. Нужны, только если картинка генерируется. Пусто — тезисы напишет модель.</p>';

		$palette = WPTP_Theme_Palette::get( (string) get_post_meta( $post->ID, self::PALETTE, true ) );
		if ( $palette ) {
			echo '<p><label><input type="checkbox" name="wptp_recolor" value="1"> Другой цвет инфографики</label><br><span class="description">Сейчас: ' . esc_html( $palette['title'] ) . '. Уже отправленные посты не меняются.</span></p>';
		}

		echo '<p><label><input type="checkbox" name="wptp_exclude" value="1"' . checked( '1', (string) get_post_meta( $post->ID, self::EXCLUDE, true ), false ) . '> Не публиковать в Telegram</label></p>';
	}

	/** One line per channel: where the post stands there, and what can be done. */
	private static function render_status( WP_Post $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$channels = WPTP_Channel_Config::active();
		$rows     = array();
		foreach ( WPTP_Queue::for_post( $post->ID ) as $row ) {
			// Newest first: the first row per channel is the current state.
			if ( ! isset( $rows[ (int) $row->channel_id ] ) ) {
				$rows[ (int) $row->channel_id ] = $row;
			}
		}
		if ( ! $channels && ! $rows ) {
			return;
		}

		$published = 'publish' === $post->post_status;
		echo '<div class="wptp-post-status"><p><strong>Каналы</strong></p><ul>';
		foreach ( $channels as $channel_id => $config ) {
			$row = $rows[ $channel_id ] ?? null;
			echo '<li><span class="wptp-ch">' . esc_html( $config['channel_title'] ) . '</span>: ';

			if ( $row ) {
				echo self::row_status( $row ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( ! in_array( $post->post_type, $config['post_types'], true ) ) {
				echo '<span class="description">не этот тип записей</span>';
			} elseif ( $published && ! WPTP_Query::matches( $post->ID, $config ) ) {
				echo '<span class="description">не подходит под условия</span>';
			} else {
				echo '<span class="description">не отправлялось</span>';
			}

			if ( $published ) {
				$sent = $row && WPTP_Queue::SENT === $row->status;
				$url  = wp_nonce_url(
					admin_url( 'admin-post.php?action=wptp_send_now&post=' . $post->ID . '&channel=' . $channel_id . ( $sent ? '&again=1' : '' ) ),
					'wptp_send_now_' . $post->ID . '_' . $channel_id
				);
				$label   = $sent ? 'Отправить ещё раз' : 'Отправить сейчас';
				$confirm = $sent ? 'Запись уже есть в этом канале. Отправить повторно?' : 'Отправить запись в канал сейчас? Несохранённые правки записи не попадут в пост.';
				echo '<br><a href="' . esc_url( $url ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')">' . esc_html( $label ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	private static function row_status( $row ) {
		switch ( $row->status ) {
			case WPTP_Queue::SENT:
				$when = esc_html( wp_date( 'd.m.Y H:i', (int) $row->sent_at ) );
				return '<span class="wptp-ok">отправлено ' . $when . '</span>' . ( $row->message_url ? ' · <a href="' . esc_url( $row->message_url ) . '" target="_blank" rel="noopener">пост</a>' : '' );
			case WPTP_Queue::PENDING:
				$at = max( (int) $row->scheduled_at, (int) $row->next_try_at );
				return 'в очереди' . ( $at > time() ? ' до ' . esc_html( wp_date( 'd.m H:i', $at ) ) : '' ) . ( $row->error ? '<br><span class="wptp-warn">' . esc_html( $row->error ) . '</span>' : '' );
			case WPTP_Queue::FAILED:
				return '<span class="wptp-warn">ошибка: ' . esc_html( (string) $row->error ) . '</span>';
			default:
				return '<span class="description">пропущено: ' . esc_html( (string) $row->error ) . '</span>';
		}
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['wptp_post_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wptp_post_meta_nonce'] ) ), 'wptp_post_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::put( $post_id, self::TITLE, isset( $_POST['wptp_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wptp_title'] ) ) : '' );
		self::put( $post_id, self::TEXT, isset( $_POST['wptp_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wptp_text'] ) ) : '' );
		self::put( $post_id, self::THESES, isset( $_POST['wptp_theses'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wptp_theses'] ) ) : '' );
		self::put( $post_id, self::EXCLUDE, empty( $_POST['wptp_exclude'] ) ? '' : '1' );

		if ( ! empty( $_POST['wptp_recolor'] ) ) {
			$old = (string) get_post_meta( $post_id, self::PALETTE, true );
			self::put( $post_id, self::PALETTE, WPTP_Theme_Palette::choose( 'rotate', $old, true ) );
		}
	}

	private static function put( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	// ------------------------------------------------------------ send now

	public static function handle_send_now() {
		$post_id    = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$channel_id = isset( $_GET['channel'] ) ? absint( $_GET['channel'] ) : 0;
		check_admin_referer( 'wptp_send_now_' . $post_id . '_' . $channel_id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Недостаточно прав' );
		}

		$result = WPTP_Dispatcher::send_now( $post_id, $channel_id, ! empty( $_GET['again'] ) );
		$config = WPTP_Channel_Config::get( $channel_id );
		set_transient(
			'wptp_notice_' . get_current_user_id(),
			array(
				'ok'      => $result['ok'],
				'message' => 'Telegram, «' . ( $config ? $config['channel_title'] : '#' . $channel_id ) . '»: ' . $result['message'],
			),
			MINUTE_IN_SECONDS
		);

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : get_edit_post_link( $post_id, 'url' ) );
		exit;
	}

	public static function notice() {
		$key    = 'wptp_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice ' . ( $notice['ok'] ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
