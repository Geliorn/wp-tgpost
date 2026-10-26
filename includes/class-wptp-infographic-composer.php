<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws an infographic picture: accent bar, heading, N numbered points laid out
 * as a vertical list or a grid of blocks, a footer line and a logo.
 *
 * A port of LAA_Infographic_Composer (llm-agent-articles), extending
 * WPTP_Image_Composer for the background pipeline, shapes and file output.
 * The rules carried over:
 *  - every point shares one font size — six blocks in six sizes read as a bug;
 *  - text is never cut: points that don't fit are reported in `overflow`, the
 *    caller shortens them (model) or drops whole points (WPTP_Infographic).
 * Changed for a tall 2:3 canvas: list rows take the height their content
 * needs (not 1/N of the area) and the block is centred between heading and
 * footer, so five points don't float in five half-empty plates.
 */
class WPTP_Infographic_Composer extends WPTP_Image_Composer {

	const ABSOLUTE_MIN_TEXT_SIZE = 11;

	/**
	 * @param array       $preset
	 * @param array       $items  [ ['label' => string, 'text' => string], ... ]
	 * @param string      $title
	 * @param string      $footer
	 * @param string|null $background_path Source picture for background_mode=source.
	 * @param string      $out_path
	 * @return array{ok:bool,path:string,mime:string,error:string,overflow:array,text_size:int}
	 */
	public static function compose( array $preset, array $items, $title, $footer, $background_path, $out_path ) {
		$prep = self::prepare( $preset, $items, $title, $footer );
		if ( ! $prep['ok'] ) {
			return self::result( false, '', '', $prep['error'] );
		}
		$preset = $prep['preset'];
		$items  = $prep['items'];
		$font   = $prep['font'];

		$canvas = self::new_canvas( (int) $preset['width'], (int) $preset['height'], $preset['background'] );
		self::draw_background( $canvas, $preset, $background_path );

		$area = self::content_area( $preset );
		$area = self::draw_heading( $canvas, $preset, $font, $title, $area, true );
		list( $area, $footer_box ) = self::reserve_footer( $preset, $font, $footer, $area );

		$fit = self::fit_items( $preset, $font, $items, $area );
		if ( empty( $fit['cells'] ) ) {
			self::destroy_image( $canvas );
			return self::result( false, '', '', $fit['error'] );
		}

		self::draw_items( $canvas, $preset, $font, $fit );
		if ( $footer_box ) {
			$size = (int) $preset['footer_size'];
			self::draw_lines( $canvas, array( $footer_box['text'] ), $font, $size, (int) round( $size * 1.3 ), $footer_box['x'], $footer_box['y'], $footer_box['w'], self::colour( $canvas, $preset['footer_color'], '#94a3b8' ), $preset['footer_align'] );
		}
		self::apply_logo( $canvas, $preset );

		$saved = self::save( $canvas, $preset, $out_path );
		self::destroy_image( $canvas );

		if ( ! $saved['ok'] ) {
			return self::result( false, '', '', $saved['error'] );
		}
		return self::result( true, $saved['path'], $saved['mime'], '', $fit['overflow'], $fit['text_size'] );
	}

	/**
	 * Dry run: the same layout maths without drawing — which points overflow
	 * and how many characters each may have. Used before rendering to ask the
	 * model for shorter wording.
	 *
	 * @return array{ok:bool,error:string,overflow:array,text_size:int}
	 */
	public static function measure( array $preset, array $items, $title, $footer ) {
		$prep = self::prepare( $preset, $items, $title, $footer );
		if ( ! $prep['ok'] ) {
			return array( 'ok' => false, 'error' => $prep['error'], 'overflow' => array(), 'text_size' => 0 );
		}

		$area = self::content_area( $prep['preset'] );
		$area = self::draw_heading( null, $prep['preset'], $prep['font'], $title, $area, false );
		list( $area ) = self::reserve_footer( $prep['preset'], $prep['font'], $footer, $area );

		$fit = self::fit_items( $prep['preset'], $prep['font'], $prep['items'], $area );
		return array(
			'ok'        => ! empty( $fit['cells'] ) && empty( $fit['overflow'] ),
			'error'     => $fit['error'],
			'overflow'  => $fit['overflow'],
			'text_size' => $fit['text_size'],
		);
	}

	private static function prepare( array $preset, array $items, $title, $footer ) {
		if ( ! self::text_available() ) {
			return array( 'ok' => false, 'error' => 'GD с FreeType недоступен — инфографику не собрать' );
		}

		$preset = wp_parse_args( $preset, WPTP_Infographic_Presets::schema() );
		$items  = self::normalise_items( $items );
		if ( ! $items ) {
			return array( 'ok' => false, 'error' => 'Нет ни одного тезиса для инфографики' );
		}

		$font = self::resolve_font( array( 'caption_font' => $preset['font'] ) );
		if ( '' === $font ) {
			return array( 'ok' => false, 'error' => 'Не найден TTF-шрифт — загрузите его в настройках' );
		}

		$preset['_text_font'] = self::regular_sibling( $font );

		return array(
			'ok'     => true,
			'error'  => '',
			'preset' => $preset,
			'items'  => $items,
			'font'   => $font,
		);
	}

	private static function content_area( array $preset ) {
		$padding = (int) $preset['padding'];
		return array(
			'x' => $padding,
			'y' => $padding,
			'w' => (int) $preset['width'] - $padding * 2,
			'h' => (int) $preset['height'] - $padding * 2,
		);
	}

	// ----------------------------------------------------------- background

	private static function draw_background( $canvas, array $preset, $background_path ) {
		if ( 'source' === $preset['background_mode'] && $background_path && is_readable( $background_path ) ) {
			$source = @imagecreatefromstring( (string) file_get_contents( $background_path ) ); // phpcs:ignore
			if ( $source ) {
				self::draw_source( $canvas, $source, $preset );
				self::destroy_image( $source );
				self::apply_filters( $canvas, $preset );
				self::apply_tint( $canvas, $preset );
			}
		}
		self::apply_gradient( $canvas, $preset );
	}

	// -------------------------------------------------------------- heading

	/** Draws (or, with $draw=false, only measures) accent + title; returns the area left. */
	private static function draw_heading( $canvas, array $preset, $font, $title, array $area, $draw ) {
		$used = 0;

		if ( $preset['accent_enabled'] && (int) $preset['accent_height'] > 0 ) {
			if ( $draw ) {
				$aw = min( (int) $preset['accent_width'], $area['w'] );
				switch ( $preset['title_align'] ) {
					case 'center':
						$ax = $area['x'] + (int) round( ( $area['w'] - $aw ) / 2 );
						break;
					case 'right':
						$ax = $area['x'] + $area['w'] - $aw;
						break;
					default:
						$ax = $area['x'];
				}
				imagefilledrectangle( $canvas, $ax, $area['y'], $ax + $aw - 1, $area['y'] + (int) $preset['accent_height'] - 1, self::colour( $canvas, $preset['accent_color'], '#e60023' ) );
			}
			$used += (int) $preset['accent_height'] + (int) $preset['accent_gap'];
		}

		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( $preset['title_enabled'] && '' !== $title ) {
			$size      = (int) $preset['title_size'];
			$min       = (int) $preset['title_min_size'];
			$max_lines = (int) $preset['title_max_lines'];

			// Shrink while it's too many lines, or while a single word (an
			// "owner/repository") is wider than the line and would be split.
			$lines = self::wrap( $font, $size, $title, $area['w'] );
			while ( $size > $min && ( count( $lines ) > $max_lines || self::widest_word( $font, $size, $title ) > $area['w'] ) ) {
				$size -= 2;
				$lines = self::wrap( $font, $size, $title, $area['w'] );
			}
			if ( count( $lines ) > $max_lines ) {
				$lines                   = array_slice( $lines, 0, $max_lines );
				$lines[ $max_lines - 1 ] = rtrim( $lines[ $max_lines - 1 ], " \t.,;:-" ) . '…';
			}

			$line_height = (int) round( $size * (float) $preset['title_line_height'] );
			if ( $draw ) {
				self::draw_lines( $canvas, $lines, $font, $size, $line_height, $area['x'], $area['y'] + $used, $area['w'], self::colour( $canvas, $preset['title_color'], '#ffffff' ), $preset['title_align'] );
			}
			$used += $line_height * count( $lines ) + (int) $preset['title_gap'];
		}

		return array(
			'x' => $area['x'],
			'y' => $area['y'] + $used,
			'w' => $area['w'],
			'h' => $area['h'] - $used,
		);
	}

	/** @return array{0:array,1:array|null} area left for the items, footer box */
	private static function reserve_footer( array $preset, $font, $footer, array $area ) {
		$footer = trim( wp_strip_all_tags( (string) $footer ) );
		if ( ! $preset['footer_enabled'] || '' === $footer ) {
			return array( $area, null );
		}

		$size = (int) $preset['footer_size'];
		// One line, shrunk to fit the width rather than wrapped or cut.
		while ( $size > 10 && self::text_width( $font, $size, $footer ) > $area['w'] ) {
			--$size;
		}
		$preset['footer_size'] = $size;

		$line = (int) round( $size * 1.3 );
		$used = $line + (int) $preset['footer_gap'];

		$box = array(
			'x'    => $area['x'],
			'y'    => $area['y'] + $area['h'] - $line,
			'w'    => $area['w'],
			'text' => $footer,
		);

		return array(
			array(
				'x' => $area['x'],
				'y' => $area['y'],
				'w' => $area['w'],
				'h' => $area['h'] - $used,
			),
			$box,
		);
	}

	// ---------------------------------------------------------------- items

	/**
	 * Finds the one font size at which every point fits, and lays out cells.
	 * Points that don't fit even at the minimum size go to `overflow`, with a
	 * character budget for a rewrite.
	 */
	private static function fit_items( array $preset, $font, array $items, array $area ) {
		$count   = count( $items );
		$is_list = 'list' === $preset['layout'];
		$columns = $is_list ? 1 : max( 1, min( (int) $preset['columns'], $count ) );
		$rows    = (int) ceil( $count / $columns );
		$gap_x   = (int) $preset['gap_x'];
		$gap_y   = (int) $preset['gap_y'];
		$pad     = $preset['plate_enabled'] ? (int) $preset['plate_padding'] : 0;

		$cell_w = (int) floor( ( $area['w'] - $gap_x * ( $columns - 1 ) ) / $columns );
		// The fixed share of height a cell may take — exact for a grid, an
		// upper bound per point for a list (where rows are content-sized).
		$share_h = (int) floor( ( $area['h'] - $gap_y * ( $rows - 1 ) ) / max( 1, $rows ) );

		if ( $cell_w < 80 || $share_h < 40 ) {
			return self::no_fit( sprintf( 'Под пункт осталось %d×%d px — уменьшите число пунктов, поля или заголовок.', $cell_w, $share_h ) );
		}

		$inner_w  = $cell_w - $pad * 2;
		$base     = max( 1, (int) $preset['text_size'] );
		$min_size = max( self::ABSOLUTE_MIN_TEXT_SIZE, (int) $preset['text_min_size'] );

		$chosen = 0;
		$best   = null;

		for ( $size = $base; $size >= $min_size; $size-- ) {
			$ratio      = $size / $base;
			$badge_size = 'none' === $preset['badge_style'] ? 0 : max( 14, (int) round( (int) $preset['badge_size'] * $ratio ) );
			$badge_gap  = $badge_size ? max( 5, (int) round( (int) $preset['badge_gap'] * $ratio ) ) : 0;

			// List: badge left of the text. Grid: cells are narrow, badge goes above.
			$text_w = $is_list ? $inner_w - $badge_size - $badge_gap : $inner_w;
			$head_h = $is_list ? 0 : ( $badge_size ? $badge_size + $badge_gap : 0 );
			if ( $text_w < 60 ) {
				continue;
			}

			$measured = self::measure_all( $preset, $font, $items, $size, $text_w );
			$heights  = array();
			foreach ( $measured as $m ) {
				$heights[] = max( $is_list ? $badge_size : 0, $head_h + $m['height'] );
			}

			if ( $is_list ) {
				$total = array_sum( $heights ) + $pad * 2 * $count + $gap_y * ( $count - 1 );
				$fits  = $total <= $area['h'];
			} else {
				$fits = max( $heights ) + $pad * 2 <= $share_h;
			}

			$best   = compact( 'size', 'badge_size', 'badge_gap', 'text_w', 'head_h', 'measured', 'heights' );
			$chosen = $size;
			if ( $fits ) {
				break;
			}
		}

		if ( ! $chosen ) {
			return self::no_fit( 'Под текст пункта осталось меньше 60 px — уменьшите маркер, число колонок или поля.' );
		}

		// Overflow: in both layouts a point may use at most its share of height.
		$overflow = array();
		$limit    = $share_h - $pad * 2;
		if ( $is_list ) {
			$total = array_sum( $best['heights'] ) + $pad * 2 * $count + $gap_y * ( $count - 1 );
			if ( $total <= $area['h'] ) {
				$limit = PHP_INT_MAX; // fits as a whole — long and short points can share.
			}
		}
		foreach ( $best['heights'] as $i => $h ) {
			if ( $h > $limit ) {
				$overflow[] = array(
					'index'      => $i,
					'text'       => $items[ $i ]['text'],
					'fits_chars' => self::estimate_fitting_chars( $font, $chosen, $best['text_w'], max( 1, $share_h - $pad * 2 - $best['head_h'] ), $preset, $items[ $i ] ),
				);
			}
		}

		// Cells.
		$cells = array();
		if ( $is_list ) {
			$total = array_sum( $best['heights'] ) + $pad * 2 * $count + $gap_y * ( $count - 1 );
			$slack = max( 0, $area['h'] - $total );
			// Spend the slack on breathing room between points (capped), centre the rest.
			$extra = $count > 1 ? min( (int) floor( $slack / ( $count - 1 ) ), $gap_y * 2 ) : 0;
			$y     = $area['y'] + (int) floor( ( $slack - $extra * ( $count - 1 ) ) / 2 );
			foreach ( $items as $i => $item ) {
				$h       = $best['heights'][ $i ] + $pad * 2;
				$cells[] = self::cell( $area['x'], $y, $cell_w, $h, $pad, $best, $i );
				$y      += $h + $gap_y + $extra;
			}
		} else {
			$cell_h = min( $share_h, max( $best['heights'] ) + $pad * 2 + 24 );
			$grid_h = $cell_h * $rows + $gap_y * ( $rows - 1 );
			$top    = $area['y'] + (int) floor( max( 0, $area['h'] - $grid_h ) / 2 );
			foreach ( $items as $i => $item ) {
				$col     = $i % $columns;
				$row     = (int) floor( $i / $columns );
				$cells[] = self::cell( $area['x'] + $col * ( $cell_w + $gap_x ), $top + $row * ( $cell_h + $gap_y ), $cell_w, $cell_h, $pad, $best, $i );
			}
		}

		return array(
			'ok'         => empty( $overflow ),
			'cells'      => $cells,
			'error'      => '',
			'overflow'   => $overflow,
			'text_size'  => $chosen,
			'is_list'    => $is_list,
			'badge_size' => $best['badge_size'],
			'badge_gap'  => $best['badge_gap'],
		);
	}

	private static function cell( $x, $y, $w, $h, $pad, array $best, $i ) {
		return array(
			'x'      => (int) $x,
			'y'      => (int) $y,
			'w'      => (int) $w,
			'h'      => (int) $h,
			'inner'  => $pad,
			'text_w' => $best['text_w'],
			'head_h' => $best['head_h'],
			'lines'  => $best['measured'][ $i ]['lines'],
			'label'  => $best['measured'][ $i ]['label_lines'],
		);
	}

	private static function no_fit( $error ) {
		return array(
			'ok'        => false,
			'cells'     => array(),
			'error'     => $error,
			'overflow'  => array(),
			'text_size' => 0,
		);
	}

	/** Wraps every point at one size and returns line sets plus the height each needs. */
	private static function measure_all( array $preset, $font, array $items, $size, $text_w ) {
		$ratio       = (int) $preset['text_size'] > 0 ? $size / (int) $preset['text_size'] : 1;
		$label_size  = max( 8, (int) round( (int) $preset['label_size'] * $ratio ) );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );

		$out = array();
		foreach ( $items as $item ) {
			$label_lines = array();
			$label_h     = 0;
			if ( $preset['label_enabled'] && '' !== $item['label'] ) {
				$label_text  = $preset['label_uppercase'] ? mb_strtoupper( $item['label'] ) : $item['label'];
				$label_lines = self::wrap( $font, $label_size, $label_text, $text_w );
				$label_h     = $label_lh * count( $label_lines ) + (int) $preset['label_gap'];
			}
			$lines = self::wrap( $preset['_text_font'], $size, $item['text'], $text_w );
			$out[] = array(
				'lines'       => $lines,
				'label_lines' => $label_lines,
				'height'      => $label_h + $line_height * count( $lines ),
			);
		}
		return $out;
	}

	/** Rough character budget for a point that overflowed — fed to the rewrite prompt. */
	private static function estimate_fitting_chars( $font, $size, $text_w, $available_h, array $preset, array $item ) {
		$line_height = max( 1, (int) round( $size * (float) $preset['text_line_height'] ) );
		$lines       = max( 1, (int) floor( $available_h / $line_height ) );
		// Cyrillic at the same size is wider than Latin — measure, don't guess.
		$sample = 'строка обычного текста в тезисе';
		$per_px = mb_strlen( $sample ) / max( 1, self::text_width( $preset['_text_font'], $size, $sample ) );
		$budget = (int) floor( $text_w * $per_px * $lines * 0.92 );
		if ( $preset['label_enabled'] && '' !== $item['label'] ) {
			$budget -= mb_strlen( $item['label'] );
		}
		return max( 30, $budget );
	}

	private static function draw_items( $canvas, array $preset, $font, array $fit ) {
		$size        = (int) $fit['text_size'];
		$ratio       = (int) $preset['text_size'] > 0 ? $size / (int) $preset['text_size'] : 1;
		$label_size  = max( 8, (int) round( (int) $preset['label_size'] * $ratio ) );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );

		$text_color  = self::colour( $canvas, $preset['text_color'], '#cbd5e1' );
		$label_color = self::colour( $canvas, $preset['label_color'], '#ffffff' );

		foreach ( $fit['cells'] as $i => $cell ) {
			if ( $preset['plate_enabled'] ) {
				self::rounded_rect( $canvas, $cell['x'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'], (int) $preset['plate_radius'], WPTP_Image_Util::hex_to_rgb( $preset['plate_color'] ), (int) $preset['plate_alpha'] );
				if ( (int) $preset['plate_border_width'] > 0 ) {
					self::stroke_rounded_rect( $canvas, $cell['x'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'], (int) $preset['plate_radius'], (int) $preset['plate_border_width'], WPTP_Image_Util::hex_to_rgb( $preset['plate_border_color'] ) );
				}
			}

			$x = $cell['x'] + $cell['inner'];
			$y = $cell['y'] + $cell['inner'];

			$content_h = $cell['head_h'];
			if ( ! empty( $cell['label'] ) ) {
				$content_h += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}
			$content_h += $line_height * count( $cell['lines'] );
			if ( $fit['is_list'] ) {
				$content_h = max( $content_h, $fit['badge_size'] );
			}

			// List rows are content-sized, so centring is exact; grid cells
			// share a height — top-align there, or badges in a row won't line up.
			if ( $fit['is_list'] ) {
				$slack = max( 0, ( $cell['h'] - $cell['inner'] * 2 ) - $content_h );
				$y    += (int) floor( $slack / 2 );
			}

			if ( $fit['badge_size'] > 0 ) {
				self::draw_badge( $canvas, $preset, $font, $i + 1, $x, $y, $fit['badge_size'] );
			}

			$text_x = $fit['is_list'] ? $x + $fit['badge_size'] + $fit['badge_gap'] : $x;
			$text_y = $fit['is_list'] ? $y : $y + $cell['head_h'];

			if ( ! empty( $cell['label'] ) ) {
				self::draw_lines( $canvas, $cell['label'], $font, $label_size, $label_lh, $text_x, $text_y, $cell['text_w'], $label_color, 'left' );
				$text_y += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}

			self::draw_lines( $canvas, $cell['lines'], $preset['_text_font'], $size, $line_height, $text_x, $text_y, $cell['text_w'], $text_color, 'left' );
		}
	}

	private static function draw_badge( $canvas, array $preset, $font, $number, $x, $y, $size ) {
		$style = $preset['badge_style'];
		$bg    = WPTP_Image_Util::hex_to_rgb( $preset['badge_bg'], array( 230, 0, 35 ) );
		$fg    = self::colour( $canvas, $preset['badge_color'], '#ffffff' );

		imagealphablending( $canvas, true );

		if ( 'bullet' === $style ) {
			$dot = max( 6, (int) round( $size * 0.34 ) );
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $dot, $dot, imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) );
			return;
		}

		if ( 'circle' === $style ) {
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $size, $size, imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) );
		} elseif ( 'square' === $style ) {
			self::rounded_rect( $canvas, $x, $y, $x + $size, $y + $size, (int) round( $size * 0.25 ), $bg, 100 );
		}

		$glyph_size = max( 8, (int) round( $size * ( 'plain' === $style ? 0.62 : 0.46 ) ) );
		$colour     = 'plain' === $style ? imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) : $fg;
		$label      = (string) $number;

		$box = imagettfbbox( $glyph_size, 0, $font, $label );
		$tw  = $box ? abs( $box[2] - $box[0] ) : $glyph_size;
		// Centre on the digit's own ink, not on the font's line box.
		$ink_h = $box ? abs( $box[7] ) - abs( $box[1] ) : $glyph_size;

		imagettftext( $canvas, $glyph_size, 0, $x + (int) round( ( $size - $tw ) / 2 ), $y + (int) round( ( $size + $ink_h ) / 2 ), $colour, $font, $label );
	}

	// -------------------------------------------------------------- helpers

	private static function draw_lines( $canvas, array $lines, $font, $size, $line_height, $x, $y, $max_width, $color, $align = 'left' ) {
		$metrics = imagettfbbox( $size, 0, $font, self::METRIC_SAMPLE );
		$ascent  = $metrics ? abs( $metrics[7] ) : (int) round( $size * 0.8 );

		imagealphablending( $canvas, true );
		foreach ( $lines as $i => $line ) {
			$line_w = self::text_width( $font, $size, $line );
			switch ( $align ) {
				case 'center':
					$lx = $x + (int) round( ( $max_width - $line_w ) / 2 );
					break;
				case 'right':
					$lx = $x + ( $max_width - $line_w );
					break;
				default:
					$lx = $x;
			}
			imagettftext( $canvas, $size, 0, $lx, $y + $ascent + $i * $line_height, $color, $font, $line );
		}
	}

	/** Rounded-rect outline, drawn as nested rings so the thickness stays inside. */
	private static function stroke_rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, $thickness, array $rgb ) {
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
		imagealphablending( $canvas, true );

		for ( $i = 0; $i < $thickness; $i++ ) {
			$r = max( 0, $radius - $i );
			imageline( $canvas, $x1 + $i + $r, $y1 + $i, $x2 - $i - $r, $y1 + $i, $color );
			imageline( $canvas, $x1 + $i + $r, $y2 - $i, $x2 - $i - $r, $y2 - $i, $color );
			imageline( $canvas, $x1 + $i, $y1 + $i + $r, $x1 + $i, $y2 - $i - $r, $color );
			imageline( $canvas, $x2 - $i, $y1 + $i + $r, $x2 - $i, $y2 - $i - $r, $color );
			if ( $r > 0 ) {
				$d = $r * 2;
				imagearc( $canvas, $x1 + $i + $r, $y1 + $i + $r, $d, $d, 180, 270, $color );
				imagearc( $canvas, $x2 - $i - $r, $y1 + $i + $r, $d, $d, 270, 360, $color );
				imagearc( $canvas, $x1 + $i + $r, $y2 - $i - $r, $d, $d, 90, 180, $color );
				imagearc( $canvas, $x2 - $i - $r, $y2 - $i - $r, $d, $d, 0, 90, $color );
			}
		}
	}

	private static function widest_word( $font, $size, $text ) {
		$max = 0;
		foreach ( preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$max = max( $max, self::text_width( $font, $size, $word ) );
		}
		return $max;
	}

	/**
	 * The regular face next to a bold one (DejaVuSans-Bold.ttf → DejaVuSans.ttf),
	 * for the body text of the points: a whole page of bold reads as shouting.
	 * Falls back to the same font when there is no sibling.
	 */
	public static function regular_sibling( $font ) {
		$candidates = array(
			preg_replace( '/-?Bold(\.[ot]tf)$/i', '$1', $font ),
			preg_replace( '/-?Bold(\.[ot]tf)$/i', '-Regular$1', $font ),
			preg_replace( '/Bold/i', 'Regular', $font ),
		);
		foreach ( $candidates as $path ) {
			if ( $path && $path !== $font && is_readable( $path ) ) {
				return $path;
			}
		}
		return $font;
	}

	private static function colour( $canvas, $hex, $fallback ) {
		$rgb = WPTP_Image_Util::hex_to_rgb( $hex, WPTP_Image_Util::hex_to_rgb( $fallback ) );
		return imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
	}

	public static function normalise_items( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'text' => $item );
			}
			$text = trim( wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'label' => trim( wp_strip_all_tags( (string) ( $item['label'] ?? '' ) ) ),
				'text'  => preg_replace( '/\s+/u', ' ', $text ),
			);
		}
		return $out;
	}

	private static function result( $ok, $path, $mime, $error, $overflow = array(), $text_size = 0 ) {
		return array(
			'ok'        => (bool) $ok,
			'path'      => $path,
			'mime'      => $mime,
			'error'     => $error,
			'overflow'  => $overflow,
			'text_size' => (int) $text_size,
		);
	}
}
