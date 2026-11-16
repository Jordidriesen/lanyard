<?php
/**
 * ACF field resolver.
 *
 * Reads a card field from Advanced Custom Fields (Free or Pro) on the server,
 * at render time. Since 1.2.0 it is called once per inline tag with
 * `acf:<key>` (see class-tags.php); core fields go through Core_Source. Every ACF call is guarded with function_exists(), so the
 * block keeps rendering its static values when ACF is deactivated.
 *
 * Security model: block attributes are written by anyone who can edit a post
 * (contributors included), so a block must not be able to read arbitrary
 * meta. A field is only resolved when it:
 *   1. is a registered, top-level ACF field (a field key looked up with
 *      acf_get_field(), or a field name that exactly one top-level field
 *      uses; sub-fields of repeater, group and flexible content fail closed),
 *   2. has a field type on the allow-list for that card slot, and
 *   3. has ACF's "Allow Access to Value in Editor UI" setting switched on
 *      (`allow_in_bindings`, ACF 6.3.6+), read the way ACF itself reads
 *      it since 2.1.1: refused only when saved as off; a field without a
 *      saved setting (created before ACF 6.3.6) counts as on, as ACF shows
 *      it. ACF applies the same rule to the [acf] shortcode and Block
 *      Bindings, so editors reach exactly the fields ACF itself exposes.
 *
 * Location rules (2.1.0): a field group's location rules decide where ACF
 * shows its edit UI, not who may read a value. Since 2.1.0 they are used to
 * order and label the editor's field list only (matching groups first) and
 * never to refuse a field, for any source. The per-field opt-in above is the
 * gate.
 *
 * Sources:
 *   - post:     the post being rendered (Card_Data::current_post_id(), never
 *               a post ID from attributes);
 *   - post_ref: one other post, chosen in the tag (type/id). Only resolved
 *               for a published, non-password-protected post of an eligible
 *               post type (public or publicly queryable, in REST, not
 *               attachment) whose type equals the tag's type;
 *   - option:   the ACF options page (ACF Pro);
 *   - user:     a chosen user, or the author of the rendered post.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Acf_Source {

	/**
	 * Allowed data sources.
	 */
	const SOURCES = array( 'post', 'post_ref', 'option', 'user' );

	/**
	 * ACF location params that never target a post object.
	 */
	const NON_POST_PARAMS = array( 'user_form', 'user_role', 'options_page', 'taxonomy', 'comment', 'widget', 'nav_menu', 'nav_menu_item', 'block', 'attachment' );

	/**
	 * Per-request cache of eligible post types.
	 *
	 * @var \WP_Post_Type[]|null
	 */
	private static $eligible = null;

	/**
	 * Per-request cache: top-level field name => list of field keys.
	 *
	 * @var array|null
	 */
	private static $names = null;

	/**
	 * Whether ACF (Free or Pro) is active.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'get_field' ) && function_exists( 'acf_get_field' );
	}

	/**
	 * Whether options pages exist (ACF Pro, or another build that ships them).
	 *
	 * @return bool
	 */
	public static function options_available() {
		return self::is_available() && function_exists( 'acf_add_options_page' );
	}

	/**
	 * ACF field types accepted per card slot kind.
	 *
	 * @param string $kind 'text' or 'image'.
	 * @return string[]
	 */
	public static function field_types( $kind ) {
		$types = 'image' === $kind
			? array( 'image' )
			: array( 'text', 'textarea', 'email', 'url', 'number', 'select', 'radio', 'button_group', 'link' );

		/**
		 * Filter the ACF field types a card slot may read.
		 *
		 * @param string[] $types Field types.
		 * @param string   $kind  'text' or 'image'.
		 */
		$types = apply_filters( 'lnyd_acf_field_types', $types, $kind );

		return is_array( $types ) ? array_map( 'strval', $types ) : array();
	}

	/**
	 * Whether a field's type fits the card slot (regardless of the bindings opt-in).
	 *
	 * @param array  $field ACF field array.
	 * @param string $kind  'text' or 'image'.
	 * @return bool
	 */
	public static function is_type_compatible( $field, $kind ) {
		if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['type'] ) ) {
			return false;
		}

		return in_array( $field['type'], self::field_types( $kind ), true );
	}

	/**
	 * Whether a field may be exposed on a card.
	 *
	 * @param array  $field ACF field array.
	 * @param string $kind  'text' or 'image'.
	 * @return bool
	 */
	public static function is_field_allowed( $field, $kind ) {
		if ( ! self::is_type_compatible( $field, $kind ) ) {
			return false;
		}

		// 2.1.1: same rule as ACF/SCF's own Block Bindings and shortcode
		// (includes/Blocks/Bindings.php): refused only when the setting is
		// saved and off. Fields created before ACF 6.3.6 have no saved key;
		// ACF shows the toggle as on for them and lets them through, and
		// re-saving the group does not write the key for untouched fields.
		// Being stricter than ACF made fields the admin sees as allowed
		// unusable. Switching the toggle off still blocks the field.
		$allowed = ! ( array_key_exists( 'allow_in_bindings', $field ) && ! $field['allow_in_bindings'] );

		/**
		 * Filter whether a field may be shown on a business card.
		 *
		 * @param bool   $allowed Whether the field is allowed.
		 * @param array  $field   ACF field array.
		 * @param string $kind    'text' or 'image'.
		 */
		return (bool) apply_filters( 'lnyd_acf_field_allowed', $allowed, $field, $kind );
	}

	/**
	 * Validate a stored binding spec.
	 *
	 * @param mixed $spec Raw spec from block attributes.
	 * @return array|null { from, field } or null when invalid.
	 */
	public static function validate_spec( $spec ) {
		if ( ! is_array( $spec ) || empty( $spec['from'] ) || empty( $spec['field'] ) ) {
			return null;
		}

		$from  = is_string( $spec['from'] ) ? $spec['from'] : '';
		$field = is_string( $spec['field'] ) ? $spec['field'] : '';

		if ( ! in_array( $from, self::SOURCES, true ) ) {
			return null;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $field ) ) {
			return null;
		}

		return array(
			'from'  => $from,
			'field' => $field,
		);
	}

	/**
	 * Normalise a post type slug: '' unless it is a registered post type.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_type( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$slug = sanitize_key( $value );

		return ( $slug === $value && post_type_exists( $slug ) ) ? $slug : '';
	}

	/**
	 * Whether a post type may be used as a "another post" source, ignoring ACF groups.
	 *
	 * Public or publicly queryable, exposed in REST (so the editor can search
	 * it), and not attachment.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_ref_post_type( $post_type ) {
		if ( ! is_string( $post_type ) || '' === $post_type || 'attachment' === $post_type ) {
			return false;
		}

		$object = get_post_type_object( $post_type );

		return $object instanceof \WP_Post_Type
			&& ( ! empty( $object->public ) || ! empty( $object->publicly_queryable ) )
			&& ! empty( $object->show_in_rest );
	}

	/**
	 * Post types the editor may pick for the "another post" source.
	 *
	 * Since 1.2.0 core fields (title, permalink, ...) work without ACF, so
	 * every is_ref_post_type() type is eligible. Since 2.1.0 the ACF field
	 * list for a type is only ordered by field-group location rules
	 * (source_groups()), not filtered.
	 *
	 * @return \WP_Post_Type[] Keyed by slug.
	 */
	public static function eligible_post_types() {
		if ( null !== self::$eligible ) {
			return self::$eligible;
		}

		$out = array();

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $slug => $object ) {
			if ( self::is_ref_post_type( $slug ) ) {
				$out[ $slug ] = $object;
			}
		}

		self::$eligible = $out;

		return $out;
	}

	/**
	 * Eligible post types as plain data for the editor.
	 *
	 * @return array[] { slug, label, rest_base, rest_namespace }
	 */
	public static function post_type_choices() {
		$out = array();

		foreach ( self::eligible_post_types() as $slug => $object ) {
			$label = isset( $object->labels->singular_name ) ? $object->labels->singular_name : $object->label;
			$out[] = array(
				'slug'           => (string) $slug,
				'label'          => wp_strip_all_tags( (string) $label ),
				'rest_base'      => ! empty( $object->rest_base ) && is_string( $object->rest_base ) ? $object->rest_base : (string) $slug,
				'rest_namespace' => ! empty( $object->rest_namespace ) && is_string( $object->rest_namespace ) ? $object->rest_namespace : 'wp/v2',
			);
		}

		return $out;
	}

	/**
	 * Resolve one bound field.
	 *
	 * @param mixed  $spec    Binding spec { from, field }.
	 * @param string $kind    'text' or 'image'.
	 * @param array  $context { post_id: int, user_id: int, ref_post_id: int, ref_post_type: string }.
	 * @return mixed|null Raw (unformatted) value, or null when unavailable.
	 */
	public static function resolve( $spec, $kind, $context ) {
		$spec = self::validate_spec( $spec );

		if ( null === $spec || ! self::is_available() ) {
			return null;
		}

		$field = self::lookup_field( $spec['field'] );

		if ( ! self::is_field_allowed( $field, $kind ) ) {
			return null;
		}

		// Top-level fields only: a sub-field (its parent is a repeater, group
		// or flexible content field) has no group of its own and fails closed.
		if ( null === self::field_group_of( $field ) ) {
			return null;
		}

		// 2.1.0: no location-rule check, for any source. Location rules control
		// where ACF shows the edit UI, not who may read a value; the admin's
		// per-field opt-in (allow_in_bindings, checked above) is the gate. The
		// "another post" checks (exists, type, eligible, published, no
		// password) stay in acf_object_id() / checked_ref_post().

		$acf_id = self::acf_object_id( $spec['from'], $context );

		if ( null === $acf_id ) {
			return null;
		}

		// Unformatted value: we format and escape ourselves, so no HTML from
		// ACF's formatting (wpautop on textareas, image arrays) reaches output.
		$value = get_field( $field['key'], $acf_id, false );

		return self::normalise( $value, $field, $kind );
	}

	/**
	 * Map a source onto the ACF post_id argument.
	 *
	 * @param string $from    post | post_ref | option | user.
	 * @param array  $context Render context.
	 * @return int|string|null
	 */
	private static function acf_object_id( $from, $context ) {
		$post_id = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;

		switch ( $from ) {
			case 'post':
				return $post_id > 0 ? $post_id : null;

			case 'post_ref':
				return self::ref_post_id( $context );

			case 'option':
				if ( ! self::options_available() ) {
					return null;
				}
				/**
				 * Filter the ACF post_id used for options-page fields.
				 *
				 * @param string $id Default 'option'.
				 */
				$id = apply_filters( 'lnyd_acf_options_id', 'option' );
				return is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ? $id : 'option';

			case 'user':
				$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
				// 0 means "author of the post being rendered". 2.2.1: a chosen
				// user must pass the same rule as core profile fields (the
				// author, or a user with published content), so a card cannot
				// read ACF user fields of accounts that never published.
				$user_id = Core_Source::target_user( 0 === $user_id ? 'author' : (string) $user_id, $post_id );
				return $user_id > 0 ? 'user_' . $user_id : null;
		}

		return null;
	}

	/**
	 * The referenced post's ID, when it may be read; otherwise null.
	 *
	 * @param array $context Render context.
	 * @return int|null
	 */
	private static function ref_post_id( $context ) {
		return self::checked_ref_post(
			isset( $context['ref_post_id'] ) ? $context['ref_post_id'] : 0,
			isset( $context['ref_post_type'] ) ? $context['ref_post_type'] : ''
		);
	}

	/**
	 * The ID of an "another post" reference when it may be read, otherwise null.
	 *
	 * Used for ACF and core fields alike: the post must exist, be of the given
	 * type, that type must be eligible, the post must be published and it must
	 * not be password protected.
	 *
	 * @param mixed $ref_id   Post ID (untrusted).
	 * @param mixed $ref_type Post type slug (untrusted).
	 * @return int|null
	 */
	public static function checked_ref_post( $ref_id, $ref_type ) {
		$ref_id   = absint( $ref_id );
		$ref_type = self::sanitize_post_type( $ref_type );

		if ( $ref_id <= 0 || '' === $ref_type || ! self::is_ref_post_type( $ref_type ) ) {
			return null;
		}

		$post = get_post( $ref_id );

		if ( ! $post instanceof \WP_Post
			|| $post->post_type !== $ref_type
			|| 'publish' !== $post->post_status
			|| '' !== (string) $post->post_password
		) {
			return null;
		}

		return (int) $post->ID;
	}

	/**
	 * The field group a top-level field belongs to.
	 *
	 * Sub-fields (parent is another field) return null, which fails closed.
	 *
	 * @param array $field ACF field.
	 * @return array|null
	 */
	private static function field_group_of( $field ) {
		if ( empty( $field['parent'] ) || ! function_exists( 'acf_get_field_group' ) ) {
			return null;
		}

		$group = acf_get_field_group( $field['parent'] );

		return is_array( $group ) ? $group : null;
	}

	/**
	 * Find the ACF field a tag points at.
	 *
	 * The picker always stores the field key (`field_...`), which is unique.
	 * A hand-typed field name is accepted only when exactly one top-level
	 * field in all field groups uses that name: fields in different groups may
	 * share a name, and acf_get_field() would then return whichever it finds
	 * first, so an ambiguous name fails closed.
	 *
	 * @param string $selector Validated key or name.
	 * @return array|null ACF field, or null.
	 */
	private static function lookup_field( $selector ) {
		if ( 0 === strpos( $selector, 'field_' ) ) {
			$field = acf_get_field( $selector );
			return is_array( $field ) && isset( $field['key'] ) && $selector === $field['key'] ? $field : null;
		}

		$names = self::field_keys_by_name();

		if ( ! isset( $names[ $selector ] ) || 1 !== count( $names[ $selector ] ) ) {
			return null;
		}

		$field = acf_get_field( $names[ $selector ][0] );

		return is_array( $field ) ? $field : null;
	}

	/**
	 * Top-level field names of every field group, with the keys using each.
	 *
	 * @return array name => string[] keys.
	 */
	private static function field_keys_by_name() {
		if ( null !== self::$names ) {
			return self::$names;
		}

		$out = array();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( is_array( $field ) && ! empty( $field['name'] ) && ! empty( $field['key'] ) ) {
						$out[ (string) $field['name'] ][] = (string) $field['key'];
					}
				}
			}
		}

		foreach ( $out as $name => $keys ) {
			$out[ $name ] = array_values( array_unique( $keys ) );
		}

		self::$names = $out;

		return $out;
	}

	/**
	 * Flatten an ACF raw value to what the card slot expects.
	 *
	 * @param mixed  $value Raw value.
	 * @param array  $field ACF field.
	 * @param string $kind  'text' or 'image'.
	 * @return mixed|null String for text slots, attachment ID or URL string for images.
	 */
	private static function normalise( $value, $field, $kind ) {
		if ( 'image' === $kind ) {
			if ( is_numeric( $value ) ) {
				return absint( $value );
			}
			if ( is_array( $value ) && isset( $value['ID'] ) ) {
				return absint( $value['ID'] );
			}
			if ( is_string( $value ) && '' !== $value ) {
				return esc_url_raw( $value, array( 'http', 'https' ) );
			}
			return null;
		}

		if ( 'link' === $field['type'] ) {
			if ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
				return $value['url'];
			}
			return is_string( $value ) ? $value : null;
		}

		if ( is_array( $value ) ) {
			$value = array_filter( $value, 'is_scalar' );
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Every ACF field group for the editor's field list, matching groups first.
	 *
	 * 2.1.0: all field groups are returned, whatever their location rules.
	 * The rules only decide the order and the "matches" label: a group
	 * matches when its rules can target the source (for 'post' the post type
	 * being edited or previewed, for 'post_ref' the chosen type). Within each
	 * part the ACF order is kept. Nothing is excluded here; resolve() applies
	 * the per-field opt-in.
	 *
	 * Returns nothing when the source cannot resolve ACF fields at all: ACF
	 * inactive, 'option' without options pages, or 'post_ref' with a type
	 * that is not eligible.
	 *
	 * @param string $from      post | post_ref | option | user.
	 * @param string $post_type Post type for 'post' and 'post_ref' ('' = unknown).
	 * @return array[] { group: array, matches: bool }
	 */
	public static function source_groups( $from, $post_type = '' ) {
		if ( ! self::is_available() || ! function_exists( 'acf_get_field_groups' ) ) {
			return array();
		}

		if ( ! in_array( $from, self::SOURCES, true ) ) {
			return array();
		}

		if ( 'option' === $from && ! self::options_available() ) {
			return array();
		}

		$post_type = self::sanitize_post_type( $post_type );

		if ( 'post_ref' === $from && ! self::is_ref_post_type( $post_type ) ) {
			return array();
		}

		// For the current post, a container type (content block, template,
		// pattern, ...) or a type that is not viewable means we do not know
		// which post will be rendered: every post-targeting group matches.
		if ( 'post' === $from && '' !== $post_type && ( ! is_post_type_viewable( $post_type ) || Card_Data::is_container_type( $post_type ) ) ) {
			$post_type = '';
		}

		$first = array();
		$rest  = array();

		foreach ( (array) acf_get_field_groups() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			if ( in_array( $from, array( 'post', 'post_ref' ), true ) && '' !== $post_type ) {
				$match = self::group_matches_post_type( $group, $post_type );
			} else {
				$match = self::group_matches( $group, 'post_ref' === $from ? 'post' : $from );
			}

			if ( $match ) {
				$first[] = array(
					'group'   => $group,
					'matches' => true,
				);
			} else {
				$rest[] = array(
					'group'   => $group,
					'matches' => false,
				);
			}
		}

		return array_merge( $first, $rest );
	}

	/**
	 * Field groups whose location rules can target the source.
	 *
	 * Since 2.1.0 this is used for ordering and labelling only (it is the
	 * "matches" part of source_groups()); fields of other groups are listed
	 * and resolve too.
	 *
	 * @param string $from      post | post_ref | option | user.
	 * @param string $post_type Post type for 'post' and 'post_ref' ('' = unknown).
	 * @return array[]
	 */
	public static function matching_groups( $from, $post_type = '' ) {
		$out = array();

		foreach ( self::source_groups( $from, $post_type ) as $entry ) {
			if ( $entry['matches'] ) {
				$out[] = $entry['group'];
			}
		}

		return $out;
	}

	/**
	 * List the fields an editor may bind, for the editor's picker.
	 *
	 * Returns field definitions only (key, label, type, group, matches),
	 * never values. Since 2.1.0 every field group is included (matching
	 * groups first, see source_groups()). Fields of a compatible type without
	 * "Allow Access to Value in Editor UI" are included with enabled = false,
	 * so the editor can explain why they cannot be picked; resolve() still
	 * refuses them. Only top-level fields are listed (acf_get_fields() does
	 * not flatten sub-fields, and repeater/group/flexible types are not on
	 * the allow-list).
	 *
	 * @param string $from      post | post_ref | option | user.
	 * @param string $kind      'text' or 'image'.
	 * @param string $post_type Post type for 'post' and 'post_ref' ('' = unknown).
	 * @param array  $groups    Optional pre-computed source_groups() result
	 *                          (plain group arrays are read as matching).
	 * @return array[]
	 */
	public static function list_fields( $from, $kind, $post_type = '', $groups = null ) {
		if ( ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = is_array( $groups ) ? $groups : self::source_groups( $from, $post_type );
		$out    = array();

		foreach ( $groups as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$is_entry = isset( $entry['group'] ) && is_array( $entry['group'] );
			$group    = $is_entry ? $entry['group'] : $entry;
			$matches  = $is_entry ? ! empty( $entry['matches'] ) : true;

			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! self::is_type_compatible( $field, $kind ) ) {
					continue;
				}

				$out[] = array(
					'key'       => (string) $field['key'],
					'name'      => isset( $field['name'] ) ? (string) $field['name'] : '',
					'label'     => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : '',
					'type'      => (string) $field['type'],
					'group'     => isset( $group['title'] ) ? wp_strip_all_tags( (string) $group['title'] ) : '',
					'group_key' => isset( $group['key'] ) ? sanitize_key( (string) $group['key'] ) : '',
					'enabled'   => self::is_field_allowed( $field, $kind ),
					'matches'   => $matches,
				);
			}
		}

		return $out;
	}

	/**
	 * Whether a field group's location rules can match a post of the given type.
	 *
	 * ACF locations are OR-of-ANDs: the outer array holds rule groups (OR),
	 * each holding rules that must all match (AND). A group can match when
	 * any rule group has no rule that rules the post type out.
	 *
	 * @param array  $group     ACF field group.
	 * @param string $post_type Registered post type slug.
	 * @return bool
	 */
	public static function group_matches_post_type( $group, $post_type ) {
		$match = false;

		if ( ! empty( $group['location'] ) && is_array( $group['location'] ) ) {
			foreach ( $group['location'] as $and_group ) {
				if ( is_array( $and_group ) && self::and_group_matches_post_type( $and_group, $post_type ) ) {
					$match = true;
					break;
				}
			}
		}

		/**
		 * Filter whether an ACF field group can apply to a post type.
		 *
		 * Since 2.1.0 this only decides the order and label of the editor's
		 * field list ("matches this post type" first, other field groups
		 * after); it never hides a field and is not used at render time.
		 * Return true to list a group the built-in rule check rejects (for
		 * example a page_* rule on a hierarchical custom type) among the
		 * matching ones.
		 *
		 * @param bool   $match     Whether the group can match.
		 * @param array  $group     ACF field group.
		 * @param string $post_type Post type slug.
		 */
		return (bool) apply_filters( 'lnyd_acf_group_matches_post_type', $match, $group, $post_type );
	}

	/**
	 * Whether every rule in one AND-group can hold for the post type.
	 *
	 * @param array  $rules     Rules.
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function and_group_matches_post_type( $rules, $post_type ) {
		$seen = 0;

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['param'] ) || ! is_string( $rule['param'] ) ) {
				continue;
			}
			++$seen;
			if ( ! self::rule_can_match_post_type( $rule, $post_type ) ) {
				return false;
			}
		}

		return $seen > 0;
	}

	/**
	 * Whether one location rule can hold for some post of the given type.
	 *
	 * Param names verified against ACF's includes/locations/ classes
	 * (github.com/AdvancedCustomFields/acf, master, October 2026).
	 *
	 * @param array  $rule      { param, operator, value }.
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function rule_can_match_post_type( $rule, $post_type ) {
		$param  = $rule['param'];
		$negate = isset( $rule['operator'] ) && '!=' === $rule['operator'];
		$value  = isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '';

		if ( in_array( $param, self::NON_POST_PARAMS, true ) ) {
			return false;
		}

		switch ( $param ) {
			case 'post_type':
				// ACF's compare_to_rule() treats the value "all" as matching anything.
				if ( 'all' === $value ) {
					return ! $negate;
				}
				return $negate ? $value !== $post_type : $value === $post_type;

			case 'page':
			case 'page_type':
			case 'page_parent':
			case 'page_template':
				// ACF registers these four with object_subtype 'page'. Note: ACF's
				// own match() for page_type/page_parent/page_template does not
				// check the post type, so on an edit screen they can also fire
				// for other (hierarchical) types; see the filter above.
				return 'page' === $post_type;

			case 'post':
				// Value is a post ID. "==" pins the group to that post's type.
				if ( $negate ) {
					return true;
				}
				return absint( $value ) > 0 && get_post_type( absint( $value ) ) === $post_type;

			case 'post_format':
				// get_post_format() is false for types without post-formats,
				// so "==" can only match types that support them.
				return $negate || post_type_supports( $post_type, 'post-formats' );

			case 'post_category':
				return $negate || is_object_in_taxonomy( $post_type, 'category' );

			case 'post_taxonomy':
				if ( $negate ) {
					return true;
				}
				$taxonomy = self::rule_taxonomy( $value );
				// Undecodable term: include conservatively; resolve() still
				// requires allow_in_bindings on every field.
				return '' === $taxonomy || is_object_in_taxonomy( $post_type, $taxonomy );

			case 'post_template':
				// Templates per post type come from the theme (classic headers
				// or block theme custom templates) and are not reliably known
				// here, so this rule never excludes a type.
				return true;

			case 'post_status':
			case 'current_user':
			case 'current_user_role':
				// Not tied to a post type.
				return true;
		}

		// Unknown (third-party) location type: include conservatively.
		return true;
	}

	/**
	 * Taxonomy slug from an ACF term rule value ("taxonomy:term-slug").
	 *
	 * @param string $value Rule value.
	 * @return string '' when it cannot be decoded.
	 */
	private static function rule_taxonomy( $value ) {
		if ( function_exists( 'acf_decode_term' ) ) {
			$term = acf_decode_term( $value );
			if ( is_array( $term ) && ! empty( $term['taxonomy'] ) && is_string( $term['taxonomy'] ) ) {
				return taxonomy_exists( $term['taxonomy'] ) ? $term['taxonomy'] : '';
			}
		}

		$parts = explode( ':', $value, 2 );
		$tax   = sanitize_key( $parts[0] );

		return count( $parts ) === 2 && taxonomy_exists( $tax ) ? $tax : '';
	}

	/**
	 * Whether a field group's location rules target the given source (type-agnostic).
	 *
	 * @param array  $group ACF field group.
	 * @param string $from  post | option | user.
	 * @return bool
	 */
	private static function group_matches( $group, $from ) {
		$params = array();

		if ( ! empty( $group['location'] ) && is_array( $group['location'] ) ) {
			foreach ( $group['location'] as $and_group ) {
				foreach ( (array) $and_group as $rule ) {
					if ( is_array( $rule ) && ! empty( $rule['param'] ) ) {
						$params[] = (string) $rule['param'];
					}
				}
			}
		}

		$user_params   = array( 'user_form', 'user_role' );
		$option_params = array( 'options_page' );

		switch ( $from ) {
			case 'user':
				return (bool) array_intersect( $params, $user_params );
			case 'option':
				return (bool) array_intersect( $params, $option_params );
			case 'post':
				return empty( $params ) || (bool) array_diff( $params, self::NON_POST_PARAMS );
		}

		return false;
	}
}
