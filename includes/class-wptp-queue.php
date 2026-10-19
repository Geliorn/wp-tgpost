<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The send queue: one row per (post, channel), in its own table.
 *
 * A table and not post meta: the tick needs "what is due now, across all
 * channels", channels need "what did I send today", rotation needs "posts
 * never sent here" — all set queries that meta can't answer cheaply.
 *
 * No repeats, by construction: `dedupe_key` is UNIQUE and is "p<post>:c<channel>"
 * for both a fresh publication and rotation, so a post reaches a channel at
 * most once. The only way around it is a deliberate manual resend, which
 * gets its own key ("m<post>:c<channel>:<time>").
 *
 * Statuses: pending (waiting for its time, its gates or a retry) → sent;
 * failed (gave up after retries — can be retried by hand); skipped (no longer
 * matches the channel, excluded, no picture and the channel says skip).
 * Times are unix timestamps.
 */
class WPTP_Queue {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'wptp_db_ver';

	const PENDING = 'pending';
	const SENT    = 'sent';
	const FAILED  = 'failed';
	const SKIPPED = 'skipped';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wptp_queue';
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				channel_id bigint(20) unsigned NOT NULL,
				kind varchar(16) NOT NULL DEFAULT 'new',
				dedupe_key varchar(64) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at bigint(20) unsigned NOT NULL DEFAULT 0,
				scheduled_at bigint(20) unsigned NOT NULL DEFAULT 0,
				next_try_at bigint(20) unsigned NOT NULL DEFAULT 0,
				sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				chat_id varchar(64) NOT NULL DEFAULT '',
				message_id bigint(20) NOT NULL DEFAULT 0,
				message_url varchar(255) NOT NULL DEFAULT '',
				variant varchar(64) NOT NULL DEFAULT '',
				error text NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY status_time (status,scheduled_at),
				KEY channel_status (channel_id,status,sent_at),
				KEY post_id (post_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function key( $post_id, $channel_id ) {
		return 'p' . (int) $post_id . ':c' . (int) $channel_id;
	}

	// -------------------------------------------------------------- writing

	/**
	 * Queues a post for a channel, once.
	 *
	 * @param string $kind new | rotation | manual
	 * @return int|false Row id, or false when the post already has a sent or
	 *                   pending row for this channel.
	 */
	public static function enqueue( $post_id, $channel_id, $kind, $scheduled_at = null ) {
		global $wpdb;
		$table        = self::table();
		$key          = self::key( $post_id, $channel_id );
		$scheduled_at = null === $scheduled_at ? time() : (int) $scheduled_at;

		$row = self::get_by_key( $key );
		if ( $row ) {
			// A post that never actually went out may be queued again: a
			// failed send retried by hand, a skipped one sent by hand.
			if ( in_array( $row->status, array( self::SENT, self::PENDING ), true ) || 'manual' !== $kind ) {
				return false;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'kind'         => $kind,
					'status'       => self::PENDING,
					'scheduled_at' => $scheduled_at,
					'next_try_at'  => 0,
					'attempts'     => 0,
					'error'        => '',
				),
				array( 'id' => (int) $row->id )
			);
			return (int) $row->id;
		}

		// INSERT IGNORE: a concurrent request may have queued it a moment ago.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (post_id, channel_id, kind, dedupe_key, status, created_at, scheduled_at) VALUES (%d, %d, %s, %s, %s, %d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $post_id,
				(int) $channel_id,
				$kind,
				$key,
				self::PENDING,
				time(),
				$scheduled_at
			)
		);
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/** A deliberate repeat of an already sent post — its own key, never deduplicated. */
	public static function enqueue_resend( $post_id, $channel_id ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'post_id'      => (int) $post_id,
				'channel_id'   => (int) $channel_id,
				'kind'         => 'manual',
				'dedupe_key'   => 'm' . (int) $post_id . ':c' . (int) $channel_id . ':' . microtime( true ),
				'status'       => self::PENDING,
				'created_at'   => time(),
				'scheduled_at' => time(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function mark_sent( $id, $chat_id, $message_id, $message_url, $variant ) {
		self::update(
			$id,
			array(
				'status'      => self::SENT,
				'sent_at'     => time(),
				'chat_id'     => (string) $chat_id,
				'message_id'  => (int) $message_id,
				'message_url' => (string) $message_url,
				'variant'     => (string) $variant,
				'error'       => '',
			)
		);
	}

	public static function mark_skipped( $id, $reason ) {
		self::update(
			$id,
			array(
				'status' => self::SKIPPED,
				'error'  => mb_substr( (string) $reason, 0, 1000 ),
			)
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** A deleted post or channel takes its rows along; messages in Telegram stay. */
	public static function on_delete_post( $post_id ) {
		global $wpdb;
		$table = self::table();
		if ( WPTP_CPT::CHANNEL === get_post_type( $post_id ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE channel_id = %d", (int) $post_id ) ); // phpcs:ignore
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", (int) $post_id ) ); // phpcs:ignore
		}
	}

	// -------------------------------------------------------------- reading

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore
	}

	public static function get_by_key( $key ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s", (string) $key ) ); // phpcs:ignore
	}

	/** Due rows, oldest first. */
	public static function due( $limit = 200 ) {
		global $wpdb;
		$table = self::table();
		$now   = time();
		return $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND scheduled_at <= %d AND next_try_at <= %d ORDER BY (kind = 'manual') DESC, scheduled_at ASC, id ASC LIMIT %d", // phpcs:ignore
				self::PENDING,
				$now,
				$now,
				(int) $limit
			)
		);
	}

	/** Rows of one post, newest first — for the post metabox. */
	public static function for_post( $post_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC", (int) $post_id ) ); // phpcs:ignore
	}

	public static function recent( $limit = 50, $channel_id = 0, $status = '' ) {
		global $wpdb;
		$table = self::table();
		$where = array( '1=1' );
		$args  = array();
		if ( $channel_id ) {
			$where[] = 'channel_id = %d';
			$args[]  = (int) $channel_id;
		}
		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = (string) $status;
		}
		$args[] = (int) $limit;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY GREATEST(sent_at, scheduled_at, created_at) DESC, id DESC LIMIT %d', $args ) ); // phpcs:ignore
	}

	public static function count( $channel_id, $status, $since = 0 ) {
		global $wpdb;
		$table = self::table();
		if ( self::SENT === $status && $since ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel_id = %d AND status = %s AND sent_at >= %d", (int) $channel_id, $status, (int) $since ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel_id = %d AND status = %s", (int) $channel_id, $status ) ); // phpcs:ignore
	}

	public static function last_sent_at( $channel_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sent_at) FROM {$table} WHERE channel_id = %d AND status = %s", (int) $channel_id, self::SENT ) ); // phpcs:ignore
	}

	public static function has_pending( $channel_id, $kind = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $kind ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE channel_id = %d AND status = %s AND kind = %s LIMIT 1", (int) $channel_id, self::PENDING, $kind ) ); // phpcs:ignore
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE channel_id = %d AND status = %s LIMIT 1", (int) $channel_id, self::PENDING ) ); // phpcs:ignore
	}

	/**
	 * SQL fragment for WP_Query: posts that have no row for this channel —
	 * neither sent, nor pending, nor skipped/failed. Rotation picks only these,
	 * so it never repeats and never loops on a post it can't send.
	 */
	public static function not_in_channel_sql( $channel_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN (SELECT post_id FROM {$table} WHERE channel_id = %d AND dedupe_key LIKE 'p%%')", (int) $channel_id ); // phpcs:ignore
	}

	public static function statuses() {
		return array(
			self::PENDING => 'в очереди',
			self::SENT    => 'отправлено',
			self::FAILED  => 'ошибка',
			self::SKIPPED => 'пропущено',
		);
	}

	public static function kinds() {
		return array(
			'new'      => 'публикация',
			'rotation' => 'ротация',
			'manual'   => 'вручную',
		);
	}
}
