<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telegram Bot API client: one instance per bot token.
 *
 * Every call goes to <api_base>/bot<token>/<method> — the base is
 * https://api.telegram.org or whatever the settings name instead (a self-hosted
 * telegram-bot-api server, a reverse proxy). The HTTP(S)/SOCKS5 proxy from the
 * settings is applied to these requests only, through the http_api_curl hook,
 * so the rest of the site keeps its own network path.
 *
 * Errors never carry the token: it is part of the URL, and cURL/WP errors may
 * echo the URL back — mask() runs over every message that leaves this class.
 */
class WPTP_Bot_API {

	/** @var string */
	private $token;

	/** @var array{code:int,message:string,retry_after:int,migrate_to:string} */
	private $last_error = array(
		'code'        => 0,
		'message'     => '',
		'retry_after' => 0,
		'migrate_to'  => '',
	);

	/** Proxy hook is added once per request. */
	private static $hooked = false;

	public function __construct( $token ) {
		$this->token = trim( (string) $token );
	}

	public static function init() {
		if ( ! self::$hooked ) {
			add_action( 'http_api_curl', array( __CLASS__, 'apply_proxy' ), 10, 3 );
			self::$hooked = true;
		}
	}

	/** @return array{code:int,message:string,retry_after:int,migrate_to:string} */
	public function last_error() {
		return $this->last_error;
	}

	public function error_message() {
		return $this->last_error['message'];
	}

	// ------------------------------------------------------------- methods

	public function get_me() {
		return $this->call( 'getMe' );
	}

	public function get_chat( $chat_id ) {
		return $this->call( 'getChat', array( 'chat_id' => $chat_id ) );
	}

	public function get_chat_member( $chat_id, $user_id ) {
		return $this->call(
			'getChatMember',
			array(
				'chat_id' => $chat_id,
				'user_id' => (int) $user_id,
			)
		);
	}

	/**
	 * Chats the bot was added to / removed from. Works only while the bot has
	 * no webhook; without an offset it doesn't consume anything.
	 */
	public function get_updates() {
		return $this->call(
			'getUpdates',
			array(
				'allowed_updates' => wp_json_encode( array( 'my_chat_member', 'channel_post', 'message' ) ),
				'limit'           => 100,
				'timeout'         => 0,
			)
		);
	}

	/**
	 * @param array $params chat_id, text, parse_mode, ...
	 */
	public function send_message( array $params ) {
		return $this->call( 'sendMessage', $params );
	}

	/**
	 * @param array  $params chat_id, caption, parse_mode, ...
	 * @param string $photo  URL, or a local path when $upload is true.
	 * @param bool   $upload Send the file itself (multipart) instead of a URL.
	 */
	public function send_photo( array $params, $photo, $upload = false ) {
		if ( ! $upload ) {
			$params['photo'] = $photo;
			return $this->call( 'sendPhoto', $params );
		}
		return $this->call( 'sendPhoto', $params, array( 'photo' => $photo ) );
	}

	// ----------------------------------------------------------------- core

	/**
	 * @param string $method
	 * @param array  $params Scalars; arrays are JSON-encoded (reply_markup, ...).
	 * @param array  $files  field => local path, sent as multipart.
	 * @return mixed|null    `result` of the response, or null (see last_error()).
	 */
	public function call( $method, array $params = array(), array $files = array() ) {
		$this->last_error = array(
			'code'        => 0,
			'message'     => '',
			'retry_after' => 0,
			'migrate_to'  => '',
		);

		if ( '' === $this->token ) {
			return $this->fail( 0, 'не задан токен бота' );
		}
		$proxy_problem = self::proxy_problem();
		if ( '' !== $proxy_problem ) {
			return $this->fail( 0, $proxy_problem );
		}

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				$params[ $key ] = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$params[ $key ] = $value ? 'true' : 'false';
			} elseif ( null === $value ) {
				unset( $params[ $key ] );
			}
		}

		$args = array(
			'timeout' => max( 5, (int) WPTP_Settings::get( 'api_timeout', 30 ) ),
		);

		if ( $files ) {
			$boundary = 'wptp' . wp_generate_password( 24, false );
			$body     = '';
			foreach ( $params as $key => $value ) {
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $key . "\"\r\n\r\n";
				$body .= (string) $value . "\r\n";
			}
			foreach ( $files as $field => $path ) {
				if ( ! is_readable( $path ) ) {
					return $this->fail( 0, 'файл не читается: ' . basename( $path ) );
				}
				$type  = wp_check_filetype( $path );
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . basename( $path ) . "\"\r\n";
				$body .= 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) . "\r\n\r\n";
				$body .= (string) file_get_contents( $path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$body .= '--' . $boundary . "--\r\n";

			$args['headers'] = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
			$args['body']    = $body;
			$args['timeout'] = max( $args['timeout'], 60 );
		} else {
			$args['body'] = $params;
		}

		$response = wp_remote_post( $this->url( $method ), $args );

		if ( is_wp_error( $response ) ) {
			return $this->fail( 0, 'HTTP: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( ! is_array( $body ) ) {
			return $this->fail( $code, 'HTTP ' . $code . ': не JSON — ' . mb_substr( wp_strip_all_tags( $raw ), 0, 200 ) );
		}
		if ( empty( $body['ok'] ) ) {
			$this->last_error['retry_after'] = (int) ( $body['parameters']['retry_after'] ?? 0 );
			$this->last_error['migrate_to']  = (string) ( $body['parameters']['migrate_to_chat_id'] ?? '' );
			return $this->fail( (int) ( $body['error_code'] ?? $code ), (string) ( $body['description'] ?? 'ошибка без описания' ) );
		}

		return $body['result'] ?? true;
	}

	private function url( $method ) {
		return WPTP_Settings::api_base() . '/bot' . $this->token . '/' . $method;
	}

	private function fail( $code, $message ) {
		$this->last_error['code']    = (int) $code;
		$this->last_error['message'] = $this->mask( $message );
		return null;
	}

	public function mask( $text ) {
		$text = (string) $text;
		if ( '' !== $this->token ) {
			$text = str_replace( array( $this->token, rawurlencode( $this->token ) ), self::masked_token( $this->token ), $text );
		}
		return $text;
	}

	/** "123456:AAH…xYz" — enough to tell bots apart, useless to anyone else. */
	public static function masked_token( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 12 ) {
			return '***';
		}
		$colon = strpos( $token, ':' );
		$head  = false === $colon ? substr( $token, 0, 4 ) : substr( $token, 0, $colon + 4 );
		return $head . '…' . substr( $token, -3 );
	}

	// ---------------------------------------------------------------- proxy

	/** '' when the configured proxy can be used, otherwise why not. */
	public static function proxy_problem() {
		$type = (string) WPTP_Settings::get( 'proxy_type', 'none' );
		if ( 'none' === $type ) {
			return '';
		}
		if ( '' === trim( (string) WPTP_Settings::get( 'proxy_host', '' ) ) ) {
			return 'прокси включён, но не задан адрес';
		}
		// http_api_curl only fires on the cURL transport.
		if ( ! function_exists( 'curl_init' ) ) {
			return 'прокси работает только с PHP cURL, а расширение не установлено';
		}
		if ( 'socks5' === $type && ! defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ) {
			return 'эта сборка cURL не поддерживает SOCKS5';
		}
		return '';
	}

	/**
	 * http_api_curl: point Bot API requests (and only them) at the proxy.
	 *
	 * @param resource|CurlHandle $handle
	 * @param array               $parsed_args
	 * @param string              $url
	 */
	public static function apply_proxy( $handle, $parsed_args = array(), $url = '' ) {
		$type = (string) WPTP_Settings::get( 'proxy_type', 'none' );
		if ( 'none' === $type || '' === (string) $url || 0 !== strpos( (string) $url, WPTP_Settings::api_base() . '/bot' ) ) {
			return;
		}
		$host = trim( (string) WPTP_Settings::get( 'proxy_host', '' ) );
		if ( '' === $host ) {
			return;
		}
		// "socks5://host" or "http://host" typed into the host field is fine too.
		$host = preg_replace( '#^[a-z0-9]+://#i', '', $host );
		$port = (int) WPTP_Settings::get( 'proxy_port', 0 );

		curl_setopt( $handle, CURLOPT_PROXY, $host ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $port > 0 ) {
			curl_setopt( $handle, CURLOPT_PROXYPORT, $port ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		// HTTPS through an HTTP proxy is tunnelled (CONNECT) by cURL on its own.
		curl_setopt( $handle, CURLOPT_PROXYTYPE, 'socks5' === $type ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$user = (string) WPTP_Settings::get( 'proxy_user', '' );
		if ( '' !== $user ) {
			curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $user . ':' . (string) WPTP_Settings::get( 'proxy_pass', '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
