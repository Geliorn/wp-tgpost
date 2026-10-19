<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: `wp tgpost <command>`.
 */
class WPTP_CLI {

	/**
	 * Lists channels with their destination and numbers.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *     wp tgpost channels
	 */
	public function channels( $args, $assoc ) {
		$rows = array();
		foreach ( get_posts( array( 'post_type' => WPTP_CPT::CHANNEL, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $channel ) {
			$config = WPTP_Channel_Config::get( $channel->ID );
			$bot    = WPTP_Bots::get( $config['bot_id'] );
			$rows[] = array(
				'id'       => $channel->ID,
				'title'    => get_the_title( $channel ),
				'status'   => $channel->post_status . ( $config['enabled'] ? '' : ' (off)' ),
				'chat'     => $config['chat_id'],
				'bot'      => $bot ? WPTP_Bots::label( $bot ) : '',
				'matches'  => WPTP_Query::count( $config ),
				'rotation' => $config['rotation_enabled'] ? WPTP_Query::count( $config, 'rotation' ) : '-',
				'sent'     => WPTP_Queue::count( $channel->ID, WPTP_Queue::SENT ),
				'pending'  => WPTP_Queue::count( $channel->ID, WPTP_Queue::PENDING ),
				'gate'     => WPTP_Dispatcher::gate( $config ),
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'title', 'status', 'chat', 'bot', 'matches', 'rotation', 'sent', 'pending', 'gate' ) );
	}

	/**
	 * Checks that the channel exists and its bot may post there.
	 *
	 * ## OPTIONS
	 * <channel>
	 * : Channel post ID.
	 */
	public function check( $args, $assoc ) {
		$result = WPTP_Dispatcher::check_channel( (int) $args[0] );
		$name   = trim( $result['title'] . ( '' !== $result['username'] ? ' @' . $result['username'] : '' ) );
		if ( $result['ok'] ) {
			WP_CLI::success( 'Бот может публиковать: ' . $name . ' (' . $result['type'] . ')' );
			return;
		}
		WP_CLI::error( $result['error'] );
	}

	/**
	 * Lists bots, or checks one / finds the chats it was added to.
	 *
	 * ## OPTIONS
	 * [<bot>]
	 * : Bot id (see the list). With it: getMe and the chats from getUpdates.
	 */
	public function bots( $args, $assoc ) {
		if ( empty( $args[0] ) ) {
			$rows = array();
			foreach ( WPTP_Bots::all() as $id => $bot ) {
				$rows[] = array(
					'id'       => $id,
					'name'     => $bot['name'],
					'username' => $bot['username'],
					'token'    => WPTP_Bot_API::masked_token( $bot['token'] ),
					'error'    => $bot['check_error'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'username', 'token', 'error' ) );
			return;
		}

		$check = WPTP_Bots::check( $args[0] );
		if ( ! $check['ok'] ) {
			WP_CLI::error( $check['error'] );
		}
		WP_CLI::log( 'getMe: ok, @' . WPTP_Bots::get( $args[0] )['username'] );
		$found = WPTP_Bots::discover( $args[0] );
		if ( ! $found['ok'] ) {
			WP_CLI::warning( $found['error'] );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $found['chats'], array( 'id', 'username', 'title', 'type', 'status' ) );
	}

	/**
	 * Checks the connection to the Bot API (proxy, API address) with getMe.
	 */
	public function ping( $args, $assoc ) {
		$bots = WPTP_Bots::all();
		if ( ! $bots ) {
			WP_CLI::error( 'Нет ни одного бота.' );
		}
		$started = microtime( true );
		$result  = WPTP_Bots::check( (string) key( $bots ) );
		$took    = sprintf( '%.2f с', microtime( true ) - $started );
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] . ' (' . $took . ')' );
		}
		WP_CLI::success( WPTP_Settings::api_base() . ' отвечает, ' . $took . ( 'none' !== WPTP_Settings::get( 'proxy_type' ) ? ', через прокси' : '' ) );
	}

	/**
	 * Shows the message a post would become in a channel, without sending.
	 *
	 * ## OPTIONS
	 * <post>
	 * : Post ID.
	 *
	 * <channel>
	 * : Channel post ID.
	 *
	 * [--render]
	 * : Render a missing infographic now.
	 */
	public function preview( $args, $assoc ) {
		$post   = get_post( (int) $args[0] );
		$config = WPTP_Channel_Config::get( (int) $args[1] );
		if ( ! $post || ! $config ) {
			WP_CLI::error( 'Нет такой записи или канала.' );
		}
		$message = WPTP_Message_Builder::build( $post, $config, ! empty( $assoc['render'] ) );

		WP_CLI::log( 'Подходит каналу: ' . ( WPTP_Query::matches( $post->ID, $config ) ? 'да' : 'нет' ) );
		WP_CLI::log( 'Статус: ' . $message['status'] . ', формат: ' . $message['format'] . ', вариант: ' . $message['variant'] );
		WP_CLI::log( 'Картинка: ' . ( $message['image'] ? $message['image']['url'] . ' (' . $message['image']['width'] . '×' . $message['image']['height'] . ')' : '—' ) );
		WP_CLI::log( 'Ссылка: ' . $message['link'] );
		WP_CLI::log( 'Символов: ' . WPTP_Template::visible_length( $message['text'] ) );
		WP_CLI::log( "Текст:\n" . $message['text'] );
		if ( $message['notes'] ) {
			WP_CLI::log( 'Заметки: ' . implode( '; ', $message['notes'] ) );
		}
	}

	/**
	 * Sends a post to a channel now (bypasses the channel's rules and schedule).
	 *
	 * ## OPTIONS
	 * <post>
	 * : Post ID.
	 *
	 * <channel>
	 * : Channel post ID.
	 *
	 * [--again]
	 * : Send even if this post already went to this channel.
	 */
	public function send( $args, $assoc ) {
		$result = WPTP_Dispatcher::send_now( (int) $args[0], (int) $args[1], ! empty( $assoc['again'] ) );
		if ( $result['ok'] ) {
			WP_CLI::success( $result['message'] );
			return;
		}
		WP_CLI::error( $result['message'] );
	}

	/**
	 * Shows the queue.
	 *
	 * ## OPTIONS
	 * [--channel=<id>]
	 * : Only this channel.
	 *
	 * [--status=<status>]
	 * : pending | sent | failed | skipped
	 *
	 * [--limit=<n>]
	 * : Default 50.
	 */
	public function queue( $args, $assoc ) {
		$rows = array();
		foreach ( WPTP_Queue::recent( (int) ( $assoc['limit'] ?? 50 ), (int) ( $assoc['channel'] ?? 0 ), (string) ( $assoc['status'] ?? '' ) ) as $row ) {
			$rows[] = array(
				'id'        => $row->id,
				'post'      => $row->post_id,
				'channel'   => $row->channel_id,
				'kind'      => $row->kind,
				'status'    => $row->status,
				'scheduled' => $row->scheduled_at ? wp_date( 'd.m H:i', (int) $row->scheduled_at ) : '',
				'sent'      => $row->sent_at ? wp_date( 'd.m H:i', (int) $row->sent_at ) : '',
				'attempts'  => $row->attempts,
				'url'       => $row->message_url,
				'error'     => mb_substr( (string) $row->error, 0, 80 ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'post', 'channel', 'kind', 'status', 'scheduled', 'sent', 'attempts', 'url', 'error' ) );
	}

	/**
	 * Runs the background tick now: rotation, sending, LLM warm-up.
	 */
	public function tick( $args, $assoc ) {
		WP_CLI::success( WPTP_Scheduler::run_tick() );
	}

	/**
	 * Copies the LLM provider and keys from another plugin of the network.
	 *
	 * ## OPTIONS
	 * --from=<source>
	 * : articles | github_repos | pinfeed
	 *
	 * @subcommand import-llm
	 */
	public function import_llm( $args, $assoc ) {
		$count = WPTP_Integrations::import_llm( (string) ( $assoc['from'] ?? '' ) );
		if ( ! $count ) {
			WP_CLI::error( 'Нечего импортировать: плагин не найден или ключи пусты.' );
		}
		WP_CLI::success( 'Скопировано полей: ' . $count );
	}
}

WP_CLI::add_command( 'tgpost', 'WPTP_CLI' );
