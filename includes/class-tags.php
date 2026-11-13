<?php
/**
 * Inline dynamic-data tags.
 *
 * Syntax (stored as plain text inside a content attribute):
 *
 *   {{lnyd source:<source>|<field:<slug> or acf:<key>>[|arg:value...]}}
 *
 *   source    post | post_ref | site | user | option          (required)
 *   field     core field slug from Core_Source::fields()      (one of field/acf)
 *   acf       ACF field key (the picker stores field_...) or  (one of field/acf)
 *             a name; a name resolves only when exactly one top-level
 *             field uses it (2.1.0), see Acf_Source::lookup_field()
 *   type      post type slug                                  (post_ref only, required)
 *   id        post ID                                         (post_ref only, required)
 *   user      author | <user ID>                              (user only, default author)
 *   fallback  plain text used when the value is empty         (optional)
 *   format    site | iso | dmy | mdy | long                   (date fields only)
 *   size      registered image size or full                   (image slot only)
 *
 * Example: "Call {{lnyd source:post|acf:field_phone}} or {{lnyd source:user|field:display_name|fallback:our office}}".
 *
 * Parsing rules (server-side only):
 *   - one strict regex; a value may not contain "|", "{", "}" or a line break;
 *   - every key and value is checked against an allow-list (parse_args());
 *   - a field string is cut at MAX_LENGTH bytes and at most MAX_TAGS tags are
 *     resolved; further tags are removed;
 *   - one preg_replace_callback() pass: resolved values are never parsed
 *     again, so a value that itself looks like a tag stays literal text;
 *   - static text and resolved values are joined as plain text; Card_Data
 *     sanitises the result and the Renderer escapes it for its output context.
 *
 * Link slots (phone, mobile, email, website, linkedin) take a single tag only:
 * see Card_Data::build().
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tags {

	/**
	 * Strict tag pattern. Group 1 holds the arguments.
	 */
	const PATTERN = '/\{\{lnyd ([a-z]{2,8}:[^|{}\r\n]{0,400}(?:\|[a-z]{2,8}:[^|{}\r\n]{0,400}){0,8})\}\}/';

	/**
	 * Maximum tags resolved per field.
	 */
	const MAX_TAGS = 20;

	/**
	 * Maximum field length in bytes that is parsed.
	 */
	const MAX_LENGTH = 4000;

	/**
	 * Maximum fallback length in characters.
	 */
	const MAX_FALLBACK = 120;

	/**
	 * Allowed sources.
	 */
	const SOURCES = array( 'post', 'post_ref', 'site', 'user', 'option' );

	/**
	 * Allowed argument keys.
	 */
	const KEYS = array( 'source', 'field', 'acf', 'type', 'id', 'user', 'fallback', 'format', 'size' );

	/**
	 * Whether a string contains at least one well-formed tag.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function has_tags( $value ) {
		return is_string( $value ) && false !== strpos( $value, '{{lnyd ' ) && 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * The parsed tag when the whole (trimmed) value is exactly one tag.
	 *
	 * @param mixed $value Value.
	 * @return array|null Validated args, or null.
	 */
	public static function single( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_LENGTH ) {
			return null;
		}

		$value = trim( $value );

		if ( ! preg_match( self::PATTERN, $value, $m ) || $m[0] !== $value ) {
			return null;
		}

		return self::parse_args( $m[1] );
	}

	/**
	 * Parse and validate the argument list of one tag.
	 *
	 * @param string $raw Arguments ("source:post|field:title").
	 * @return array|null Normalised args, or null when anything is off.
	 */
	public static function parse_args( $raw ) {
		$args = array(
			'source'   => '',
			'field'    => '',
			'acf'      => '',
			'type'     => '',
			'id'       => 0,
			'user'     => 'author',
			'fallback' => '',
			'format'   => '',
			'size'     => '',
		);
		$seen = array();

		foreach ( explode( '|', (string) $raw ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			if ( 2 !== count( $parts ) ) {
				return null;
			}
			list( $key, $value ) = $parts;
			if ( ! in_array( $key, self::KEYS, true ) || isset( $seen[ $key ] ) ) {
				return null; // Unknown or duplicate key.
			}
			$seen[ $key ] = true;
			$args[ $key ] = $value;
		}

		$source = $args['source'];
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return null;
		}

		// Exactly one of field / acf, and only where that kind of field exists.
		$has_field = isset( $seen['field'] );
		$has_acf   = isset( $seen['acf'] );
		if ( $has_field === $has_acf ) {
			return null;
		}
		if ( $has_field ) {
			$core = Core_Source::fields();
			if ( ! isset( $core[ $source ][ $args['field'] ] ) ) {
				return null;
			}
		} elseif ( 'site' === $source || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $args['acf'] ) ) {
			return null;
		}

		// post_ref needs type and id; nobody else may carry them.
		if ( 'post_ref' === $source ) {
			if ( ! isset( $seen['type'], $seen['id'] ) || ! preg_match( '/^[1-9][0-9]{0,18}$/', $args['id'] ) ) {
				return null;
			}
			$args['type'] = Acf_Source::sanitize_post_type( $args['type'] );
			$args['id']   = (int) $args['id'];
			if ( '' === $args['type'] ) {
				return null;
			}
		} elseif ( isset( $seen['type'] ) || isset( $seen['id'] ) ) {
			return null;
		}

		if ( isset( $seen['user'] ) ) {
			if ( 'user' !== $source || ( 'author' !== $args['user'] && ! preg_match( '/^[1-9][0-9]{0,18}$/', $args['user'] ) ) ) {
				return null;
			}
		}

		$args['fallback'] = self::clean_fallback( $args['fallback'] );

		if ( isset( $seen['format'] ) ) {
			$kind = $has_field ? Core_Source::field_kind( $source, $args['field'], 'text' ) : '';
			if ( 'date' !== $kind || ! in_array( $args['format'], Core_Source::DATE_FORMATS, true ) ) {
				return null;
			}
		} else {
			$args['format'] = 'site';
		}

		if ( isset( $seen['size'] ) && ! self::valid_size( $args['size'] ) ) {
			return null;
		}

		return $args;
	}

	/**
	 * Plain-text fallback: no tag delimiters, no line breaks, capped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_fallback( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( preg_replace( '/[|{}\r\n\t]+/', ' ', (string) $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_FALLBACK ) : substr( $value, 0, self::MAX_FALLBACK );
	}

	/**
	 * Whether an image size name is registered.
	 *
	 * @param string $size Size name.
	 * @return bool
	 */
	public static function valid_size( $size ) {
		if ( ! is_string( $size ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/', $size ) ) {
			return false;
		}

		return 'full' === $size || in_array( $size, get_intermediate_image_sizes(), true );
	}

	/**
	 * Replace every tag in a text field with its resolved plain-text value.
	 *
	 * @param mixed $value   Attribute value.
	 * @param array $context { post_id: int }.
	 * @return string Plain text (not yet escaped).
	 */
	public static function render_text( $value, $context ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( strlen( $value ) > self::MAX_LENGTH ) {
			$value = substr( $value, 0, self::MAX_LENGTH );
		}

		if ( false === strpos( $value, '{{lnyd ' ) ) {
			return $value;
		}

		$count = 0;
		$out   = preg_replace_callback(
			self::PATTERN,
			static function ( $m ) use ( &$count, $context ) {
				++$count;
				if ( $count > self::MAX_TAGS ) {
					return '';
				}
				$args = self::parse_args( $m[1] );
				if ( null === $args ) {
					return '';
				}
				return self::text_value( $args, $context );
			},
			$value
		);

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Resolved plain text for one validated tag, with its fallback.
	 *
	 * @param array $args    Validated args.
	 * @param array $context Render context.
	 * @return string
	 */
	public static function text_value( $args, $context ) {
		$value = self::resolve( $args, 'text', $context );
		$text  = is_scalar( $value ) ? Card_Data::text( (string) $value ) : '';

		return '' !== $text ? $text : Card_Data::text( $args['fallback'] );
	}

	/**
	 * Resolve one validated tag.
	 *
	 * @param array  $args    Validated args.
	 * @param string $kind    'text' or 'image'.
	 * @param array  $context { post_id: int }.
	 * @return mixed|null
	 */
	public static function resolve( $args, $kind, $context ) {
		if ( '' !== $args['field'] ) {
			return Core_Source::resolve( $args, $kind, $context );
		}

		$acf_context = array(
			'post_id'       => isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0,
			'user_id'       => 'author' === $args['user'] ? 0 : absint( $args['user'] ),
			'ref_post_id'   => (int) $args['id'],
			'ref_post_type' => $args['type'],
		);

		return Acf_Source::resolve(
			array(
				'from'  => $args['source'],
				'field' => $args['acf'],
			),
			$kind,
			$acf_context
		);
	}

	/**
	 * Build a tag string from args (used by the 1.1.0 migration).
	 *
	 * @param array $args Args (source plus field|acf and optional extras).
	 * @return string
	 */
	public static function build( $args ) {
		$parts = array();

		foreach ( self::KEYS as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
				continue;
			}
			$value = 'fallback' === $key ? self::clean_fallback( $args[ $key ] ) : (string) $args[ $key ];
			if ( '' !== $value ) {
				$parts[] = $key . ':' . $value;
			}
		}

		return '{{lnyd ' . implode( '|', $parts ) . '}}';
	}

	/**
	 * Convert 1.1.0 attributes (sources, userId, refPostType, refPostId) into
	 * inline tags. Mirrors migrateV110() in src/card/deprecated.js; used at
	 * render time for posts that were not opened in the editor since 1.1.0.
	 *
	 * @param array $attributes Attributes.
	 * @return array
	 */
	public static function migrate_v110( $attributes ) {
		if ( empty( $attributes['sources'] ) || ! is_array( $attributes['sources'] ) ) {
			return $attributes;
		}

		$slots = Card_Data::text_fields();
		$slots[] = 'avatar';

		foreach ( $slots as $slot ) {
			if ( ! isset( $attributes['sources'][ $slot ] ) ) {
				continue;
			}
			$spec = Acf_Source::validate_spec( $attributes['sources'][ $slot ] );
			if ( null === $spec ) {
				continue;
			}

			$args = array(
				'source' => $spec['from'],
				'acf'    => $spec['field'],
			);

			if ( 'post_ref' === $spec['from'] ) {
				$type = isset( $attributes['refPostType'] ) ? Acf_Source::sanitize_post_type( $attributes['refPostType'] ) : '';
				$id   = isset( $attributes['refPostId'] ) ? absint( $attributes['refPostId'] ) : 0;
				if ( '' === $type || $id <= 0 ) {
					continue; // 1.1.0 showed the static value here.
				}
				$args['type'] = $type;
				$args['id']   = $id;
			} elseif ( 'user' === $spec['from'] ) {
				$user         = isset( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0;
				$args['user'] = $user > 0 ? (string) $user : 'author';
			}

			if ( 'avatar' === $slot ) {
				if ( empty( $attributes['avatarTag'] ) ) {
					$attributes['avatarTag'] = self::build( $args );
				}
				continue;
			}

			$static = isset( $attributes[ $slot ] ) && is_scalar( $attributes[ $slot ] ) ? (string) $attributes[ $slot ] : '';
			if ( self::has_tags( $static ) ) {
				continue;
			}
			$args['fallback']    = $static;
			$attributes[ $slot ] = self::build( $args );
		}

		unset( $attributes['sources'], $attributes['userId'], $attributes['refPostType'], $attributes['refPostId'] );

		return $attributes;
	}
}
