<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One HTTP client for all five OpenAI-compatible chat/completions providers
 * (Yandex, DeepSeek, Qwen, Timeweb TWC AI, OpenRouter). Same as LAA_LLM_Client
 * in llm-agent-articles, kept per-plugin so this plugin works on its own:
 * retries on 429/5xx, tolerant JSON unwrapping and an error channel, so a
 * failed message can report *why* in the log.
 */
class WPTP_LLM_Client {

	/** @var array */
	private $config;

	/** @var string */
	private $last_error = '';

	public function __construct( $provider = null ) {
		$this->config = WPTP_Settings::llm_provider_config( $provider );
	}

	public function last_error() {
		return $this->last_error;
	}

	public function provider() {
		return $this->config['provider'];
	}

	/**
	 * Ask for a JSON object and get it decoded, or null.
	 *
	 * @param string $system       System prompt.
	 * @param string $user         User prompt.
	 * @param int    $max_tokens   Hard cap on the response.
	 * @param float  $temperature  Sampling temperature.
	 */
	public function json( $system, $user, $max_tokens = 2000, $temperature = 0.7 ) {
		$content = $this->chat( $system, $user, $max_tokens, $temperature, true );
		if ( null === $content ) {
			return null;
		}

		$parsed = self::extract_json( $content );
		if ( ! is_array( $parsed ) ) {
			$this->last_error = 'Модель вернула не JSON: ' . mb_substr( $content, 0, 200 );
			return null;
		}
		return $parsed;
	}

	/** Free-form text/HTML answer. */
	public function text( $system, $user, $max_tokens = 2000, $temperature = 0.6 ) {
		return $this->chat( $system, $user, $max_tokens, $temperature, false );
	}

	private function chat( $system, $user, $max_tokens, $temperature, $want_json ) {
		$payload = array(
			'model'       => $this->config['model'],
			'max_tokens'  => (int) $max_tokens,
			'temperature' => (float) $temperature,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);

		// DeepSeek and OpenRouter honour response_format; the other two ignore
		// an unknown key rather than erroring, so it's safe to always send it
		// on the JSON path — it measurably cuts "here is your JSON:" preambles.
		if ( $want_json && in_array( $this->config['provider'], array( 'deepseek', 'openrouter' ), true ) ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		return $this->call( $payload, 2 );
	}

	private function call( array $payload, $attempts_left ) {
		$timeout = max( 15, (int) WPTP_Settings::get( 'llm_timeout', 60 ) );

		$response = wp_remote_post(
			$this->config['endpoint'],
			array(
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$this->config['headers']
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = 'HTTP: ' . $response->get_error_message();
			return $attempts_left > 0 ? $this->call( $payload, $attempts_left - 1 ) : null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$this->last_error = 'HTTP ' . $code . ': ' . mb_substr( wp_strip_all_tags( $raw ), 0, 300 );
			// 4xx other than 429 won't fix itself on retry — a bad key or a
			// bad model name just burns the remaining attempts.
			$retryable = ( 429 === $code || $code >= 500 );
			return ( $retryable && $attempts_left > 0 ) ? $this->call( $payload, $attempts_left - 1 ) : null;
		}

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			$this->last_error = 'Пустой ответ модели: ' . mb_substr( wp_strip_all_tags( $raw ), 0, 300 );
			return $attempts_left > 0 ? $this->call( $payload, $attempts_left - 1 ) : null;
		}

		$this->last_error = '';
		return trim( $body['choices'][0]['message']['content'] );
	}

	/**
	 * Models wrap JSON in ```json fences despite instructions, and sometimes
	 * put a sentence before it. Strip the fence, then fall back to the widest
	 * {...} / [...] span in the string.
	 */
	public static function extract_json( $content ) {
		$content = trim( (string) $content );

		if ( 0 === strpos( $content, '```' ) ) {
			$content = preg_replace( '/^```[a-zA-Z]*\s*/', '', $content );
			$content = preg_replace( '/```\s*$/', '', $content );
			$content = trim( $content );
		}

		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		foreach ( array( array( '{', '}' ), array( '[', ']' ) ) as $pair ) {
			$start = strpos( $content, $pair[0] );
			$end   = strrpos( $content, $pair[1] );
			if ( false !== $start && false !== $end && $end > $start ) {
				$decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return null;
	}

	/** Strips a ```html / ```markdown fence off a free-form answer. */
	public static function strip_fence( $content ) {
		$content = trim( (string) $content );
		if ( 0 === strpos( $content, '```' ) ) {
			$content = preg_replace( '/^```[a-zA-Z]*\s*/', '', $content );
			$content = preg_replace( '/```\s*$/', '', $content );
		}
		return trim( $content );
	}
}
