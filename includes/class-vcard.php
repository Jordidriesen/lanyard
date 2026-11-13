<?php
/**
 * vCard 3.0 builder (RFC 2426).
 *
 * Adapted from Business Card Block 2.1.7 by bPlugins (includes/Card/VCard.php,
 * GPLv3 or later): escaping, line folding and name splitting are kept; the
 * contact-type registry was replaced by this fork's fixed slots.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VCard {

	const FOLD_AT = 75;

	/**
	 * Build the vCard text.
	 *
	 * @param array $model   Card_Data::build() output.
	 * @param bool  $compact Leave out PHOTO and LinkedIn (keeps QR codes small).
	 * @return string CRLF-separated vCard, or '' when there is nothing to export.
	 */
	public static function build( $model, $compact = false ) {
		$identity = isset( $model['identity'] ) ? $model['identity'] : array();
		$contacts = isset( $model['contacts'] ) ? $model['contacts'] : array();
		$name     = isset( $identity['name'] ) ? (string) $identity['name'] : '';

		if ( '' === $name && empty( $contacts ) ) {
			return '';
		}

		$lines = array( 'BEGIN:VCARD', 'VERSION:3.0' );

		if ( '' !== $name ) {
			$lines[] = 'FN:' . self::escape( $name );
			$lines[] = 'N:' . self::structured_name( $name );
		} elseif ( ! empty( $identity['organization'] ) ) {
			$lines[] = 'FN:' . self::escape( $identity['organization'] );
			$lines[] = 'N:;;;;';
		}

		if ( ! empty( $identity['title'] ) ) {
			$lines[] = 'TITLE:' . self::escape( $identity['title'] );
		}
		if ( ! empty( $identity['organization'] ) ) {
			$lines[] = 'ORG:' . self::escape( $identity['organization'] );
		}

		if ( ! $compact ) {
			$photo = self::photo_url( $identity );
			if ( '' !== $photo ) {
				$lines[] = 'PHOTO;VALUE=URI:' . self::escape_uri( $photo );
			}
		}

		foreach ( $contacts as $row ) {
			$text = isset( $row['text'] ) ? (string) $row['text'] : '';
			$href = isset( $row['href'] ) ? (string) $row['href'] : '';

			switch ( isset( $row['type'] ) ? $row['type'] : '' ) {
				case 'phone':
					$lines[] = 'TEL;TYPE=WORK,VOICE:' . self::escape( $text );
					break;
				case 'mobile':
					$lines[] = 'TEL;TYPE=CELL:' . self::escape( $text );
					break;
				case 'email':
					if ( is_email( $text ) ) {
						$lines[] = 'EMAIL;TYPE=INTERNET:' . self::escape( $text );
					}
					break;
				case 'website':
					if ( '' !== $href ) {
						$lines[] = 'URL:' . self::escape_uri( $href );
					}
					break;
				case 'linkedin':
					if ( ! $compact && '' !== $href ) {
						$lines[] = 'X-SOCIALPROFILE;TYPE=linkedin:' . self::escape_uri( $href );
					}
					break;
				case 'address':
					$lines[] = 'ADR;TYPE=WORK:;;' . self::escape( $text ) . ';;;;';
					break;
			}
		}

		$lines[] = 'END:VCARD';

		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Safe file name for the download.
	 *
	 * @param array $model Card model.
	 * @return string
	 */
	public static function filename( $model ) {
		$name = ! empty( $model['identity']['name'] ) ? $model['identity']['name'] : ( ! empty( $model['identity']['organization'] ) ? $model['identity']['organization'] : '' );
		$name = sanitize_file_name( remove_accents( $name ) );
		$name = trim( preg_replace( '/-+/', '-', preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ) ), '-.' );

		return ( '' === $name ? 'contact' : substr( $name, 0, 60 ) ) . '.vcf';
	}

	/**
	 * Absolute URL of the avatar, if any.
	 *
	 * @param array $identity Identity part of the model.
	 * @return string
	 */
	private static function photo_url( $identity ) {
		if ( ! empty( $identity['avatar']['id'] ) ) {
			$src = wp_get_attachment_image_url( (int) $identity['avatar']['id'], 'medium' );
			return $src ? (string) $src : '';
		}

		return ! empty( $identity['avatar']['url'] ) ? (string) $identity['avatar']['url'] : '';
	}

	/**
	 * Split a display name into the five N components.
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	private static function structured_name( $name ) {
		$parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $parts ) ) {
			return ';;;;';
		}
		if ( 1 === count( $parts ) ) {
			return ';' . self::escape( $parts[0] ) . ';;;';
		}

		$family = array_pop( $parts );
		$given  = array_shift( $parts );

		return self::escape( $family ) . ';' . self::escape( $given ) . ';' . self::escape( implode( ' ', $parts ) ) . ';;';
	}

	/**
	 * Escape a TEXT value (RFC 2426 section 5).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape( $value ) {
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $value );

		return str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\,' ), $value );
	}

	/**
	 * Escape a URI value.
	 *
	 * @param string $value Raw URI.
	 * @return string
	 */
	private static function escape_uri( $value ) {
		$value = str_replace( array( "\r", "\n" ), '', (string) $value );

		return str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\,' ), $value );
	}

	/**
	 * Fold a line at 75 octets without splitting a UTF-8 character.
	 *
	 * @param string $line Content line.
	 * @return string
	 */
	private static function fold( $line ) {
		if ( strlen( $line ) <= self::FOLD_AT ) {
			return $line;
		}

		$out       = '';
		$remaining = $line;
		$limit     = self::FOLD_AT;

		while ( strlen( $remaining ) > $limit ) {
			$chunk = substr( $remaining, 0, $limit );
			$back  = 0;
			while ( strlen( $chunk ) > 1 && $back < 4 && 0x80 === ( ord( $remaining[ strlen( $chunk ) ] ) & 0xC0 ) ) {
				$chunk = substr( $chunk, 0, -1 );
				$back++;
			}
			$out      .= $chunk . "\r\n ";
			$remaining = substr( $remaining, strlen( $chunk ) );
			$limit     = self::FOLD_AT - 1;
		}

		return $out . $remaining;
	}
}
