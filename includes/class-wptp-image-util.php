<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared vocabulary of the image code: the nine-point position grid,
 * gradient directions, fit modes and colour helpers. Used by the infographic
 * presets, their admin form and the composers.
 */
class WPTP_Image_Util {

	public static function positions() {
		return array(
			'top-left'      => 'Сверху слева',
			'top-center'    => 'Сверху по центру',
			'top-right'     => 'Сверху справа',
			'middle-left'   => 'По центру слева',
			'middle-center' => 'По центру',
			'middle-right'  => 'По центру справа',
			'bottom-left'   => 'Снизу слева',
			'bottom-center' => 'Снизу по центру',
			'bottom-right'  => 'Снизу справа',
		);
	}

	public static function gradient_directions() {
		return array(
			'to-bottom' => 'Сверху вниз',
			'to-top'    => 'Снизу вверх',
			'to-right'  => 'Слева направо',
			'to-left'   => 'Справа налево',
		);
	}

	public static function fit_modes() {
		return array(
			'cover'   => 'Заполнить и обрезать',
			'contain' => 'Вписать целиком (с полями)',
		);
	}

	public static function sanitize_hex( $value, $default = '#000000' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = sanitize_hex_color( 0 === strpos( $value, '#' ) ? $value : '#' . $value );
		return $clean ? $clean : $default;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function hex_to_rgb( $hex, $fallback = array( 0, 0, 0 ) ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
