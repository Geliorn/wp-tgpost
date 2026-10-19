<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin-wide settings in one option array. Per-channel settings live as post
 * meta on the `tgchannel` post (see WPTP_Channel_Config), bots in their own
 * option (WPTP_Bots, not autoloaded — tokens are secrets), infographic
 * presets in theirs (WPTP_Infographic_Presets), so a tuned set can be
 * exported between sites of the network.
 */
class WPTP_Settings {

	const OPTION_KEY = 'wptp_settings';

	public static function defaults() {
		return array(
			// ---------------------------------------------------------- LLM
			// Same five OpenAI-compatible providers as llm-agent-articles /
			// llm-agent-github-repos / wp-pinfeed; keys can be imported.
			'llm_provider'          => 'deepseek',
			'yandex_api_key'        => '',
			'yandex_folder_id'      => '',
			'yandex_model'          => 'deepseek-v32/latest',
			'deepseek_api_key'      => '',
			'deepseek_model'        => 'deepseek-chat',
			'qwen_api_key'          => '',
			'qwen_model'            => 'qwen-plus',
			'qwen_base_url'         => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
			'timeweb_agent_id'      => '',
			'timeweb_token'         => '',
			'timeweb_proxy_source'  => '',
			'openrouter_api_key'    => '',
			'openrouter_model'      => 'deepseek/deepseek-chat',
			'llm_timeout'           => 60,
			'llm_per_tick'          => 10,   // LLM calls per background tick, across all channels

			// ------------------------------------------- connection to Telegram
			// Applies to Bot API requests only, never to the rest of the site.
			'api_base'              => 'https://api.telegram.org',
			'proxy_type'            => 'none', // none | http | socks5
			'proxy_host'            => '',
			'proxy_port'            => '',
			'proxy_user'            => '',
			'proxy_pass'            => '',
			'api_timeout'           => 30,

			// ---------------------------------------------------------- sending
			'sends_per_tick'        => 20,     // messages per tick, across all channels
			'photo_upload'          => 'auto', // auto | always | never — send the picture as a file, not a URL

			// ----------------------------------------------------------- images
			'caption_font_path'     => '',
			'images_per_tick'       => 30,

			'seo_plugin'            => 'auto', // auto | yoast | rankmath | none
			'cron_secret'           => '',
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_secret' ) );
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	public static function update( array $values ) {
		$all = self::get_all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function maybe_generate_secret() {
		if ( '' === (string) self::get( 'cron_secret' ) ) {
			self::regenerate_secret();
		}
	}

	public static function regenerate_secret() {
		$secret = bin2hex( random_bytes( 16 ) );
		self::update( array( 'cron_secret' => $secret ) );
		return $secret;
	}

	public static function proxy_types() {
		return array(
			'none'   => 'Без прокси',
			'http'   => 'HTTP(S)-прокси',
			'socks5' => 'SOCKS5 (DNS через прокси)',
		);
	}

	/** Bot API base URL without a trailing slash. */
	public static function api_base() {
		$base = trim( (string) self::get( 'api_base', '' ) );
		return '' === $base ? 'https://api.telegram.org' : untrailingslashit( $base );
	}

	public static function llm_providers() {
		return array(
			'yandex'     => 'Yandex Foundation Models',
			'deepseek'   => 'DeepSeek напрямую',
			'qwen'       => 'Qwen (Alibaba DashScope)',
			'timeweb'    => 'Timeweb (TWC AI API)',
			'openrouter' => 'OpenRouter',
		);
	}

	/** Every key that belongs to the LLM block — what "import keys" copies. */
	public static function llm_keys() {
		return array(
			'llm_provider',
			'yandex_api_key',
			'yandex_folder_id',
			'yandex_model',
			'deepseek_api_key',
			'deepseek_model',
			'qwen_api_key',
			'qwen_model',
			'qwen_base_url',
			'timeweb_agent_id',
			'timeweb_token',
			'timeweb_proxy_source',
			'openrouter_api_key',
			'openrouter_model',
		);
	}

	/**
	 * OpenAI-compatible provider presets — endpoint + auth + model resolution.
	 * Identical to LAA_Settings::llm_provider_config(), kept per-plugin on
	 * purpose: each plugin of the network must work on its own.
	 */
	public static function llm_provider_config( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return array(
					'provider' => 'yandex',
					'endpoint' => 'https://ai.api.cloud.yandex.net/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Api-Key ' . $s['yandex_api_key'],
						'x-folder-id'   => $s['yandex_folder_id'],
					),
					'model'    => 'gpt://' . $s['yandex_folder_id'] . '/' . ltrim( $s['yandex_model'], '/' ),
				);
			case 'timeweb':
				return array(
					'provider' => 'timeweb',
					'endpoint' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/' . rawurlencode( $s['timeweb_agent_id'] ) . '/v1/chat/completions',
					'headers'  => array(
						'Authorization'  => 'Bearer ' . $s['timeweb_token'],
						'x-proxy-source' => $s['timeweb_proxy_source'],
					),
					'model'    => 'gpt-4', // ignored by Timeweb — the agent's own model is used.
				);
			case 'openrouter':
				return array(
					'provider' => 'openrouter',
					'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['openrouter_api_key'],
					),
					'model'    => $s['openrouter_model'],
				);
			case 'qwen':
				return array(
					'provider' => 'qwen',
					'endpoint' => rtrim( $s['qwen_base_url'], '/' ) . '/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['qwen_api_key'],
					),
					'model'    => $s['qwen_model'],
				);
			case 'deepseek':
			default:
				return array(
					'provider' => 'deepseek',
					'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['deepseek_api_key'],
					),
					'model'    => $s['deepseek_model'],
				);
		}
	}

	/** True when the configured LLM provider actually has its credentials filled in. */
	public static function llm_ready( $provider = null ) {
		$s        = self::get_all();
		$provider = $provider && 'inherit' !== $provider ? $provider : $s['llm_provider'];

		switch ( $provider ) {
			case 'yandex':
				return '' !== $s['yandex_api_key'] && '' !== $s['yandex_folder_id'];
			case 'timeweb':
				return '' !== $s['timeweb_agent_id'] && '' !== $s['timeweb_token'];
			case 'openrouter':
				return '' !== $s['openrouter_api_key'];
			case 'qwen':
				return '' !== $s['qwen_api_key'];
			default:
				return '' !== $s['deepseek_api_key'];
		}
	}

	/**
	 * Which SEO plugin's meta to read {seo_title}/{seo_description} from.
	 * 'auto' picks whichever is active, so the same channel config works
	 * across sites of the network that run different SEO plugins.
	 */
	public static function seo_plugin() {
		$value = (string) self::get( 'seo_plugin', 'auto' );
		if ( 'auto' !== $value ) {
			return $value;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'none';
	}
}
