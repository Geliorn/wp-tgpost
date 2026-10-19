<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rolling human-readable log (last ~300 entries) in one option, plus a plain
 * file in uploads — so the direct-PHP-CLI trigger leaves a trace too.
 * Same shape as LAA_Log / LAGR_Log, kept per-plugin on purpose.
 */
class WPTP_Log {

	const OPTION_KEY  = 'wptp_log';
	const MAX_ENTRIES = 300;

	public static function add( $message, $level = 'info' ) {
		$entries   = get_option( self::OPTION_KEY, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = array(
			'time'    => time(),
			'level'   => $level,
			'message' => (string) $message,
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION_KEY, $entries, false );

		$upload_dir = wp_upload_dir();
		$log_file   = trailingslashit( $upload_dir['basedir'] ) . 'wptp-tgpost.log';
		$line       = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [' . $level . '] ' . $message . "\n";
		@file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	public static function error( $message ) {
		self::add( $message, 'error' );
	}

	public static function get_recent( $limit = 300 ) {
		$entries = get_option( self::OPTION_KEY, array() );
		$entries = is_array( $entries ) ? $entries : array();
		return array_slice( array_reverse( $entries ), 0, $limit );
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
