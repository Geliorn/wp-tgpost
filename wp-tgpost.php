<?php
/**
 * Plugin Name: WP TgPost
 * Description: Автопубликация записей в Telegram-каналы через Bot API. Один бот — сколько угодно каналов; у каждого канала свои условия отбора по таксономиям, шаблон сообщения, публикация при выходе записи и ротация старых записей без повторов, тихие часы, интервал и дневной лимит. Картинка — из записи или генерируемая инфографика (пресеты как в WP PinFeed), анонс может писать LLM. HTTP(S)/SOCKS5-прокси для Bot API. Самодостаточен — без ACF/CPT UI.
 * Version: 0.1.1
 * Author: llm-agent.ru
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wptp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPTP_VERSION', '0.1.1' );
define( 'WPTP_PLUGIN_FILE', __FILE__ );
define( 'WPTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-settings.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-log.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-bot-api.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-bots.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-cpt.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-queue.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-integrations.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-channel-config.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-query.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-template.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-llm-client.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-llm-text.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-post-meta.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-image-util.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-image-composer.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-infographic-presets.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-theme-palette.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-infographic-composer.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-infographic.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-image-resolver.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-message-builder.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-dispatcher.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-scheduler.php';
require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPTP_PLUGIN_DIR . 'includes/class-wptp-cli.php';
}

/**
 * Boot — every class hooks itself in its own init(), same convention as the
 * other plugins of the network (wp-pinfeed, llm-agent-articles, …).
 */
function wptp_boot() {
	WPTP_Settings::init();
	WPTP_Bot_API::init();
	WPTP_Integrations::init();
	WPTP_CPT::init();
	WPTP_Queue::init();
	WPTP_Channel_Config::init();
	WPTP_Query::init();
	WPTP_Post_Meta::init();
	WPTP_Message_Builder::init();
	WPTP_Dispatcher::init();
	WPTP_Scheduler::init();
	WPTP_Admin::init();
}
add_action( 'plugins_loaded', 'wptp_boot' );

function wptp_activate() {
	WPTP_Queue::install();
	WPTP_CPT::register_post_types();
	WPTP_Infographic_Presets::ensure_defaults();
	WPTP_Scheduler::activate();
}
register_activation_hook( __FILE__, 'wptp_activate' );

function wptp_deactivate() {
	WPTP_Scheduler::deactivate();
}
register_deactivation_hook( __FILE__, 'wptp_deactivate' );
