<?php
/**
 * Standalone cron entry point — loads wp-load.php directly, bypassing WP-CLI.
 * The third trigger of the background tick, for hosts where the site can't
 * curl itself. Same as cron.php in wp-pinfeed and llm-agent-articles.
 *
 * Usage: php /path/to/wp-content/plugins/wp-tgpost/cron.php
 */

require_once __DIR__ . '/../../../wp-load.php';

if ( 'cli' !== php_sapi_name() ) {
	// Hit directly over HTTP rather than via the home-URL query param —
	// still requires the secret.
	$provided = isset( $_GET['tgpost_cron'] ) ? (string) $_GET['tgpost_cron'] : ''; // phpcs:ignore
	$secret   = (string) WPTP_Settings::get( 'cron_secret' );

	if ( '' === $secret || ! hash_equals( $secret, $provided ) ) {
		status_header( 403 );
		exit( 'forbidden' );
	}
	header( 'Content-Type: text/plain' );
}

echo WPTP_Scheduler::run_tick() . "\n"; // phpcs:ignore
