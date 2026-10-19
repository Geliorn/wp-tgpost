<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presets for infographic pictures — the same fields, ranges and sanitiser
 * as WPPF_Infographic_Presets in wp-pinfeed (and the vocabulary of
 * LAA_Infographic_Presets in llm-agent-articles: heading, numbered items as
 * a list or a grid of blocks, badge, label, footer, logo), so a preset
 * exported from wp-pinfeed imports here unchanged. Only the starting values
 * differ: a square 1080×1080 for the Telegram feed, 4 points, sizes to match.
 *
 * Background field names (fit, focal, filters, tint, gradient, logo_*) are
 * the ones WPTP_Image_Composer's pipeline reads, because
 * WPTP_Infographic_Composer hands them straight to it.
 */
class WPTP_Infographic_Presets {

	const OPTION_KEY = 'wptp_infographic_presets';

	/** wp-pinfeed's presets option — the source of "import from wp-pinfeed". */
	const PINFEED_OPTION = 'wppf_infographic_presets';

	public static function layouts() {
		return array(
			'list' => 'Нумерованный список',
			'grid' => 'Сетка блоков',
		);
	}

	public static function badge_styles() {
		return array(
			'circle' => 'Номер в круге',
			'square' => 'Номер в квадрате',
			'plain'  => 'Номер без подложки',
			'bullet' => 'Точка-маркер',
			'none'   => 'Без маркера',
		);
	}

	public static function background_modes() {
		return array(
			'solid'  => 'Сплошной цвет (+ градиент)',
			'source' => 'Картинка записи, затемнённая',
		);
	}

	/** Every field with its default — the form, the sanitiser and the composer all read this. */
	public static function schema() {
		return array(
			'name'                => 'Новый пресет',

			// colours: preset | theme | rotate (WPTP_Theme_Palette)
			'palette_source'      => 'preset',
			'palette_layout'      => 'accent',

			// canvas
			'width'               => 1080,
			'height'              => 1080,
			'background'          => '#0f172a',
			'padding'             => 60,
			'format'              => 'jpg',
			'quality'             => 88,
			'font'                => '',

			// background
			'background_mode'     => 'solid',
			'fit'                 => 'cover',
			'focal'               => 'middle-center',
			'grayscale'           => 0,
			'brightness'          => -20,
			'contrast'            => 0,
			'blur'                => 2,
			'tint_hex'            => '#0f172a',
			'tint_alpha'          => 78,
			'gradient_enabled'    => 0,
			'gradient_direction'  => 'to-bottom',
			'gradient_from_hex'   => '#000000',
			'gradient_from_alpha' => 0,
			'gradient_to_hex'     => '#000000',
			'gradient_to_alpha'   => 60,

			// accent bar above the heading
			'accent_enabled'      => 1,
			'accent_color'        => '#2aabee',
			'accent_width'        => 110,
			'accent_height'       => 8,
			'accent_gap'          => 24,

			// heading
			'title_enabled'       => 1,
			'title_size'          => 52,
			'title_min_size'      => 34,
			'title_color'         => '#ffffff',
			'title_align'         => 'left',
			'title_max_lines'     => 3,
			'title_line_height'   => 1.25,
			'title_gap'           => 32,

			// layout
			'layout'              => 'list',
			'columns'             => 2,
			'gap_x'               => 24,
			'gap_y'               => 16,

			// item plate
			'plate_enabled'       => 1,
			'plate_color'         => '#1e293b',
			'plate_alpha'         => 92,
			'plate_radius'        => 18,
			'plate_padding'       => 20,
			'plate_border_width'  => 0,
			'plate_border_color'  => '#334155',

			// badge
			'badge_style'         => 'circle',
			'badge_size'          => 46,
			'badge_bg'            => '#2aabee',
			'badge_color'         => '#ffffff',
			'badge_gap'           => 18,

			// item label — a short bold line above the thesis
			'label_enabled'       => 1,
			'label_size'          => 28,
			'label_color'         => '#ffffff',
			'label_uppercase'     => 0,
			'label_gap'           => 8,

			// item text
			'text_size'           => 26,
			'text_min_size'       => 18,
			'text_color'          => '#cbd5e1',
			'text_line_height'    => 1.3,

			// footer — the text itself comes from the channel ("Подпись внизу")
			'footer_enabled'      => 1,
			'footer_size'         => 24,
			'footer_color'        => '#94a3b8',
			'footer_align'        => 'left',
			'footer_gap'          => 24,

			// logo
			'logo_id'             => 0,
			'logo_position'       => 'bottom-right',
			'logo_width'          => 14,
			'logo_alpha'          => 95,
			'logo_offset_x'       => 60,
			'logo_offset_y'       => 48,
		);
	}

	public static function ensure_defaults() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			update_option( self::OPTION_KEY, self::seed_presets(), false );
		}
	}

	/** @return array<string,array> keyed by preset id */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::seed_presets();
		}
		$out = array();
		foreach ( $stored as $id => $preset ) {
			$out[ $id ] = wp_parse_args( is_array( $preset ) ? $preset : array(), self::schema() );
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		return $all ? reset( $all ) : self::schema();
	}

	public static function save( $id, array $values ) {
		$all = self::all();
		$id  = sanitize_key( $id );
		if ( '' === $id ) {
			$id = 'ig-' . substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
		}
		$all[ $id ] = self::sanitize( $values );
		update_option( self::OPTION_KEY, $all, false );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		if ( empty( $all ) ) {
			$all = self::seed_presets();
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function sanitize( array $values ) {
		$schema = self::schema();
		$out    = array();

		$int_ranges = array(
			'width'               => array( 400, 3000 ),
			'height'              => array( 400, 4500 ),
			'padding'             => array( 0, 300 ),
			'quality'             => array( 1, 100 ),
			'grayscale'           => array( 0, 1 ),
			'brightness'          => array( -255, 255 ),
			'contrast'            => array( -100, 100 ),
			'blur'                => array( 0, 10 ),
			'tint_alpha'          => array( 0, 100 ),
			'gradient_enabled'    => array( 0, 1 ),
			'gradient_from_alpha' => array( 0, 100 ),
			'gradient_to_alpha'   => array( 0, 100 ),
			'accent_enabled'      => array( 0, 1 ),
			'accent_width'        => array( 0, 2000 ),
			'accent_height'       => array( 0, 60 ),
			'accent_gap'          => array( 0, 200 ),
			'title_enabled'       => array( 0, 1 ),
			'title_size'          => array( 12, 200 ),
			'title_min_size'      => array( 10, 200 ),
			'title_max_lines'     => array( 1, 6 ),
			'title_gap'           => array( 0, 200 ),
			'columns'             => array( 1, 4 ),
			'gap_x'               => array( 0, 120 ),
			'gap_y'               => array( 0, 120 ),
			'plate_enabled'       => array( 0, 1 ),
			'plate_alpha'         => array( 0, 100 ),
			'plate_radius'        => array( 0, 80 ),
			'plate_padding'       => array( 0, 100 ),
			'plate_border_width'  => array( 0, 12 ),
			'badge_size'          => array( 0, 160 ),
			'badge_gap'           => array( 0, 80 ),
			'label_enabled'       => array( 0, 1 ),
			'label_size'          => array( 8, 120 ),
			'label_uppercase'     => array( 0, 1 ),
			'label_gap'           => array( 0, 60 ),
			'text_size'           => array( 8, 120 ),
			'text_min_size'       => array( 6, 120 ),
			'footer_enabled'      => array( 0, 1 ),
			'footer_size'         => array( 8, 80 ),
			'footer_gap'          => array( 0, 120 ),
			'logo_id'             => array( 0, PHP_INT_MAX ),
			'logo_width'          => array( 2, 100 ),
			'logo_alpha'          => array( 0, 100 ),
			'logo_offset_x'       => array( 0, 2000 ),
			'logo_offset_y'       => array( 0, 2000 ),
		);

		foreach ( $schema as $key => $default ) {
			$value = $values[ $key ] ?? $default;

			if ( isset( $int_ranges[ $key ] ) ) {
				$out[ $key ] = max( $int_ranges[ $key ][0], min( $int_ranges[ $key ][1], (int) $value ) );
				continue;
			}

			switch ( $key ) {
				case 'title_line_height':
				case 'text_line_height':
					$out[ $key ] = max( 0.8, min( 3.0, (float) $value ) );
					break;
				case 'background':
				case 'tint_hex':
				case 'gradient_from_hex':
				case 'gradient_to_hex':
				case 'accent_color':
				case 'title_color':
				case 'plate_color':
				case 'plate_border_color':
				case 'badge_bg':
				case 'badge_color':
				case 'label_color':
				case 'text_color':
				case 'footer_color':
					$out[ $key ] = WPTP_Image_Util::sanitize_hex( $value, $default );
					break;
				case 'layout':
					$out[ $key ] = isset( self::layouts()[ $value ] ) ? $value : $default;
					break;
				case 'badge_style':
					$out[ $key ] = isset( self::badge_styles()[ $value ] ) ? $value : $default;
					break;
				case 'background_mode':
					$out[ $key ] = isset( self::background_modes()[ $value ] ) ? $value : $default;
					break;
				case 'palette_source':
					$out[ $key ] = isset( WPTP_Theme_Palette::sources()[ $value ] ) ? $value : $default;
					break;
				case 'palette_layout':
					$out[ $key ] = isset( WPTP_Theme_Palette::layouts()[ $value ] ) ? $value : $default;
					break;
				case 'fit':
					$out[ $key ] = isset( WPTP_Image_Util::fit_modes()[ $value ] ) ? $value : $default;
					break;
				case 'focal':
				case 'logo_position':
					$out[ $key ] = isset( WPTP_Image_Util::positions()[ $value ] ) ? $value : $default;
					break;
				case 'gradient_direction':
					$out[ $key ] = isset( WPTP_Image_Util::gradient_directions()[ $value ] ) ? $value : $default;
					break;
				case 'title_align':
				case 'footer_align':
					$out[ $key ] = in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : $default;
					break;
				case 'format':
					$out[ $key ] = in_array( $value, array( 'jpg', 'webp', 'png' ), true ) ? $value : $default;
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( '' === trim( $out['name'] ) ) {
			$out['name'] = 'Без названия';
		}
		foreach ( array( 'title_min_size' => 'title_size', 'text_min_size' => 'text_size' ) as $min => $max ) {
			if ( $out[ $min ] > $out[ $max ] ) {
				$out[ $min ] = $out[ $max ];
			}
		}

		return $out;
	}

	/** Three starting points: dark list, dark grid, light list. */
	public static function seed_presets() {
		$base = self::schema();

		$list = wp_parse_args( array( 'name' => 'Тёмный список' ), $base );

		$grid = wp_parse_args(
			array(
				'name'          => 'Сетка 2 колонки',
				'layout'        => 'grid',
				'columns'       => 2,
				'badge_style'   => 'square',
				'badge_size'    => 42,
				'badge_gap'     => 14,
				'label_size'    => 26,
				'text_size'     => 24,
				'text_min_size' => 17,
			),
			$base
		);

		$light = wp_parse_args(
			array(
				'name'         => 'Светлый список',
				'background'   => '#fff7ed',
				'tint_hex'     => '#fff7ed',
				'accent_color' => '#ea580c',
				'title_color'  => '#1f2937',
				'plate_color'  => '#ffffff',
				'plate_alpha'  => 100,
				'plate_border_width' => 2,
				'plate_border_color' => '#fed7aa',
				'badge_bg'     => '#ea580c',
				'label_color'  => '#111827',
				'text_color'   => '#4b5563',
				'footer_color' => '#9ca3af',
			),
			$base
		);

		return array(
			'ig-list'  => $list,
			'ig-grid'  => $grid,
			'ig-light' => $light,
			'ig-theme' => self::theme_preset(),
		);
	}

	/**
	 * Copies wp-pinfeed's presets in (same schema). Ids get a "pf-" prefix so
	 * they never overwrite this plugin's own presets.
	 *
	 * @return int Number of presets imported.
	 */
	public static function import_from_pinfeed() {
		$source = get_option( self::PINFEED_OPTION, array() );
		if ( ! is_array( $source ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $source as $id => $preset ) {
			if ( is_array( $preset ) ) {
				self::save( 'pf-' . sanitize_key( (string) $id ), $preset );
				++$count;
			}
		}
		return $count;
	}

	public static function pinfeed_available() {
		$source = get_option( self::PINFEED_OPTION, array() );
		return is_array( $source ) && ! empty( $source );
	}

	/** Dark list whose colours come from the theme, a new palette per post. */
	public static function theme_preset() {
		return wp_parse_args(
			array(
				'name'           => 'Цвета темы (ротация)',
				'palette_source' => 'rotate',
				'palette_layout' => 'accent',
			),
			self::schema()
		);
	}
}
