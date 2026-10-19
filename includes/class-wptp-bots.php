<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bots: one token, any number of channels. Stored in one option that is not
 * autoloaded (tokens are secrets and only the tick and the admin need them):
 *
 *   id => array( name, token, username, tg_id, checked_at, check_error )
 *
 * A channel stores the bot id, so the token can be replaced (e.g. after
 * /revoke in @BotFather) without touching the channels.
 */
class WPTP_Bots {

	const OPTION_KEY = 'wptp_bots';

	/** @return array<string,array> */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $bot ) {
			if ( is_array( $bot ) ) {
				$out[ (string) $id ] = wp_parse_args(
					$bot,
					array(
						'name'        => '',
						'token'       => '',
						'username'    => '',
						'tg_id'       => 0,
						'checked_at'  => 0,
						'check_error' => '',
					)
				);
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return $all[ (string) $id ] ?? null;
	}

	/** The only bot, when there is exactly one — the default for a new channel. */
	public static function default_id() {
		$all = self::all();
		return 1 === count( $all ) ? (string) key( $all ) : '';
	}

	/** id => "Name (@username)" for selects. */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $id => $bot ) {
			$out[ $id ] = self::label( $bot );
		}
		return $out;
	}

	public static function label( array $bot ) {
		$name = '' !== $bot['name'] ? $bot['name'] : 'Бот';
		return $name . ( '' !== $bot['username'] ? ' (@' . $bot['username'] . ')' : '' );
	}

	/** @return WPTP_Bot_API|null */
	public static function api( $id ) {
		$bot = self::get( $id );
		return $bot && '' !== $bot['token'] ? new WPTP_Bot_API( $bot['token'] ) : null;
	}

	/**
	 * Adds or updates a bot and checks the token with getMe.
	 *
	 * @param string $id    '' for a new bot.
	 * @param string $token '' keeps the stored token (the form never shows it).
	 * @return array{id:string,ok:bool,error:string}
	 */
	public static function save( $id, $name, $token ) {
		$all   = self::all();
		$id    = sanitize_key( (string) $id );
		$token = trim( (string) $token );

		if ( '' !== $token && ! preg_match( '/^\d+:[A-Za-z0-9_-]{30,}$/', $token ) ) {
			return array( 'id' => isset( $all[ $id ] ) ? $id : '', 'ok' => false, 'error' => 'токен не похож на токен бота (вид: 123456789:AA…)' );
		}
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			if ( '' === $token ) {
				return array( 'id' => '', 'ok' => false, 'error' => 'нужен токен от @BotFather' );
			}
			$id         = 'bot-' . substr( md5( $token . microtime( true ) ), 0, 8 );
			$all[ $id ] = array(
				'name'        => '',
				'token'       => '',
				'username'    => '',
				'tg_id'       => 0,
				'checked_at'  => 0,
				'check_error' => '',
			);
		}
		if ( '' !== $token ) {
			$all[ $id ]['token'] = $token;
		}
		$all[ $id ]['name'] = sanitize_text_field( (string) $name );
		update_option( self::OPTION_KEY, $all, false );

		$check = self::check( $id );
		return array( 'id' => $id, 'ok' => $check['ok'], 'error' => $check['error'] );
	}

	/**
	 * getMe: confirms the token and stores the bot's username and id (the id
	 * is what getChatMember needs to check the bot's rights in a channel).
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function check( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return array( 'ok' => false, 'error' => 'бот не найден' );
		}
		$api = new WPTP_Bot_API( $all[ $id ]['token'] );
		$me  = $api->get_me();

		$all[ $id ]['checked_at']  = time();
		$all[ $id ]['check_error'] = is_array( $me ) ? '' : $api->error_message();
		if ( is_array( $me ) ) {
			$all[ $id ]['username'] = (string) ( $me['username'] ?? '' );
			$all[ $id ]['tg_id']    = (int) ( $me['id'] ?? 0 );
			if ( '' === $all[ $id ]['name'] ) {
				$all[ $id ]['name'] = (string) ( $me['first_name'] ?? '' );
			}
		}
		update_option( self::OPTION_KEY, $all, false );

		return array(
			'ok'    => is_array( $me ),
			'error' => $all[ $id ]['check_error'],
		);
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ (string) $id ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Chats the bot has been added to, from getUpdates (my_chat_member and
	 * posts it can see). Telegram keeps updates for 24 hours, so this finds
	 * channels the bot was added to recently.
	 *
	 * @return array{ok:bool,error:string,chats:array}
	 */
	public static function discover( $id ) {
		$api = self::api( $id );
		if ( ! $api ) {
			return array( 'ok' => false, 'error' => 'бот не найден', 'chats' => array() );
		}
		$updates = $api->get_updates();
		if ( ! is_array( $updates ) ) {
			$error = $api->error_message();
			if ( false !== stripos( $error, 'webhook' ) ) {
				$error .= ' — у бота настроен webhook, getUpdates недоступен; укажите @username или ID канала вручную';
			}
			return array( 'ok' => false, 'error' => $error, 'chats' => array() );
		}

		$chats = array();
		foreach ( $updates as $update ) {
			foreach ( array( 'my_chat_member', 'channel_post', 'message' ) as $kind ) {
				if ( empty( $update[ $kind ]['chat'] ) ) {
					continue;
				}
				$chat = $update[ $kind ]['chat'];
				$key  = (string) $chat['id'];
				$row  = array(
					'id'       => $key,
					'title'    => (string) ( $chat['title'] ?? trim( ( $chat['first_name'] ?? '' ) . ' ' . ( $chat['last_name'] ?? '' ) ) ),
					'username' => (string) ( $chat['username'] ?? '' ),
					'type'     => (string) ( $chat['type'] ?? '' ),
					'status'   => $chats[ $key ]['status'] ?? '',
				);
				if ( 'my_chat_member' === $kind ) {
					$row['status'] = (string) ( $update[ $kind ]['new_chat_member']['status'] ?? '' );
				}
				$chats[ $key ] = $row;
			}
		}
		return array( 'ok' => true, 'error' => '', 'chats' => array_values( $chats ) );
	}
}
