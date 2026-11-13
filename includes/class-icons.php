<?php
/**
 * Outline icons (Tabler Icons, MIT).
 *
 * Path data copied unchanged from the official repository
 * https://github.com/tabler/tabler-icons (icons/outline/*.svg, main branch,
 * read October 2026), MIT licence, Copyright (c) 2020-2026 Paweł Kuna.
 * Only the "d" attributes are kept; the SVG wrapper is built here with fixed
 * attributes. Nothing is loaded remotely.
 *
 * Used by the card (Profile, the only look since 2.0.0): the contact rows, the
 * QR and close buttons and the "Add to contacts" icon. The filled glyph set
 * of 1.3.0 and earlier was removed. The SVG keeps Tabler's stroke width of 2
 * at 24px; style.scss draws icons that render below 24px at 1.75 (the CSS
 * stroke-width property overrides the presentation attribute). Every icon
 * is inline, aria-hidden="true", focusable="false" and drawn in currentColor.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Icons {

	/**
	 * Contact type => outline icon name.
	 */
	const CONTACT = array(
		'email'    => 'mail',
		'phone'    => 'phone',
		'mobile'   => 'device-mobile',
		'website'  => 'world',
		'linkedin' => 'brand-linkedin',
		'address'  => 'map-pin',
	);

	/**
	 * Icon name => path data (Tabler Icons outline, 24 × 24, stroke 2).
	 *
	 * @return array<string, string[]>
	 */
	private static function paths() {
		return array(
			'mail'           => array(
				'M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10',
				'M3 7l9 6l9 -6',
			),
			'phone'          => array(
				'M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2',
			),
			'device-mobile'  => array(
				'M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14',
				'M11 4h2',
				'M12 17v.01',
			),
			'world'          => array(
				'M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0',
				'M3.6 9h16.8',
				'M3.6 15h16.8',
				'M11.5 3a17 17 0 0 0 0 18',
				'M12.5 3a17 17 0 0 1 0 18',
			),
			'brand-linkedin' => array(
				'M8 11v5',
				'M8 8v.01',
				'M12 16v-5',
				'M16 16v-3a2 2 0 1 0 -4 0',
				'M3 7a4 4 0 0 1 4 -4h10a4 4 0 0 1 4 4v10a4 4 0 0 1 -4 4h-10a4 4 0 0 1 -4 -4l0 -10',
			),
			'map-pin'        => array(
				'M9 11a3 3 0 1 0 6 0a3 3 0 0 0 -6 0',
				'M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0',
			),
			'qrcode'         => array(
				'M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4',
				'M7 17l0 .01',
				'M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4',
				'M7 7l0 .01',
				'M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4',
				'M17 7l0 .01',
				'M14 14l3 0',
				'M20 14l0 .01',
				'M14 14l0 3',
				'M14 20l3 0',
				'M17 17l3 0',
				'M20 17l0 3',
			),
			'plus'           => array(
				'M12 5l0 14',
				'M5 12l14 0',
			),
			'x'              => array(
				'M18 6l-12 12',
				'M6 6l12 12',
			),
		);
	}

	/**
	 * Inline outline SVG. Static markup: fixed attributes, path data from
	 * paths() only (never from input), each value passed through esc_attr().
	 *
	 * @param string $name Icon name (a key of paths()).
	 * @return string SVG markup, or '' for an unknown name.
	 */
	public static function svg( $name ) {
		$paths = self::paths();
		if ( ! is_string( $name ) || ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
		foreach ( $paths[ $name ] as $d ) {
			$out .= '<path d="' . esc_attr( $d ) . '"/>';
		}

		return $out . '</svg>';
	}

	/**
	 * Outline icon for a contact type.
	 *
	 * @param string $type Contact type (a key of CONTACT).
	 * @return string
	 */
	public static function contact( $type ) {
		$map = self::CONTACT;

		return is_string( $type ) && isset( $map[ $type ] ) ? self::svg( $map[ $type ] ) : '';
	}
}
