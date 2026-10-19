<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The background tick: plans rotation, sends what is due, then spends spare
 * LLM budget on posts still waiting for their time.
 *
 * Three ways to trigger the same tick, as in the other plugins of the network:
 *  1) WP-Cron on a 10-minute schedule (default, zero setup), plus a one-off
 *     event at the time a freshly queued post is due;
 *  2) an HTTP endpoint guarded by a secret (?tgpost_cron=...);
 *  3) WP-CLI (`wp tgpost tick`) or cron.php for a real crontab.
 * All of them end in run_tick().
 */
class WPTP_Scheduler {

	const CRON_HOOK              = 'wptp_tick_event';
	const DUE_HOOK               = 'wptp_due_event';
	const TICK_RUNNING_TRANSIENT = 'wptp_tick_running';
	const ENDPOINT_THROTTLE      = 'wptp_endpoint_throttle';
	const HTTP_QUERY_PARAM       = 'tgpost_cron';
	const LAST_TICK_OPTION       = 'wptp_last_tick';
	const TICK_LOCK_SECONDS      = 9 * MINUTE_IN_SECONDS;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( self::DUE_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_http_trigger' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'wptp_10_minutes', self::CRON_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$schedules['wptp_10_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Каждые 10 минут (WP TgPost)',
		);
		return $schedules;
	}

	/**
	 * A one-off tick at the time a queued post is due, so a post with no delay
	 * goes out within seconds, not at the next 10-minute tick. Rounded to the
	 * minute: an import of a hundred posts schedules one event, not a hundred.
	 */
	public static function schedule_at( $timestamp ) {
		$minute = (int) ( ceil( max( time(), (int) $timestamp ) / 60 ) * 60 );
		if ( ! wp_next_scheduled( self::DUE_HOOK, array( $minute ) ) ) {
			wp_schedule_single_event( $minute + 5, self::DUE_HOOK, array( $minute ) );
		}
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_unschedule_hook( self::DUE_HOOK );
	}

	public static function maybe_handle_http_trigger() {
		if ( ! isset( $_GET[ self::HTTP_QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$given  = sanitize_text_field( wp_unslash( $_GET[ self::HTTP_QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$secret = (string) WPTP_Settings::get( 'cron_secret', '' );

		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}
		if ( get_transient( self::ENDPOINT_THROTTLE ) ) {
			exit( 'throttled' );
		}
		set_transient( self::ENDPOINT_THROTTLE, 1, MINUTE_IN_SECONDS );

		ignore_user_abort( true );
		$summary = self::run_tick();
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( esc_html( $summary ) );
	}

	public static function run_tick() {
		if ( get_transient( self::TICK_RUNNING_TRANSIENT ) ) {
			return 'тик уже выполняется';
		}
		set_transient( self::TICK_RUNNING_TRANSIENT, time(), self::TICK_LOCK_SECONDS );

		$started  = microtime( true );
		$llm_left = max( 0, (int) WPTP_Settings::get( 'llm_per_tick', 10 ) );
		$planned  = 0;

		try {
			$channels = WPTP_Channel_Config::active();
			foreach ( $channels as $config ) {
				if ( WPTP_Dispatcher::plan_rotation( $config ) ) {
					++$planned;
				}
			}

			$stats  = WPTP_Dispatcher::send_due( max( 1, (int) WPTP_Settings::get( 'sends_per_tick', 20 ) ), $llm_left );
			$warmed = WPTP_Dispatcher::prewarm( $llm_left );
		} finally {
			delete_transient( self::TICK_RUNNING_TRANSIENT );
		}

		$summary = sprintf(
			'Каналов: %d, ротация: %d, отправлено: %d, пропущено: %d, ошибок: %d, ждут модель/картинку: %d, подготовлено заранее: %d, %.1f с',
			count( $channels ),
			$planned,
			$stats['sent'],
			$stats['skipped'],
			$stats['failed'],
			$stats['waiting'],
			$warmed,
			microtime( true ) - $started
		);
		update_option(
			self::LAST_TICK_OPTION,
			array(
				'time'    => time(),
				'summary' => $summary,
			),
			false
		);
		if ( $stats['sent'] || $stats['failed'] || $stats['skipped'] ) {
			WPTP_Log::add( 'Тик: ' . $summary );
		}
		return $summary;
	}

	/** Ready-to-paste commands for an external cron, shown on the settings screen. */
	public static function get_trigger_commands() {
		$url = add_query_arg( self::HTTP_QUERY_PARAM, WPTP_Settings::get( 'cron_secret', '' ), home_url( '/' ) );
		return array(
			'curl' => '*/5 * * * * curl -s "' . $url . '" > /dev/null 2>&1',
			'wget' => '*/5 * * * * wget -q -O - "' . $url . '" > /dev/null 2>&1',
			'php'  => '*/5 * * * * php ' . WPTP_PLUGIN_DIR . 'cron.php > /dev/null 2>&1',
		);
	}
}
