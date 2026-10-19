<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the picture of a message by walking the channel's ordered source
 * list. The first acceptable hit wins and is sent as is. "Acceptable": a
 * wider-than-tall picture is skipped when the channel says so — then the next
 * source is tried, and with nothing left WPTP_Message_Builder generates an
 * infographic (or sends text only / skips the post).
 *
 * Same as WPPF_Image_Resolver in wp-pinfeed, minus the per-post Pinterest
 * picture: a post's own picture for Telegram comes from a meta field.
 */
class WPTP_Image_Resolver {

	/**
	 * Available sources: key => array( label, help ). "gh_pin" is listed only
	 * when llm-agent-github-repos is present.
	 */
	public static function sources() {
		$sources = array(
			'gh_pin'    => array(
				'label' => 'Готовый пин из плагина «GitHub-репозитории»',
				'help'  => 'Вертикальная картинка 1000×1500, которую llm-agent-github-repos собирает для пинов (_gh_pin_image_id). На сайте не выводится.',
			),
			'thumbnail' => array(
				'label' => 'Изображение записи (миниатюра)',
				'help'  => 'Featured image. У статей llm-agent-articles — обложка или инфографика статьи.',
			),
			'content'   => array(
				'label' => 'Первое изображение в тексте записи',
				'help'  => 'Первая картинка в содержимом записи.',
			),
			'meta'      => array(
				'label' => 'Мета-поле записи',
				'help'  => 'Произвольное поле, где лежит ID вложения или URL картинки. Ключ — в поле ниже.',
			),
		);

		if ( ! WPTP_Integrations::has_github_repos() ) {
			unset( $sources['gh_pin'] );
		}

		/**
		 * Extra image sources: key => array( 'label' => ..., 'help' => ... )
		 * (a plain string label works too). Resolve them on wptp_image_source.
		 *
		 * @param array $sources
		 */
		$sources = (array) apply_filters( 'wptp_image_sources', $sources );
		foreach ( $sources as $key => $source ) {
			if ( ! is_array( $source ) ) {
				$sources[ $key ] = array(
					'label' => (string) $source,
					'help'  => '',
				);
			}
		}
		return $sources;
	}

	/**
	 * Walks the sources in order.
	 *
	 * @return array{found:array|null,skipped:array}
	 *         found   — the picture to serve (with 'source'), or null;
	 *         skipped — horizontal pictures passed over (first one may serve
	 *                   as an infographic background).
	 */
	public static function find( WP_Post $post, array $config ) {
		$skipped = array();

		foreach ( $config['image_sources'] as $source ) {
			$found = self::from_source( $source, $post, $config );
			if ( ! $found || '' === $found['url'] ) {
				continue;
			}
			$found['source'] = $source;

			if ( 'skip' === $config['image_horizontal'] && self::is_horizontal( $found ) ) {
				$skipped[] = $found;
				continue;
			}
			return array(
				'found'   => $found,
				'skipped' => $skipped,
			);
		}

		return array(
			'found'   => null,
			'skipped' => $skipped,
		);
	}

	public static function from_source( $source, WP_Post $post, array $config ) {
		switch ( $source ) {
			case 'gh_pin':
				return self::attachment( (int) get_post_meta( $post->ID, '_gh_pin_image_id', true ) );

			case 'thumbnail':
				return self::attachment( (int) get_post_thumbnail_id( $post ) );

			case 'content':
				return self::from_content( $post );

			case 'meta':
				if ( '' === $config['image_meta_key'] ) {
					return null;
				}
				$value = get_post_meta( $post->ID, $config['image_meta_key'], true );
				if ( is_numeric( $value ) ) {
					return self::attachment( (int) $value );
				}
				return self::url( (string) $value );
		}

		/**
		 * Custom image source registered by a filter on wptp_image_sources.
		 *
		 * @param array|null $found  array(attachment_id, url, width, height) or null
		 * @param string     $source
		 * @param WP_Post    $post
		 * @param array      $config
		 */
		return apply_filters( 'wptp_image_source', null, $source, $post, $config );
	}

	private static function from_content( WP_Post $post ) {
		$content = (string) $post->post_content;

		// Block editor and classic both tag library images with wp-image-ID.
		if ( preg_match( '/wp-image-(\d+)/', $content, $m ) ) {
			$found = self::attachment( (int) $m[1] );
			if ( $found ) {
				return $found;
			}
		}

		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$url = html_entity_decode( $m[1] );
			$id  = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url ) );
			return $id ? self::attachment( $id ) : self::url( $url );
		}

		return null;
	}

	/** Full-size data of a library image, or null. */
	public static function attachment( $attachment_id ) {
		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) $src[0],
			'width'         => (int) $src[1],
			'height'        => (int) $src[2],
		);
	}

	/** An external URL (e.g. a GitHub avatar). Size unknown until downloaded. */
	public static function url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return null;
		}
		return array(
			'attachment_id' => 0,
			'url'           => $url,
			'width'         => 0,
			'height'        => 0,
		);
	}

	/**
	 * Wider than tall. An external URL has no known size until downloaded —
	 * it is not fetched just to decide, so it counts as not horizontal.
	 */
	public static function is_horizontal( array $found ) {
		if ( $found['width'] < 1 || $found['height'] < 1 ) {
			return false;
		}
		return $found['width'] > $found['height'];
	}

	private static function cache_path( array $found ) {
		return WPTP_Message_Builder::base_dir() . 'src/' . md5( $found['url'] ) . '.img';
	}

	/** Already downloaded (or a library file) — rendering won't touch the network. */
	public static function has_local_copy( array $found ) {
		if ( $found['attachment_id'] ) {
			return true;
		}
		$path = self::cache_path( $found );
		return is_readable( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Local path of the picture, for GD. Library images are read from disk;
	 * external ones are downloaded once into uploads/wptp-images/src/ (keyed by
	 * URL) so a re-render doesn't hit the remote host again.
	 */
	public static function local_path( array $found ) {
		if ( $found['attachment_id'] ) {
			$path = get_attached_file( $found['attachment_id'] );
			return $path && is_readable( $path ) ? $path : '';
		}

		$path = self::cache_path( $found );
		$dir  = dirname( $path );
		if ( is_readable( $path ) && filesize( $path ) > 0 ) {
			return $path;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $found['url'], 15 );
		if ( is_wp_error( $tmp ) ) {
			WPTP_Log::error( 'Не удалось скачать картинку ' . $found['url'] . ': ' . $tmp->get_error_message() );
			return '';
		}

		// Only keep it if it's actually an image GD can open.
		$info = @getimagesize( $tmp ); // phpcs:ignore
		if ( ! $info ) {
			@unlink( $tmp ); // phpcs:ignore
			return '';
		}

		wp_mkdir_p( $dir );
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@copy( $tmp, $path ); // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
		}
		return is_readable( $path ) ? $path : '';
	}
}
