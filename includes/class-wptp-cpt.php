<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One post type: `tgchannel` — a Telegram channel (or group) the site posts
 * to, with its bot, selection rules, schedule and message template. Not
 * public, but with a UI, same as `pinfeed` in wp-pinfeed. Unlike a feed, a
 * channel publishes by itself — so only administrators manage channels.
 */
class WPTP_CPT {

	const CHANNEL = 'tgchannel';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
	}

	public static function register_post_types() {
		register_post_type(
			self::CHANNEL,
			array(
				'label'        => 'Telegram-каналы',
				'labels'       => array(
					'name'          => 'Telegram-каналы',
					'singular_name' => 'Telegram-канал',
					'add_new'       => 'Добавить канал',
					'add_new_item'  => 'Новый канал',
					'edit_item'     => 'Канал',
					'search_items'  => 'Поиск каналов',
					'all_items'     => 'Каналы',
					'not_found'     => 'Каналов пока нет',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				// A channel posts publicly on the site's behalf — administrators only.
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
					'delete_posts'       => 'manage_options',
					'create_posts'       => 'manage_options',
				),
				'menu_icon'    => 'dashicons-megaphone',
				'show_in_menu' => WPTP_Admin::MAIN_SLUG,
			)
		);
	}
}
