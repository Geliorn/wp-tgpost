<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channel config -> WP_Query args. One place for "which posts belong to this
 * channel", used three ways:
 *  - matches()   — is a just-published post for this channel (and does it
 *                  still match right before sending);
 *  - rotation()  — older posts never sent to this channel, in the channel's
 *                  rotation order;
 *  - count()     — numbers for the admin and the CLI.
 * The taxonomy rules are the same as wp-pinfeed's (WPPF_Query::tax_query).
 */
class WPTP_Query {

	/** Per-post opt-out, set in the post metabox (WPTP_Post_Meta). */
	const EXCLUDE_META = '_wptp_exclude';

	/** Query var that switches on the "never sent to this channel" clause. */
	const NOT_IN_CHANNEL = 'wptp_not_in_channel';

	public static function init() {
		add_filter( 'posts_where', array( __CLASS__, 'posts_where' ), 10, 2 );
	}

	/**
	 * @param array  $config Normalised channel config.
	 * @param int    $limit
	 * @param string $mode   match | rotation
	 */
	public static function args( array $config, $limit = 10, $mode = 'match' ) {
		$args = array(
			'post_type'              => $config['post_types'],
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $limit,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'has_password'           => false,
			'suppress_filters'       => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array(
				'relation' => 'OR',
				array(
					'key'     => self::EXCLUDE_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::EXCLUDE_META,
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		$tax_query = self::tax_query( $config );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$exclude = array_filter( array_map( 'intval', explode( ',', (string) $config['exclude_ids'] ) ) );
		if ( $exclude ) {
			$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams
		}

		if ( 'rotation' === $mode ) {
			$args = self::rotation_args( $args, $config );
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		/**
		 * Last word on the query of one channel.
		 *
		 * @param array  $args   WP_Query args.
		 * @param array  $config Normalised channel config.
		 * @param string $mode   match | rotation
		 */
		return (array) apply_filters( 'wptp_query_args', $args, $config, $mode );
	}

	private static function rotation_args( array $args, array $config ) {
		$args[ self::NOT_IN_CHANNEL ] = (int) $config['channel_id'];

		switch ( $config['rotation_order'] ) {
			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
			case 'meta_num':
				if ( '' !== $config['rotation_meta_key'] ) {
					// A named meta clause instead of meta_key, so posts lacking
					// the field are not silently dropped.
					$args['meta_query'] = array(
						'relation' => 'AND',
						$args['meta_query'],
						array(
							'relation'  => 'OR',
							'wptp_sort' => array(
								'key'  => $config['rotation_meta_key'],
								'type' => 'NUMERIC',
							),
							array(
								'key'     => $config['rotation_meta_key'],
								'compare' => 'NOT EXISTS',
							),
						),
					);
					$args['orderby'] = array(
						'wptp_sort' => 'DESC',
						'date'      => 'DESC',
					);
					break;
				}
				// No key given — fall through to the oldest first.
			case 'oldest':
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'ASC';
		}

		$date_query = array();
		if ( (int) $config['rotation_min_age_days'] > 0 ) {
			$date_query[] = array(
				'before'    => gmdate( 'Y-m-d H:i:s', time() - (int) $config['rotation_min_age_days'] * DAY_IN_SECONDS ),
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);
		}
		if ( (int) $config['rotation_max_age_days'] > 0 ) {
			$date_query[] = array(
				'after'     => gmdate( 'Y-m-d H:i:s', time() - (int) $config['rotation_max_age_days'] * DAY_IN_SECONDS ),
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);
		}
		if ( $date_query ) {
			$args['date_query'] = $date_query;
		}

		return $args;
	}

	/** posts_where: "never queued for this channel" for rotation queries. */
	public static function posts_where( $where, $query ) {
		$channel_id = (int) $query->get( self::NOT_IN_CHANNEL );
		if ( $channel_id > 0 ) {
			$where .= WPTP_Queue::not_in_channel_sql( $channel_id );
		}
		return $where;
	}

	public static function tax_query( array $config ) {
		$clauses = array();

		foreach ( $config['tax_rules'] as $rule ) {
			if ( ! taxonomy_exists( $rule['taxonomy'] ) ) {
				continue;
			}

			$clause = array(
				'taxonomy'         => $rule['taxonomy'],
				'operator'         => $rule['operator'],
				'include_children' => (bool) $rule['children'],
			);

			if ( ! in_array( $rule['operator'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$terms = array_values( array_filter( array_map( 'trim', explode( ',', $rule['terms'] ) ), 'strlen' ) );
				if ( ! $terms ) {
					continue;
				}
				// Slugs by default (portable between sites); numeric entries are IDs.
				$all_numeric       = count( array_filter( $terms, 'ctype_digit' ) ) === count( $terms );
				$clause['field']   = $all_numeric ? 'term_id' : 'slug';
				$clause['terms']   = $all_numeric ? array_map( 'intval', $terms ) : array_map( 'sanitize_title', $terms );
			}

			$clauses[] = $clause;
		}

		if ( ! $clauses ) {
			return array();
		}

		$clauses['relation'] = $config['tax_relation'];
		return $clauses;
	}

	/** Does this (published) post belong to the channel right now? */
	public static function matches( $post_id, array $config ) {
		$args           = self::args( $config, 1 );
		$args['p']      = (int) $post_id;
		$args['fields'] = 'ids';
		$query          = new WP_Query( $args );
		return ! empty( $query->posts );
	}

	/** @return WP_Post[] Next rotation candidates, never sent to this channel. */
	public static function rotation( array $config, $limit = 1 ) {
		$query = new WP_Query( self::args( $config, $limit, 'rotation' ) );
		return $query->posts;
	}

	/** How many posts match the channel (mode=match) or are left for rotation. */
	public static function count( array $config, $mode = 'match' ) {
		$args                  = self::args( $config, 1, $mode );
		$args['no_found_rows'] = false;
		$args['fields']        = 'ids';
		$args['orderby']       = 'none';
		$query                 = new WP_Query( $args );
		return (int) $query->found_posts;
	}
}
