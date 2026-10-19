<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The placeholder engine behind every text template of a channel — the same
 * engine as WPPF_Template in wp-pinfeed.
 *
 *   {title}                     a placeholder
 *   {content:300}               with an argument
 *   {tg_title|seo_title|title}  fallback chain: first non-empty value wins
 *   {excerpt|"Читать на сайте"} a quoted literal as the last resort
 *
 * Unknown placeholders resolve to an empty string (and so fall through the
 * chain) — a typo must not leak "{tilte}" into a public post.
 *
 * render_html() is the Telegram flavour: the template may use Telegram's
 * HTML tags (<b>, <i>, <a href>, <blockquote>, …), every placeholder value
 * is escaped, and anything else in the template that looks like markup is
 * escaped too — so a "<" in a post title can't break the message.
 */
class WPTP_Template {

	/** Telegram's HTML subset (parse_mode=HTML) — kept as is in a template. */
	const ALLOWED_TAGS = '#</?(?:b|strong|i|em|u|ins|s|strike|del|code|pre|tg-spoiler)>|<a\s+href="[^"<>]*">|</a>|<blockquote(?:\s+expandable)?>|</blockquote>|<span\s+class="tg-spoiler">|</span>#i';

	/**
	 * @param string $template
	 * @param array  $ctx  post (WP_Post|null), config (array), llm (array|null)
	 */
	public static function render( $template, array $ctx ) {
		return self::tidy(
			preg_replace_callback(
				'/\{([^{}\n]+)\}/u',
				function ( $m ) use ( $ctx ) {
					return self::resolve_chain( $m[1], $ctx );
				},
				(string) $template
			)
		);
	}

	/**
	 * Telegram HTML: allowed tags of the template kept, the rest of its text
	 * and every placeholder value escaped.
	 *
	 * @param int $clip Cut placeholder values to this many characters (0 — no
	 *                  cut); the message builder lowers it until the message
	 *                  fits Telegram's limit.
	 * @param int $only Apply $clip to this placeholder only (0-based index in
	 *                  the template), -1 — to all of them.
	 */
	public static function render_html( $template, array $ctx, $clip = 0, $only = -1 ) {
		$template = (string) $template;
		$parts    = preg_split( self::ALLOWED_TAGS, $template, -1, PREG_SPLIT_OFFSET_CAPTURE );
		preg_match_all( self::ALLOWED_TAGS, $template, $tags );

		$safe = '';
		foreach ( $parts as $i => $part ) {
			$safe .= self::escape( $part[0] );
			if ( isset( $tags[0][ $i ] ) ) {
				$safe .= $tags[0][ $i ];
			}
		}

		$index = -1;
		return self::tidy(
			preg_replace_callback(
				'/\{([^{}\n]+)\}/u',
				function ( $m ) use ( $ctx, $clip, $only, &$index ) {
					++$index;
					return self::resolve_chain( $m[1], $ctx, true, ( $only < 0 || $only === $index ) ? (int) $clip : 0 );
				},
				$safe
			)
		);
	}

	/** Index of the placeholder with the longest value, -1 if none has one. */
	public static function longest_placeholder( $template, array $ctx ) {
		preg_match_all( '/\{([^{}\n]+)\}/u', (string) $template, $m );
		$best   = -1;
		$length = 0;
		foreach ( $m[1] as $i => $chain ) {
			$len = mb_strlen( self::resolve_chain( $chain, $ctx ) );
			if ( $len > $length ) {
				$best   = $i;
				$length = $len;
			}
		}
		return $best;
	}

	/** Telegram HTML needs only <, > and & escaped; existing entities stay. */
	public static function escape( $text ) {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false );
	}

	/** Length as the reader sees it — what Telegram's limits count. */
	public static function visible_length( $html ) {
		return mb_strlen( html_entity_decode( wp_strip_all_tags( (string) $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function tidy( $out ) {
		// A tag around a chain that resolved to nothing: "<b></b>" is noise
		// (and an empty entity Telegram may reject).
		$out = preg_replace( '#<(b|strong|i|em|u|ins|s|strike|del|code|tg-spoiler)>\s*</\1>#iu', '', (string) $out );

		// Templates are multi-line on purpose (description + blank line +
		// hashtags), but a chain that resolved to nothing must not leave
		// a stack of empty lines behind.
		$out = preg_replace( "/[ \t]+/u", ' ', (string) $out );
		$out = preg_replace( "/ *\n */u", "\n", $out );
		$out = preg_replace( "/\n{3,}/u", "\n\n", $out );
		return trim( $out );
	}

	/**
	 * @param bool $html Escape values for Telegram HTML (literals are already
	 *                   escaped as part of the template).
	 * @param int  $clip Cut values to this many characters, 0 — don't.
	 */
	public static function resolve_chain( $chain, array $ctx, $html = false, $clip = 0 ) {
		// Split on "|" outside of double quotes.
		$parts = preg_split( '/\|(?=(?:[^"]*"[^"]*")*[^"]*$)/u', $chain );
		foreach ( (array) $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^"(.*)"$/us', $part, $lit ) ) {
				return $lit[1];
			}
			$value = self::resolve( $part, $ctx );
			if ( '' !== trim( $value ) ) {
				if ( $clip > 0 ) {
					$value = self::truncate( $value, $clip );
				}
				return $html ? self::escape( $value ) : $value;
			}
		}
		return '';
	}

	/** One placeholder, e.g. "terms:repo_topic:3". Always returns plain text. */
	public static function resolve( $token, array $ctx ) {
		$args = explode( ':', $token );
		$name = strtolower( trim( array_shift( $args ) ) );
		$post   = $ctx['post'] ?? null;
		$config = $ctx['config'] ?? array();

		$value = null;

		switch ( $name ) {
			// ------------------------------------------------------------ site
			case 'site_name':
				$value = get_bloginfo( 'name' );
				break;
			case 'site_description':
				$value = get_bloginfo( 'description' );
				break;
			case 'site_url':
				$value = home_url( '/' );
				break;
			case 'site_domain':
				$value = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$value = preg_replace( '/^www\./', '', $value );
				break;

			// --------------------------------------------------------- channel
			case 'channel_title':
				$value = $config['channel_title'] ?? '';
				break;
			case 'channel_slug':
				$value = $config['channel_slug'] ?? '';
				break;

			// --------------------------------------------------------- message
			case 'palette':
				// Set by WPTP_Message_Builder once the picture is known.
				$value = $ctx['palette'] ?? '';
				break;
			case 'link':
				// The post URL with UTM, set by WPTP_Message_Builder.
				$value = $ctx['link'] ?? '';
				break;
		}

		if ( null === $value && $post instanceof WP_Post ) {
			$value = self::resolve_post( $name, $args, $post, $ctx );
		}

		/**
		 * Custom placeholders. Return a string to handle $name, or leave null.
		 *
		 * @param string|null $value
		 * @param string      $name  Lower-cased placeholder name.
		 * @param array       $args  Colon-separated arguments.
		 * @param array       $ctx   post, config, llm.
		 */
		$value = apply_filters( 'wptp_placeholder', $value, $name, $args, $ctx );

		return self::plain( (string) $value );
	}

	private static function resolve_post( $name, array $args, WP_Post $post, array $ctx ) {
		switch ( $name ) {
			case 'title':
				return get_the_title( $post );

			case 'excerpt':
				if ( has_excerpt( $post ) ) {
					return $post->post_excerpt;
				}
				return wp_trim_words( self::content_text( $post ), 55, '…' );

			case 'content':
				$chars = isset( $args[0] ) ? max( 20, (int) $args[0] ) : 300;
				return self::truncate( self::content_text( $post ), $chars );

			case 'short_description':
				// llm-agent-github-repos keeps the card text here.
				return (string) get_post_meta( $post->ID, 'short_description', true );

			case 'seo_title':
				return self::seo( $post->ID, 'title' );

			case 'seo_description':
				return self::seo( $post->ID, 'description' );

			case 'tg_title':
				return (string) get_post_meta( $post->ID, WPTP_Post_Meta::TITLE, true );

			case 'tg_text':
				return (string) get_post_meta( $post->ID, WPTP_Post_Meta::TEXT, true );

			case 'llm_title':
				return (string) ( $ctx['llm']['title'] ?? '' );

			case 'llm_description':
				return (string) ( $ctx['llm']['description'] ?? '' );

			case 'permalink':
				return get_permalink( $post );

			case 'date':
				return get_the_date( $args[0] ?? 'd.m.Y', $post );

			case 'author':
				return get_the_author_meta( 'display_name', (int) $post->post_author );

			case 'post_type':
				return $post->post_type;

			case 'post_type_label':
				$obj = get_post_type_object( $post->post_type );
				return $obj ? $obj->labels->singular_name : '';

			case 'meta':
				if ( empty( $args[0] ) ) {
					return '';
				}
				$meta = get_post_meta( $post->ID, implode( ':', $args ), true );
				return is_scalar( $meta ) ? (string) $meta : '';

			case 'terms':
			case 'term':
			case 'hashtags':
				$taxonomy = $args[0] ?? '';
				$limit    = 'term' === $name ? 1 : ( isset( $args[1] ) ? max( 1, (int) $args[1] ) : 10 );
				$names    = self::term_names( $post, $taxonomy, $limit );
				if ( 'hashtags' === $name ) {
					return implode( ' ', array_filter( array_map( array( __CLASS__, 'hashtag' ), $names ) ) );
				}
				return implode( ', ', $names );
		}

		return null;
	}

	/** Term names of a post in one taxonomy ("" or "*" = every public taxonomy). */
	public static function term_names( WP_Post $post, $taxonomy, $limit ) {
		$taxonomies = ( '' === $taxonomy || '*' === $taxonomy )
			? get_object_taxonomies( $post->post_type )
			: array( $taxonomy );

		$names = array();
		foreach ( $taxonomies as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$obj = get_taxonomy( $tax );
			if ( ! $obj || ! $obj->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				if ( 'uncategorized' === $t->slug ) {
					continue;
				}
				$names[] = $t->name;
			}
		}

		$names = array_values( array_unique( $names ) );
		return array_slice( $names, 0, $limit );
	}

	/** "AI агенты" -> "#AIАгенты"; drops anything that isn't a letter/digit. */
	public static function hashtag( $name ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return '';
		}
		$tag = '';
		foreach ( $words as $word ) {
			$tag .= mb_strtoupper( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
		}
		return '#' . $tag;
	}

	/** Title or description from whichever SEO plugin the site runs. */
	public static function seo( $post_id, $field ) {
		switch ( WPTP_Settings::seo_plugin() ) {
			case 'yoast':
				$key = 'title' === $field ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
				break;
			case 'rankmath':
				$key = 'title' === $field ? 'rank_math_title' : 'rank_math_description';
				break;
			default:
				return '';
		}

		$value = (string) get_post_meta( $post_id, $key, true );
		// SEO plugins store their own %%vars%% / %vars% — unresolved ones are
		// worse than nothing in a post.
		if ( preg_match( '/%%?[a-z_]+%%?/i', $value ) ) {
			return '';
		}
		return $value;
	}

	public static function content_text( WP_Post $post ) {
		$content = strip_shortcodes( (string) $post->post_content );
		$content = excerpt_remove_blocks( $content );
		return self::plain( $content );
	}

	/** HTML -> one tidy plain-text string, newlines kept. */
	public static function plain( $text ) {
		// Block boundaries become line breaks first, or "<p>a.</p><p>b</p>" turns into "a.b".
		$text = preg_replace( '#<br\s*/?>|</(p|div|li|h[1-6]|blockquote|pre|tr|figure)>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/\r\n?/", "\n", $text );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		return trim( (string) $text );
	}

	/** Cuts at a word boundary and adds an ellipsis; never mid-word. */
	public static function truncate( $text, $max ) {
		$text = trim( (string) $text );
		$max  = (int) $max;
		if ( $max < 1 || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = max( mb_strrpos( $cut, ' ' ), mb_strrpos( $cut, "\n" ) );
		if ( false !== $space && $space > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n.,;:-—–" ) . '…';
	}

	/** Reference table for the channel form. */
	public static function help_html() {
		$rows = array(
			'{title}'                     => 'Заголовок записи',
			'{excerpt}'                   => 'Отрывок (или первые 55 слов текста)',
			'{content:300}'               => 'Текст записи, до N символов',
			'{short_description}'         => 'Краткое описание репозитория (llm-agent-github-repos)',
			'{seo_title} {seo_description}' => 'Из Yoast / Rank Math',
			'{tg_title} {tg_text}'        => 'Ручной текст из блока «Telegram» у записи',
			'{llm_title} {llm_description}' => 'Сгенерировано моделью (если включено ниже)',
			'{link}'                      => 'Ссылка на запись с UTM-метками — например, <code>&lt;a href="{link}"&gt;Читать&lt;/a&gt;</code>',
			'{terms:category:3}'          => 'Термины таксономии через запятую',
			'{term:repo_topic}'           => 'Первый термин таксономии',
			'{hashtags:post_tag:5}'       => 'Хэштеги из терминов (#ИмяТермина). <code>*</code> вместо таксономии — все',
			'{meta:_gh_stars}'            => 'Любое мета-поле',
			'{date} {author} {post_type_label}' => 'Дата, автор, тип записи',
			'{channel_title}'             => 'Название канала (как в админке)',
			'{site_name} {site_domain}'   => 'Сайт',
		);
		$rows = array_merge( $rows, WPTP_Integrations::placeholder_help() );

		$html = '<table class="wptp-help widefat striped"><tbody>';
		foreach ( $rows as $code => $label ) {
			$html .= '<tr><td><code>' . esc_html( $code ) . '</code></td><td>' . wp_kses_post( $label ) . '</td></tr>';
		}
		$html .= '</tbody></table><p class="description"><code>{a|b|c}</code> — первое непустое значение; <code>{excerpt|"Читать на сайте"}</code> — текст в кавычках как запасной вариант. Разметка Telegram: <code>&lt;b&gt; &lt;i&gt; &lt;u&gt; &lt;s&gt; &lt;code&gt; &lt;a href="…"&gt; &lt;blockquote&gt; &lt;blockquote expandable&gt; &lt;tg-spoiler&gt;</code>; остальное экранируется.</p>';
		return $html;
	}
}
