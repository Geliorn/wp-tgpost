<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The GD toolkit the infographic is drawn with (WPTP_Infographic_Composer
 * extends it): canvas, crop by focal point, filters, tint, gradient, logo,
 * rounded plates, word wrap, font lookup and file output.
 *
 * Ported from LAA_Image_Composer (llm-agent-articles). GD only, as there:
 * present with FreeType on every host of the network, one implementation,
 * one set of bugs.
 */
class WPTP_Image_Composer {

	/** GD alpha is inverted and 0..127, not 0..255. */
	const GD_OPAQUE      = 0;
	const GD_TRANSPARENT = 127;

	/** Reference glyphs for a stable baseline across lines of different content. */
	const METRIC_SAMPLE = 'ХудЁjgy';

	public static function available() {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );
	}

	public static function text_available() {
		return self::available() && function_exists( 'imagettftext' );
	}

	/**
	 * Free a GD image. Needed on PHP 7.4 (resources); since PHP 8.0 GdImage is
	 * freed when unreferenced and imagedestroy() is a no-op, deprecated in 8.5.
	 */
	protected static function destroy_image( $image ) {
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image );
		}
	}


	// --------------------------------------------------------------- canvas

	protected static function new_canvas( $width, $height, $background ) {
		$canvas = imagecreatetruecolor( max( 1, $width ), max( 1, $height ) );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		$rgb = WPTP_Image_Util::hex_to_rgb( $background, array( 17, 24, 39 ) );
		$bg  = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $bg );

		imagealphablending( $canvas, true );
		return $canvas;
	}


	/** Scales the source to cover (and crops by the focal point) or to contain. */
	protected static function draw_source( $canvas, $source, array $preset ) {
		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$sw = imagesx( $source );
		$sh = imagesy( $source );

		if ( $sw < 1 || $sh < 1 ) {
			return;
		}

		if ( 'contain' === $preset['fit'] ) {
			$scale = min( $cw / $sw, $ch / $sh );
			$dw    = max( 1, (int) round( $sw * $scale ) );
			$dh    = max( 1, (int) round( $sh * $scale ) );
			list( $dx, $dy ) = self::place( $preset['focal'], $cw, $ch, $dw, $dh, 0, 0 );
			imagecopyresampled( $canvas, $source, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
			return;
		}

		$scale = max( $cw / $sw, $ch / $sh );
		$vis_w = min( $sw, (int) ceil( $cw / $scale ) );
		$vis_h = min( $sh, (int) ceil( $ch / $scale ) );
		list( $sx, $sy ) = self::place( $preset['focal'], $sw, $sh, $vis_w, $vis_h, 0, 0 );

		imagecopyresampled( $canvas, $source, 0, 0, $sx, $sy, $cw, $ch, $vis_w, $vis_h );
	}

	/**
	 * Top-left coordinate for a $iw×$ih box inside a $cw×$ch area, per the
	 * nine-point grid plus an offset.
	 */
	public static function place( $position, $cw, $ch, $iw, $ih, $offset_x = 0, $offset_y = 0 ) {
		$parts = explode( '-', (string) $position );
		$v     = $parts[0] ?? 'middle';
		$h     = $parts[1] ?? 'center';

		switch ( $h ) {
			case 'left':
				$x = $offset_x;
				break;
			case 'right':
				$x = $cw - $iw - $offset_x;
				break;
			default:
				$x = (int) round( ( $cw - $iw ) / 2 );
		}

		switch ( $v ) {
			case 'top':
				$y = $offset_y;
				break;
			case 'bottom':
				$y = $ch - $ih - $offset_y;
				break;
			default:
				$y = (int) round( ( $ch - $ih ) / 2 );
		}

		return array( (int) $x, (int) $y );
	}

	// -------------------------------------------------------------- filters

	protected static function apply_filters( $canvas, array $preset ) {
		if ( $preset['grayscale'] ) {
			imagefilter( $canvas, IMG_FILTER_GRAYSCALE );
		}
		if ( 0 !== (int) $preset['brightness'] ) {
			imagefilter( $canvas, IMG_FILTER_BRIGHTNESS, (int) $preset['brightness'] );
		}
		if ( 0 !== (int) $preset['contrast'] ) {
			imagefilter( $canvas, IMG_FILTER_CONTRAST, (int) $preset['contrast'] );
		}
		for ( $i = 0; $i < (int) $preset['blur']; $i++ ) {
			imagefilter( $canvas, IMG_FILTER_GAUSSIAN_BLUR );
		}
	}

	protected static function apply_tint( $canvas, array $preset ) {
		$alpha = (int) $preset['tint_alpha'];
		if ( $alpha < 1 || '' === $preset['tint_hex'] ) {
			return;
		}
		$rgb   = WPTP_Image_Util::hex_to_rgb( $preset['tint_hex'] );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::alpha_to_gd( $alpha ) );
		imagealphablending( $canvas, true );
		imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ) - 1, imagesy( $canvas ) - 1, $color );
	}

	protected static function apply_gradient( $canvas, array $preset ) {
		if ( ! $preset['gradient_enabled'] ) {
			return;
		}

		$w = imagesx( $canvas );
		$h = imagesy( $canvas );

		$from     = WPTP_Image_Util::hex_to_rgb( $preset['gradient_from_hex'] );
		$to       = WPTP_Image_Util::hex_to_rgb( $preset['gradient_to_hex'] );
		$from_a   = (int) $preset['gradient_from_alpha'];
		$to_a     = (int) $preset['gradient_to_alpha'];
		$vertical = in_array( $preset['gradient_direction'], array( 'to-bottom', 'to-top' ), true );
		$reverse  = in_array( $preset['gradient_direction'], array( 'to-top', 'to-left' ), true );

		$steps = $vertical ? $h : $w;
		if ( $steps < 2 ) {
			return;
		}

		imagealphablending( $canvas, true );

		for ( $i = 0; $i < $steps; $i++ ) {
			$t = $i / ( $steps - 1 );
			if ( $reverse ) {
				$t = 1 - $t;
			}

			$alpha = $from_a + ( $to_a - $from_a ) * $t;
			if ( $alpha < 0.5 ) {
				continue;
			}

			$color = imagecolorallocatealpha(
				$canvas,
				(int) round( $from[0] + ( $to[0] - $from[0] ) * $t ),
				(int) round( $from[1] + ( $to[1] - $from[1] ) * $t ),
				(int) round( $from[2] + ( $to[2] - $from[2] ) * $t ),
				self::alpha_to_gd( $alpha )
			);
			if ( $vertical ) {
				imageline( $canvas, 0, $i, $w - 1, $i, $color );
			} else {
				imageline( $canvas, $i, 0, $i, $h - 1, $color );
			}
		}
	}

	protected static function apply_logo( $canvas, array $preset ) {
		$attachment_id = (int) $preset['logo_id'];
		if ( $attachment_id < 1 ) {
			return;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		$logo = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $logo ) {
			return;
		}

		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$lw = imagesx( $logo );
		$lh = imagesy( $logo );

		$target_w = max( 8, (int) round( $cw * (int) $preset['logo_width'] / 100 ) );
		$target_h = max( 8, (int) round( $lh * ( $target_w / max( 1, $lw ) ) ) );

		$layer = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $target_w - 1, $target_h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagecopyresampled( $layer, $logo, 0, 0, 0, 0, $target_w, $target_h, $lw, $lh );
		self::destroy_image( $logo );

		$alpha = (int) $preset['logo_alpha'];
		if ( $alpha < 100 ) {
			self::scale_layer_alpha( $layer, $alpha / 100 );
		}

		list( $x, $y ) = self::place( $preset['logo_position'], $cw, $ch, $target_w, $target_h, (int) $preset['logo_offset_x'], (int) $preset['logo_offset_y'] );

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x, $y, 0, 0, $target_w, $target_h );
		self::destroy_image( $layer );
	}

	/** Multiplies every pixel's opacity by $factor (0..1), keeping its own alpha shape. */
	protected static function scale_layer_alpha( $layer, $factor ) {
		$w = imagesx( $layer );
		$h = imagesy( $layer );

		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba = imagecolorat( $layer, $x, $y );
				$a    = ( $rgba >> 24 ) & 0x7F;
				if ( self::GD_TRANSPARENT === $a ) {
					continue;
				}
				$opacity = ( self::GD_TRANSPARENT - $a ) * $factor;
				$color   = imagecolorallocatealpha( $layer, ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF, (int) round( self::GD_TRANSPARENT - $opacity ) );
				imagesetpixel( $layer, $x, $y, $color );
			}
		}

		imagealphablending( $layer, true );
	}

	// ----------------------------------------------------------------- text



	public static function text_width( $font, $size, $text ) {
		if ( '' === $text ) {
			return 0;
		}
		$bbox = imagettfbbox( $size, 0, $font, $text );
		return $bbox ? (int) abs( $bbox[2] - $bbox[0] ) : 0;
	}

	/** Greedy word wrap; a single word wider than the box is split at punctuation, then by characters. */
	public static function wrap( $font, $size, $text, $max_width ) {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$line  = '';

		foreach ( (array) $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( self::text_width( $font, $size, $candidate ) <= $max_width ) {
				$line = $candidate;
				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
				$line    = '';
			}

			if ( self::text_width( $font, $size, $word ) <= $max_width ) {
				$line = $word;
				continue;
			}

			// Too wide for a line: break after "/", "-", "_" or "." first
			// ("owner/ repo"), and only split by characters what still won't fit.
			$chunk = '';
			foreach ( (array) preg_split( '/(?<=[\/\-_.])/u', $word, -1, PREG_SPLIT_NO_EMPTY ) as $piece ) {
				if ( '' !== $chunk && self::text_width( $font, $size, $chunk . $piece ) > $max_width ) {
					$lines[] = $chunk;
					$chunk   = '';
				}
				if ( self::text_width( $font, $size, $piece ) <= $max_width ) {
					$chunk .= $piece;
					continue;
				}
				foreach ( (array) preg_split( '//u', $piece, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
					if ( '' !== $chunk && self::text_width( $font, $size, $chunk . $char ) > $max_width ) {
						$lines[] = $chunk;
						$chunk   = '';
					}
					$chunk .= $char;
				}
			}
			$line = $chunk;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return empty( $lines ) ? array( '' ) : $lines;
	}

	/**
	 * Font lookup, most specific first: the preset, the global setting, the
	 * font folders of this plugin, of llm-agent-articles and of wp-pinfeed
	 * (one upload serves all), then the usual system faces. Theme fonts are woff2 and
	 * GD can't read those.
	 */
	public static function resolve_font( array $preset = array() ) {
		$candidates = array();

		if ( ! empty( $preset['caption_font'] ) ) {
			$candidates[] = $preset['caption_font'];
		}
		$configured = (string) WPTP_Settings::get( 'caption_font_path', '' );
		if ( '' !== $configured ) {
			$candidates[] = $configured;
		}

		foreach ( self::uploaded_fonts() as $font ) {
			$candidates[] = $font['path'];
		}

		$candidates = array_merge(
			$candidates,
			array(
				'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
				'/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
				'/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
				'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
				'/usr/share/fonts/truetype/droid/DroidSans-Bold.ttf',
				'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			)
		);

		foreach ( $candidates as $path ) {
			$path = self::normalise_font_path( $path );
			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		foreach ( (array) glob( '/usr/share/fonts/truetype/*/*.ttf' ) as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	private static function normalise_font_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		if ( 0 === strpos( $path, '/' ) ) {
			return $path;
		}
		return trailingslashit( self::fonts_dir() ) . basename( $path );
	}

	public static function fonts_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'wptp-fonts';
	}

	/** @return array<int,array{name:string,path:string}> */
	public static function uploaded_fonts() {
		$upload = wp_upload_dir();
		$dirs   = array( self::fonts_dir(), trailingslashit( $upload['basedir'] ) . 'laa-fonts', trailingslashit( $upload['basedir'] ) . 'wppf-fonts' );
		$out    = array();
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( trailingslashit( $dir ) . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE ) as $path ) {
				if ( $path ) {
					$out[] = array(
						'name' => basename( $path ),
						'path' => $path,
					);
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------- frame & output


	/** Filled rectangle with rounded corners, on its own layer so the arcs don't double-blend. */
	protected static function rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, array $rgb, $alpha_pct ) {
		$x1 = max( 0, (int) $x1 );
		$y1 = max( 0, (int) $y1 );
		$x2 = min( imagesx( $canvas ) - 1, (int) $x2 );
		$y2 = min( imagesy( $canvas ) - 1, (int) $y2 );

		$w = $x2 - $x1 + 1;
		$h = $y2 - $y1 + 1;
		if ( $w < 2 || $h < 2 ) {
			return;
		}

		$radius = max( 0, min( (int) $radius, (int) floor( min( $w, $h ) / 2 ) ) );

		$layer = imagecreatetruecolor( $w, $h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );

		$color = imagecolorallocatealpha( $layer, $rgb[0], $rgb[1], $rgb[2], self::alpha_to_gd( $alpha_pct ) );

		if ( $radius < 1 ) {
			imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, $color );
		} else {
			imagefilledrectangle( $layer, $radius, 0, $w - 1 - $radius, $h - 1, $color );
			imagefilledrectangle( $layer, 0, $radius, $w - 1, $h - 1 - $radius, $color );
			$d = $radius * 2;
			imagefilledellipse( $layer, $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $radius, $h - 1 - $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $h - 1 - $radius, $d, $d, $color );
		}

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x1, $y1, 0, 0, $w, $h );
		self::destroy_image( $layer );
	}

	protected static function save( $canvas, array $preset, $out_path ) {
		$dir = dirname( $out_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::fail( 'Не удалось создать каталог для картинки: ' . $dir );
		}

		switch ( $preset['format'] ) {
			case 'png':
				imagesavealpha( $canvas, true );
				$ok   = imagepng( $canvas, $out_path, (int) round( 9 - ( (int) $preset['quality'] / 100 ) * 9 ) );
				$mime = 'image/png';
				break;
			case 'webp':
				if ( ! function_exists( 'imagewebp' ) ) {
					return self::fail( 'GD собран без поддержки WebP — выберите другой формат' );
				}
				$ok   = imagewebp( $canvas, $out_path, (int) $preset['quality'] );
				$mime = 'image/webp';
				break;
			default:
				$ok   = imagejpeg( $canvas, $out_path, (int) $preset['quality'] );
				$mime = 'image/jpeg';
		}

		if ( ! $ok ) {
			return self::fail( 'Не удалось записать файл картинки: ' . $out_path );
		}

		return array(
			'ok'    => true,
			'path'  => $out_path,
			'mime'  => $mime,
			'error' => '',
		);
	}

	public static function extension_for( $format ) {
		return in_array( $format, array( 'png', 'webp' ), true ) ? $format : 'jpg';
	}

	/** Percent opacity (0..100) -> GD alpha (127..0). */
	protected static function alpha_to_gd( $percent ) {
		$percent = max( 0, min( 100, (float) $percent ) );
		return (int) round( self::GD_TRANSPARENT * ( 1 - $percent / 100 ) );
	}

	protected static function fail( $error ) {
		return array(
			'ok'    => false,
			'path'  => '',
			'mime'  => '',
			'error' => $error,
		);
	}
}
