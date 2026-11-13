<?php
/**
 * REST routes for the editor's dynamic-data picker.
 *
 * GET /wp-json/lanyard/v1/fields?source=post|post_ref|site|user|option&kind=text|image&post_type=<slug>
 *   Field definitions only, never values: the core WordPress allow-list for
 *   the source plus ACF fields grouped by field group. Since 2.1.0 every ACF
 *   field group is listed, whatever its location rules; groups whose rules
 *   match the source (and post type) come first with matches = true. ACF
 *   fields of a usable type without "Allow Access to Value in Editor UI" are
 *   listed with enabled = false; render time still refuses them.
 *
 * GET /wp-json/lanyard/v1/post-types
 *   Post types eligible for the "another post" source, with rest_base, plus
 *   the container post types (lnyd_container_post_types) for the editor.
 *
 * Both require the edit_posts capability: they describe the site's content
 * model and are not public data, so they are never opened with __return_true.
 * Both are read-only (GET).
 *
 * The 1.1.0 routes acf-fields and acf-post-types were replaced by these two.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NAMESPACE_V1 = 'lanyard/v1';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_fields' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
				'args'                => array(
					'source'    => array(
						'type'              => 'string',
						'enum'              => Tags::SOURCES,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'kind'      => array(
						'type'              => 'string',
						'enum'              => array( 'text', 'image' ),
						'default'           => 'text',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'post_type' => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 20,
						'validate_callback' => array( __CLASS__, 'validate_post_type' ),
						'sanitize_callback' => array( Acf_Source::class, 'sanitize_post_type' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/post-types',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_post_types' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Only users who can write posts (and therefore insert the block) may list fields.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_list() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'lnyd_forbidden',
				__( 'Sorry, you are not allowed to list card fields.', 'lanyard' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Accept '' or the slug of a registered post type, nothing else.
	 *
	 * @param mixed $value Raw value.
	 * @return true|\WP_Error
	 */
	public static function validate_post_type( $value ) {
		if ( '' === $value ) {
			return true;
		}

		if ( ! is_string( $value ) || '' === Acf_Source::sanitize_post_type( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Unknown post type.', 'lanyard' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * List the fields a slot may use for a source.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_fields( $request ) {
		$source    = (string) $request->get_param( 'source' );
		$kind      = (string) $request->get_param( 'kind' );
		$post_type = Acf_Source::sanitize_post_type( $request->get_param( 'post_type' ) );

		// Defence in depth: args validation already enforces the enums.
		if ( ! in_array( $source, Tags::SOURCES, true ) ) {
			$source = 'post';
		}
		if ( ! in_array( $kind, array( 'text', 'image' ), true ) ) {
			$kind = 'text';
		}

		$groups = array();

		$core = Core_Source::list_fields( $source, $kind );
		if ( ! empty( $core ) ) {
			$groups[] = array(
				'key'    => 'wordpress',
				'title'  => __( 'WordPress', 'lanyard' ),
				'fields' => $core,
			);
		}

		$acf_groups = 0;
		$acf_total  = 0;
		if ( 'site' !== $source && Acf_Source::is_available() ) {
			// 2.1.0: every field group, matching groups first. The match is
			// only an ordering and label hint, never a filter.
			$all        = Acf_Source::source_groups( $source, $post_type );
			$acf_total  = count( $all );
			$acf_groups = count( array_filter( wp_list_pluck( $all, 'matches' ) ) );
			$by_group   = array();

			foreach ( Acf_Source::list_fields( $source, $kind, $post_type, $all ) as $field ) {
				$key        = '' !== $field['group_key'] ? $field['group_key'] : 'acf';
				$group_name = '' !== $field['group'] ? $field['group'] : __( '(no group title)', 'lanyard' );
				if ( ! isset( $by_group[ $key ] ) ) {
					$by_group[ $key ] = array(
						'key'     => 'acf-' . $key,
						/* translators: %s: ACF field group title. */
						'title'   => sprintf( __( 'ACF: %s', 'lanyard' ), $group_name ),
						'group'   => $group_name,
						'acf'     => true,
						'matches' => (bool) $field['matches'],
						'fields'  => array(),
					);
				}
				$by_group[ $key ]['fields'][] = array(
					'arg'     => 'acf',
					'value'   => $field['key'],
					'label'   => '' !== $field['label'] ? $field['label'] : $field['name'],
					'kind'    => 'image' === $kind ? 'image' : 'text',
					'type'    => $field['type'],
					'enabled' => (bool) $field['enabled'],
				);
			}

			$groups = array_merge( $groups, array_values( $by_group ) );
		}

		// "Matches this <type>" only when the type decided the order: not for
		// the current post in a container or a non-viewable type.
		$label_type  = ( 'post' === $source && '' !== $post_type && ( ! is_post_type_viewable( $post_type ) || Card_Data::is_container_type( $post_type ) ) ) ? '' : $post_type;
		$type_object = '' !== $label_type && in_array( $source, array( 'post', 'post_ref' ), true ) ? get_post_type_object( $label_type ) : null;
		$type_label  = $type_object instanceof \WP_Post_Type && isset( $type_object->labels->singular_name ) ? wp_strip_all_tags( (string) $type_object->labels->singular_name ) : '';

		return rest_ensure_response(
			array(
				'acf'             => Acf_Source::is_available(),
				'options'         => Acf_Source::options_available(),
				'post_type'       => $post_type,
				'post_type_label' => $type_label,
				'acf_groups'      => $acf_groups,
				'acf_total'       => $acf_total,
				'groups'          => $groups,
			)
		);
	}

	/**
	 * List post types eligible for the "another post" source.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_post_types() {
		return rest_ensure_response(
			array(
				'acf'        => Acf_Source::is_available(),
				'options'    => Acf_Source::options_available(),
				'post_types' => Acf_Source::post_type_choices(),
				// 2.1.0: types whose editor shows the "Preview with" picker.
				'containers' => Card_Data::container_post_types(),
			)
		);
	}
}

Rest::init();
