<?php
/**
 * Removes everything the plugin created: its options (bots and their tokens
 * included), the channel posts (plugin configuration, not site content), the
 * queue table, the per-post meta and the generated images. Messages already
 * posted to Telegram are not touched.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'wptp_settings', 'wptp_bots', 'wptp_log', 'wptp_infographic_presets', 'wptp_last_tick', 'wptp_palette_cursor', 'wptp_db_ver' ) as $option ) {
	delete_option( $option );
}

// Tick lock, LLM back-off markers, notices.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wptp\_%' OR option_name LIKE '\_transient\_timeout\_wptp\_%'" ); // phpcs:ignore

$channels = get_posts(
	array(
		'post_type'      => 'tgchannel',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $channels as $channel_id ) {
	wp_delete_post( $channel_id, true );
}

foreach ( array( '_wptp_llm', '_wptp_theses', '_wptp_theses_manual', '_wptp_title', '_wptp_text', '_wptp_exclude', '_wptp_palette' ) as $key ) {
	delete_post_meta_by_key( $key );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wptp_queue" ); // phpcs:ignore

wp_clear_scheduled_hook( 'wptp_tick_event' );
wp_unschedule_hook( 'wptp_due_event' );

// Generated images.
$upload = wp_upload_dir();
$base   = trailingslashit( $upload['basedir'] ) . 'wptp-images';
if ( is_dir( $base ) ) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
	}
	@rmdir( $base ); // phpcs:ignore
}
