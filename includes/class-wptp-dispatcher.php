<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts posts into the queue and sends what is due.
 *
 *  - Publication: wp_after_insert_post (fires after terms and meta are saved,
 *    for the editor, REST, imports and scheduled posts alike) queues a newly
 *    published post for every channel it matches, at publish time + delay.
 *  - Rotation: each tick, a channel with rotation on gets one post it has
 *    never had, once its rotation interval has passed.
 *  - Sending: due rows go out within the channel's gates (quiet hours,
 *    interval, daily limit — all optional). Right before sending, the post is
 *    checked against the channel again (rules, opt-out), and the model is
 *    asked for the announcement and infographic points if needed.
 *
 * A row that can't be sent yet stays pending; Telegram errors are retried
 * with a back-off and end as "failed" after MAX_ATTEMPTS.
 */
class WPTP_Dispatcher {

	const MAX_ATTEMPTS = 6;

	/** Seconds to wait after the n-th failed attempt. */
	const RETRY_DELAYS = array( 300, 900, 3600, 10800, 21600 );

	/** Channel meta: rotation ran out of posts (logged once). */
	const ROTATION_EMPTY = '_wptp_rotation_empty';

	public static function init() {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert' ), 20, 4 );
	}

	// ------------------------------------------------------------- queueing

	public static function on_after_insert( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $post_before instanceof WP_Post && 'publish' === $post_before->post_status ) {
			return; // An edit of an already published post, not a publication.
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || in_array( $post->post_type, array( WPTP_CPT::CHANNEL, 'attachment' ), true ) ) {
			return;
		}
		self::enqueue_new( $post );
	}

	/** @return int Channels the post was queued for. */
	public static function enqueue_new( WP_Post $post ) {
		$queued = 0;
		foreach ( WPTP_Channel_Config::active() as $channel_id => $config ) {
			if ( ! $config['on_publish'] || ! in_array( $post->post_type, $config['post_types'], true ) ) {
				continue;
			}
			if ( ! WPTP_Query::matches( $post->ID, $config ) ) {
				continue;
			}
			$published = max( time(), (int) get_post_time( 'U', true, $post ) );
			$at        = $published + (int) $config['delay_minutes'] * MINUTE_IN_SECONDS;
			if ( WPTP_Queue::enqueue( $post->ID, $channel_id, 'new', $at ) ) {
				++$queued;
				WPTP_Scheduler::schedule_at( $at );
			}
		}
		return $queued;
	}

	/** Queues the next never-sent post of a channel, when its rotation is due. */
	public static function plan_rotation( array $config ) {
		$channel_id = (int) $config['channel_id'];
		if ( ! $config['rotation_enabled'] || WPTP_Queue::has_pending( $channel_id, 'rotation' ) ) {
			return false;
		}
		$every = (int) $config['rotation_every_hours'] * HOUR_IN_SECONDS;
		$last  = (int) get_post_meta( $channel_id, WPTP_Channel_Config::LAST_ROTATION, true );
		if ( $every > 0 && time() - $last < $every ) {
			return false;
		}
		// Not while the channel can't send anyway — the slot would be wasted.
		if ( '' !== self::gate( $config ) ) {
			return false;
		}

		$posts = WPTP_Query::rotation( $config, 1 );
		if ( ! $posts ) {
			if ( ! get_post_meta( $channel_id, self::ROTATION_EMPTY, true ) ) {
				update_post_meta( $channel_id, self::ROTATION_EMPTY, time() );
				WPTP_Log::add( 'Ротация «' . $config['channel_title'] . '»: все подходящие записи уже отправлены' );
			}
			return false;
		}
		delete_post_meta( $channel_id, self::ROTATION_EMPTY );

		$id = WPTP_Queue::enqueue( $posts[0]->ID, $channel_id, 'rotation', time() );
		update_post_meta( $channel_id, WPTP_Channel_Config::LAST_ROTATION, time() );
		return (bool) $id;
	}

	// --------------------------------------------------------------- gates

	/**
	 * Why the channel may not send right now ('' — it may). Every gate is
	 * optional: an empty/zero setting is no gate.
	 */
	public static function gate( array $config ) {
		$channel_id = (int) $config['channel_id'];

		if ( '' !== $config['quiet_from'] && '' !== $config['quiet_to'] && $config['quiet_from'] !== $config['quiet_to'] ) {
			$now   = wp_date( 'H:i' );
			$from  = $config['quiet_from'];
			$to    = $config['quiet_to'];
			$quiet = $from < $to ? ( $now >= $from && $now < $to ) : ( $now >= $from || $now < $to );
			if ( $quiet ) {
				return 'тихие часы ' . $from . '–' . $to;
			}
		}

		if ( $config['min_interval_minutes'] > 0 ) {
			$next = WPTP_Queue::last_sent_at( $channel_id ) + (int) $config['min_interval_minutes'] * MINUTE_IN_SECONDS;
			if ( $next > time() ) {
				return 'интервал: следующий пост после ' . wp_date( 'H:i', $next );
			}
		}

		if ( $config['daily_limit'] > 0 ) {
			$midnight = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
			if ( WPTP_Queue::count( $channel_id, WPTP_Queue::SENT, $midnight ) >= (int) $config['daily_limit'] ) {
				return 'дневной лимит ' . (int) $config['daily_limit'];
			}
		}

		return '';
	}

	// ------------------------------------------------------------- sending

	/**
	 * Sends due rows within the budgets.
	 *
	 * @param int $budget    Messages to send at most.
	 * @param int $llm_left  LLM calls left (by reference — shared with the tick).
	 * @return array{sent:int,skipped:int,failed:int,waiting:int}
	 */
	public static function send_due( $budget, &$llm_left ) {
		$stats   = array( 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0 );
		$configs = array();
		$closed  = array();

		foreach ( WPTP_Queue::due() as $row ) {
			if ( $budget <= 0 ) {
				break;
			}
			$channel_id = (int) $row->channel_id;
			if ( isset( $closed[ $channel_id ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $channel_id, $configs ) ) {
				$configs[ $channel_id ] = WPTP_Channel_Config::get( $channel_id );
			}
			$config = $configs[ $channel_id ];
			if ( ! $config ) {
				WPTP_Queue::mark_skipped( $row->id, 'канал удалён' );
				continue;
			}

			$manual = 'manual' === $row->kind;
			if ( ! $manual ) {
				if ( ! $config['published'] || ! $config['enabled'] ) {
					$closed[ $channel_id ] = true;
					continue;
				}
				if ( '' !== self::gate( $config ) ) {
					$closed[ $channel_id ] = true;
					continue;
				}
			}

			$result = self::process( $row, $config, $llm_left );
			if ( isset( $stats[ $result ] ) ) {
				++$stats[ $result ];
			}
			if ( 'sent' === $result ) {
				--$budget;
			}
		}

		return $stats;
	}

	/**
	 * Re-checks, prepares and sends one row.
	 *
	 * @param object $row      Queue row.
	 * @param array  $config   Channel config.
	 * @param int    $llm_left LLM calls left (by reference).
	 * @return string sent | skipped | failed | waiting
	 */
	public static function process( $row, array $config, &$llm_left ) {
		$post   = get_post( (int) $row->post_id );
		$manual = 'manual' === $row->kind;

		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			WPTP_Queue::mark_skipped( $row->id, 'запись не опубликована' );
			return 'skipped';
		}
		if ( '1' === (string) get_post_meta( $post->ID, WPTP_Query::EXCLUDE_META, true ) ) {
			WPTP_Queue::mark_skipped( $row->id, 'у записи стоит «Не публиковать в Telegram»' );
			return 'skipped';
		}
		if ( ! $manual && ! WPTP_Query::matches( $post->ID, $config ) ) {
			WPTP_Queue::mark_skipped( $row->id, 'запись больше не подходит под условия канала' );
			return 'skipped';
		}

		// The model, right before sending — a message goes out once.
		foreach ( array( 'WPTP_Infographic', 'WPTP_LLM_Text' ) as $class ) {
			if ( ! $class::needs_generation( $post, $config ) ) {
				continue;
			}
			if ( $llm_left <= 0 ) {
				return 'waiting';
			}
			--$llm_left;
			$class::generate( $post, $config );
		}

		$message = WPTP_Message_Builder::build( $post, $config, true );
		if ( 'pending' === $message['status'] ) {
			return 'waiting';
		}
		if ( 'skip' === $message['status'] ) {
			WPTP_Queue::mark_skipped( $row->id, implode( '; ', $message['notes'] ) );
			return 'skipped';
		}

		return self::send( $row, $config, $post, $message );
	}

	/** @return string sent | failed | waiting */
	private static function send( $row, array $config, WP_Post $post, array $message ) {
		$api = WPTP_Bots::api( $config['bot_id'] );
		if ( ! $api ) {
			return self::failure( $row, $config, $post, array( 'code' => 0, 'message' => 'бот канала не найден', 'retry_after' => 0, 'migrate_to' => '' ) );
		}

		$chat_id = $config['chat_id'];
		$result  = self::deliver( $api, $chat_id, $config, $message );
		$error   = $api->last_error();

		// A group upgraded to a supergroup gets a new id; follow it once.
		if ( null === $result && '' !== $error['migrate_to'] ) {
			$chat_id = $error['migrate_to'];
			update_post_meta( $config['channel_id'], WPTP_Channel_Config::PREFIX . 'chat_id', $chat_id );
			WPTP_Log::add( 'Канал «' . $config['channel_title'] . '»: группа стала супергруппой, новый ID ' . $chat_id );
			$result = self::deliver( $api, $chat_id, $config, $message );
			$error  = $api->last_error();
		}

		if ( ! is_array( $result ) || empty( $result['message_id'] ) ) {
			return self::failure( $row, $config, $post, $error );
		}

		$chat = $result['chat'] ?? array();
		WPTP_Queue::mark_sent( $row->id, (string) ( $chat['id'] ?? $chat_id ), (int) $result['message_id'], self::message_url( $chat, (int) $result['message_id'] ), $message['variant'] );
		WPTP_Log::add( 'Отправлено в «' . $config['channel_title'] . '»: #' . $post->ID . ' «' . get_the_title( $post ) . '» (' . ( WPTP_Queue::kinds()[ $row->kind ] ?? $row->kind ) . ', ' . ( $message['image'] ? 'картинка: ' . $message['variant'] : 'текст' ) . ')' );
		return 'sent';
	}

	/**
	 * One API call with the fallbacks that make sense on the spot: a photo
	 * URL Telegram couldn't fetch is uploaded as a file; markup Telegram
	 * couldn't parse goes as plain text.
	 *
	 * @return array|null The sent Message.
	 */
	private static function deliver( WPTP_Bot_API $api, $chat_id, array $config, array $message ) {
		$params = array(
			'chat_id'    => $chat_id,
			'parse_mode' => 'HTML',
		);
		if ( $config['disable_notification'] ) {
			$params['disable_notification'] = true;
		}
		if ( '' !== trim( $config['button_text'] ) ) {
			$params['reply_markup'] = array(
				'inline_keyboard' => array(
					array(
						array(
							'text' => trim( $config['button_text'] ),
							'url'  => $message['link'],
						),
					),
				),
			);
		}

		$image = 'photo' === $message['format'] ? $message['image'] : null;
		$send  = function ( array $params ) use ( $api, $image, $message ) {
			if ( $image ) {
				$params['caption'] = $params['text'];
				unset( $params['text'] );
				$upload = self::should_upload( $image );
				$result = $api->send_photo( $params, $upload ? $image['path'] : $image['url'], $upload );
				if ( null === $result && ! $upload && '' !== $image['path'] && self::is_fetch_error( $api->error_message() ) ) {
					$result = $api->send_photo( $params, $image['path'], true );
				}
				return $result;
			}
			$params['link_preview_options'] = 'link_preview' === $message['format']
				? array( 'url' => $message['link'], 'prefer_large_media' => true )
				: array( 'is_disabled' => true );
			return $api->send_message( $params );
		};

		$params['text'] = $message['text'];
		$result         = $send( $params );

		if ( null === $result && false !== stripos( $api->error_message(), "can't parse entities" ) ) {
			WPTP_Log::error( 'Разметка не разобрана Telegram, отправляю без неё: ' . $api->error_message() );
			unset( $params['parse_mode'] );
			$params['text'] = html_entity_decode( wp_strip_all_tags( $message['text'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$result         = $send( $params );
		}

		return is_array( $result ) ? $result : null;
	}

	/** Errors where Telegram couldn't download the picture by URL. */
	private static function is_fetch_error( $error ) {
		return (bool) preg_match( '/HTTP URL|web ?page|wrong file identifier|failed to get|WEBPAGE_|IMAGE_PROCESS_FAILED|wrong type of the/i', (string) $error );
	}

	/** Send the file itself rather than a URL Telegram has to fetch. */
	private static function should_upload( array $image ) {
		if ( '' === $image['path'] ) {
			return false;
		}
		switch ( (string) WPTP_Settings::get( 'photo_upload', 'auto' ) ) {
			case 'always':
				return true;
			case 'never':
				return false;
			default:
				return self::site_is_local() || 0 !== strpos( $image['url'], 'https://' );
		}
	}

	/** A dev/test host Telegram can't reach from outside. */
	public static function site_is_local() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|test|localhost|lan|internal)$/', $host ) ) {
			return true;
		}
		return (bool) filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	public static function message_url( array $chat, $message_id ) {
		if ( ! empty( $chat['username'] ) ) {
			return 'https://t.me/' . $chat['username'] . '/' . (int) $message_id;
		}
		$id = (string) ( $chat['id'] ?? '' );
		if ( 0 === strpos( $id, '-100' ) ) {
			return 'https://t.me/c/' . substr( $id, 4 ) . '/' . (int) $message_id;
		}
		return '';
	}

	/** @return string failed | waiting */
	private static function failure( $row, array $config, WP_Post $post, array $error ) {
		$text = ( $error['code'] ? $error['code'] . ' ' : '' ) . $error['message'];

		// Flood control: wait as long as Telegram says, it's not a real attempt.
		if ( 429 === (int) $error['code'] ) {
			WPTP_Queue::update(
				$row->id,
				array(
					'next_try_at' => time() + max( 1, (int) $error['retry_after'] ) + 1,
					'error'       => $text,
				)
			);
			return 'waiting';
		}

		$attempts = (int) $row->attempts + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			WPTP_Queue::update(
				$row->id,
				array(
					'status'   => WPTP_Queue::FAILED,
					'attempts' => $attempts,
					'error'    => $text,
				)
			);
			WPTP_Log::error( 'Не отправлено в «' . $config['channel_title'] . '» после ' . $attempts . ' попыток: #' . $post->ID . ' — ' . $text );
			return 'failed';
		}

		$delays = self::RETRY_DELAYS;
		WPTP_Queue::update(
			$row->id,
			array(
				'attempts'    => $attempts,
				'next_try_at' => time() + $delays[ min( $attempts, count( $delays ) ) - 1 ],
				'error'       => $text,
			)
		);
		WPTP_Log::error( 'Ошибка отправки в «' . $config['channel_title'] . '» (попытка ' . $attempts . '): #' . $post->ID . ' — ' . $text );
		return 'failed';
	}

	// ------------------------------------------------------------- warm-up

	/**
	 * Uses spare LLM budget on rows that are still waiting for their time
	 * (the channel's delay, quiet hours), so they go out ready.
	 */
	public static function prewarm( &$llm_left ) {
		if ( $llm_left <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table = WPTP_Queue::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY scheduled_at ASC LIMIT 50", WPTP_Queue::PENDING ) ); // phpcs:ignore
		$done  = 0;

		foreach ( $rows as $row ) {
			$config = WPTP_Channel_Config::get( (int) $row->channel_id );
			$post   = get_post( (int) $row->post_id );
			if ( ! $config || ! $post ) {
				continue;
			}
			foreach ( array( 'WPTP_Infographic', 'WPTP_LLM_Text' ) as $class ) {
				if ( $llm_left <= 0 ) {
					return $done;
				}
				if ( $class::needs_generation( $post, $config ) ) {
					--$llm_left;
					if ( $class::generate( $post, $config ) ) {
						++$done;
					}
				}
			}
		}
		return $done;
	}

	// -------------------------------------------------------- manual & check

	/**
	 * "Send now" from the post screen or the CLI: bypasses the channel's
	 * gates and rules (not the opt-out), sends synchronously.
	 *
	 * @param bool $again Send a post that already went to this channel once more.
	 * @return array{ok:bool,message:string}
	 */
	public static function send_now( $post_id, $channel_id, $again = false ) {
		$config = WPTP_Channel_Config::get( $channel_id );
		if ( ! $config || '' === $config['chat_id'] || ! WPTP_Bots::get( $config['bot_id'] ) ) {
			return array( 'ok' => false, 'message' => 'канал не настроен' );
		}

		$row = WPTP_Queue::get_by_key( WPTP_Queue::key( $post_id, $channel_id ) );
		if ( $row && WPTP_Queue::SENT === $row->status && ! $again ) {
			return array( 'ok' => false, 'message' => 'уже отправлено в этот канал' );
		}
		if ( $row && WPTP_Queue::SENT === $row->status ) {
			$id = WPTP_Queue::enqueue_resend( $post_id, $channel_id );
		} elseif ( $row && WPTP_Queue::PENDING === $row->status ) {
			WPTP_Queue::update( $row->id, array( 'kind' => 'manual', 'next_try_at' => 0 ) );
			$id = (int) $row->id;
		} else {
			$id = WPTP_Queue::enqueue( $post_id, $channel_id, 'manual', time() );
		}
		if ( ! $id ) {
			return array( 'ok' => false, 'message' => 'не удалось поставить в очередь' );
		}

		$llm    = 3;
		$row    = WPTP_Queue::get( $id );
		$result = self::process( $row, $config, $llm );
		$row    = WPTP_Queue::get( $id );

		switch ( $result ) {
			case 'sent':
				return array( 'ok' => true, 'message' => 'отправлено' . ( $row->message_url ? ': ' . $row->message_url : '' ) );
			case 'waiting':
				return array( 'ok' => false, 'message' => 'ещё не готово (модель или картинка) — запись в очереди, уйдёт с фоновой задачей' );
			default:
				return array( 'ok' => false, 'message' => (string) $row->error );
		}
	}

	/**
	 * getChat + getChatMember: does the chat exist and may the bot post there?
	 *
	 * @return array{ok:bool,error:string,title:string,username:string,type:string,at:int}
	 */
	public static function check_channel( $channel_id ) {
		$config = WPTP_Channel_Config::get( $channel_id );
		$out    = array( 'ok' => false, 'error' => '', 'title' => '', 'username' => '', 'type' => '', 'at' => time() );
		$bot    = $config ? WPTP_Bots::get( $config['bot_id'] ) : null;
		$api    = $config ? WPTP_Bots::api( $config['bot_id'] ) : null;

		if ( ! $config || ! $bot || ! $api || '' === $config['chat_id'] ) {
			$out['error'] = 'не выбран бот или не указан канал';
		} else {
			$chat = $api->get_chat( $config['chat_id'] );
			if ( ! is_array( $chat ) ) {
				$out['error'] = 'канал не найден или бот не добавлен в него: ' . $api->error_message();
			} else {
				$out['title']    = (string) ( $chat['title'] ?? '' );
				$out['username'] = (string) ( $chat['username'] ?? '' );
				$out['type']     = (string) ( $chat['type'] ?? '' );

				if ( empty( $bot['tg_id'] ) ) {
					WPTP_Bots::check( $config['bot_id'] );
					$bot = WPTP_Bots::get( $config['bot_id'] );
				}
				$member = $api->get_chat_member( $config['chat_id'], (int) $bot['tg_id'] );
				$status = is_array( $member ) ? (string) ( $member['status'] ?? '' ) : '';

				if ( 'channel' === $out['type'] ) {
					$can          = 'creator' === $status || ( 'administrator' === $status && ! empty( $member['can_post_messages'] ) );
					$out['ok']    = $can;
					$out['error'] = $can ? '' : ( 'administrator' === $status ? 'у бота нет права «Публикация сообщений»' : 'бот не администратор канала' );
				} else {
					$can          = in_array( $status, array( 'creator', 'administrator', 'member' ), true ) || ( 'restricted' === $status && ! empty( $member['can_send_messages'] ) );
					$out['ok']    = $can;
					$out['error'] = $can ? '' : 'бот не может писать в эту группу (' . ( '' !== $status ? $status : $api->error_message() ) . ')';
				}
			}
		}

		update_post_meta( $channel_id, WPTP_Channel_Config::CHECK, $out );
		return $out;
	}
}
