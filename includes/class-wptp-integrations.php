<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that knows about the other plugins of the network:
 *  - llm-agent-articles (LAA): articles in `post`, cover as featured image,
 *    `_laa_primary_keyword`, SEO fields in Yoast/Rank Math;
 *  - llm-agent-github-repos (LAGR): `github_repo` posts, `repo_topic` /
 *    `repo_language` / `repo_license` taxonomies, `short_description` and
 *    `_gh_*` meta.
 *
 * Nothing here is required — with neither plugin installed the channels work
 * on any post type and taxonomy; this class only adds ready-made profiles, a
 * few placeholders and the "import LLM keys" source list (wp-pinfeed included).
 */
class WPTP_Integrations {

	public static function has_articles() {
		return class_exists( 'LAA_CPT' ) || false !== get_option( 'laa_settings', false );
	}

	public static function has_github_repos() {
		return post_type_exists( 'github_repo' ) || class_exists( 'LAGR_CPT' );
	}

	/** Ready-made channel profiles, only for the plugins present on this site. */
	public static function profiles() {
		$out = array();

		if ( self::has_articles() ) {
			$out['articles'] = array(
				'label'  => 'Статьи блога (llm-agent-articles)',
				'values' => array(
					'post_types'         => array( 'post' ),
					'format'             => 'photo',
					'text_template'      => "<b>{tg_title|llm_title|seo_title|title}</b>\n\n{tg_text|llm_description|seo_description|excerpt|content:600}\n\n{hashtags:post_tag:5}",
					// The article's own picture (cover or infographic) as is;
					// nothing found — an infographic with points from the model.
					'image_sources'      => array( 'thumbnail', 'content' ),
					'image_horizontal'   => 'use',
					'image_missing'      => 'generate',
					'infographic_preset' => 'ig-list',
					'footer_template'    => '{site_domain}',
					// The article plugin publishes first and attaches the cover
					// right after; give it time.
					'delay_minutes'      => 30,
					'llm_keywords'       => '{primary_keyword}',
				),
			);
		}

		if ( self::has_github_repos() ) {
			$out['github_repos'] = array(
				'label'  => 'GitHub-репозитории (llm-agent-github-repos)',
				'values' => array(
					'post_types'         => array( 'github_repo' ),
					'format'             => 'photo',
					'text_template'      => "<b>{tg_title|llm_title|title}</b>\n\n{tg_text|llm_description|short_description|seo_description|excerpt}\n\n{gh_stars} {gh_language}\n\n{hashtags:repo_topic:1} {hashtags:post_tag:5}",
					// The pin llm-agent-github-repos renders, then the post's picture.
					// The owner avatar is part of the site design, never a picture here.
					'image_sources'      => array( 'gh_pin', 'thumbnail' ),
					'image_meta_key'     => '',
					'image_horizontal'   => 'use',
					'image_missing'      => 'generate',
					'infographic_preset' => 'ig-grid',
					'infographic_title'  => '{tg_title|llm_title|title}',
					'footer_template'    => '{gh_stars} · {site_domain}',
				),
			);
		}

		/**
		 * Extra channel profiles: key => array( label, values ).
		 *
		 * @param array $out
		 */
		return (array) apply_filters( 'wptp_profiles', $out );
	}

	/** Field values of one profile (empty array for an unknown key). */
	public static function profile( $key ) {
		$profiles = self::profiles();
		return isset( $profiles[ $key ]['values'] ) ? (array) $profiles[ $key ]['values'] : array();
	}

	// ---------------------------------------------------------- placeholders

	public static function init() {
		add_filter( 'wptp_placeholder', array( __CLASS__, 'placeholder' ), 10, 4 );
	}

	public static function placeholder( $value, $name, $args, $ctx ) {
		if ( null !== $value ) {
			return $value;
		}
		$post = $ctx['post'] ?? null;
		if ( ! $post instanceof WP_Post ) {
			return $value;
		}

		switch ( $name ) {
			case 'gh_stars':
				$stars = (int) get_post_meta( $post->ID, '_gh_stars', true );
				return $stars > 0 ? '★ ' . self::short_number( $stars ) : '';
			case 'gh_forks':
				$forks = (int) get_post_meta( $post->ID, '_gh_forks', true );
				return $forks > 0 ? self::short_number( $forks ) : '';
			case 'gh_language':
				return (string) get_post_meta( $post->ID, '_gh_language', true );
			case 'gh_license':
				return (string) get_post_meta( $post->ID, '_gh_license', true );
			case 'gh_full_name':
				return (string) get_post_meta( $post->ID, '_gh_full_name', true );
			case 'gh_url':
				$full = (string) get_post_meta( $post->ID, '_gh_full_name', true );
				return '' !== $full ? 'github.com/' . $full : '';
			case 'gh_pin_description':
				// Written by llm-agent-github-repos together with its pin.
				return (string) get_post_meta( $post->ID, '_gh_pin_description', true );
			case 'primary_keyword':
				return (string) get_post_meta( $post->ID, '_laa_primary_keyword', true );
		}

		return $value;
	}

	public static function placeholder_help() {
		$out = array();
		if ( self::has_github_repos() ) {
			$out['{gh_stars} {gh_forks}']        = 'Звёзды («★ 1,2k») и форки репозитория';
			$out['{gh_language} {gh_license}']   = 'Язык и лицензия репозитория';
			$out['{gh_full_name} {gh_url}']      = 'owner/repo и github.com/owner/repo';
			$out['{gh_pin_description}']         = 'Описание, которое github-repos пишет вместе со своим пином';
		}
		if ( self::has_articles() ) {
			$out['{primary_keyword}'] = 'Главный ключ статьи (llm-agent-articles)';
		}
		return $out;
	}

	/** 1234 -> "1,2k", 2300000 -> "2,3M". */
	public static function short_number( $n ) {
		$n = (int) $n;
		if ( $n >= 1000000 ) {
			return rtrim( rtrim( number_format( $n / 1000000, 1, ',', '' ), '0' ), ',' ) . 'M';
		}
		if ( $n >= 1000 ) {
			return rtrim( rtrim( number_format( $n / 1000, 1, ',', '' ), '0' ), ',' ) . 'k';
		}
		return (string) $n;
	}

	// -------------------------------------------------------------- LLM keys

	/** Plugins whose LLM settings can be copied: key => array( label, option ). */
	public static function llm_import_sources() {
		$out = array();
		foreach ( array(
			'articles'     => array( 'AI Article Writer (llm-agent-articles)', 'laa_settings' ),
			'github_repos' => array( 'GitHub Repo Collector (llm-agent-github-repos)', 'lagr_settings' ),
			'pinfeed'      => array( 'WP PinFeed (wp-pinfeed)', 'wppf_settings' ),
		) as $key => $source ) {
			$stored = get_option( $source[1], array() );
			if ( is_array( $stored ) && self::has_any_llm_key( $stored ) ) {
				$out[ $key ] = array(
					'label'  => $source[0],
					'option' => $source[1],
				);
			}
		}
		return $out;
	}

	private static function has_any_llm_key( array $s ) {
		foreach ( array( 'yandex_api_key', 'deepseek_api_key', 'qwen_api_key', 'timeweb_token', 'openrouter_api_key' ) as $key ) {
			if ( ! empty( $s[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Copies the LLM block from a neighbour plugin. Returns the number of keys copied. */
	public static function import_llm( $source_key ) {
		$sources = self::llm_import_sources();
		if ( ! isset( $sources[ $source_key ] ) ) {
			return 0;
		}

		$stored = (array) get_option( $sources[ $source_key ]['option'], array() );
		$values = array();
		foreach ( WPTP_Settings::llm_keys() as $key ) {
			if ( isset( $stored[ $key ] ) && '' !== (string) $stored[ $key ] ) {
				$values[ $key ] = (string) $stored[ $key ];
			}
		}

		if ( $values ) {
			WPTP_Settings::update( $values );
			WPTP_Log::add( 'Импортированы настройки LLM из ' . $sources[ $source_key ]['label'] . ' (' . count( $values ) . ' полей)' );
		}
		return count( $values );
	}
}
