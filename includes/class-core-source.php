<?php
/**
 * Core WordPress field resolver (works without ACF).
 *
 * Strict allow-list per source; no arbitrary meta keys. Values are returned
 * as raw plain text, attachment IDs or URLs; Card_Data sanitises them and
 * the Renderer escapes them for their output context.
 *
 * Sources and fields:
 *   post / post_ref: title, excerpt, permalink, date, modified, post_type_label,
 *                    featured_image (image slots only).
 *   site:            name, tagline, home_url, custom_logo (image slots only).
 *   user:            display_name, first_name, last_name, nickname, bio,
 *                    user_url, avatar (image slots only), user_email (gated,
 *                    see user_email_allowed()).
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Source {

	/**
	 * Date format keys accepted by the `format` tag argument.
	 */
	const DATE_FORMATS = array( 'site', 'iso', 'dmy', 'mdy', 'long' );

	/**
	 * Recursion guard for excerpts (a card in a post's own content).
	 *
	 * @var bool
	 */
	private static $in_excerpt = false;

	/**
	 * The allow-list: source => field => kind ('text', 'date' or 'image').
	 *
	 * 'date' fields go in text slots and accept the `format` argument.
	 *
	 * @return array
	 */
	public static function fields() {
		$post = array(
			'title'           => 'text',
			'excerpt'         => 'text',
			'permalink'       => 'text',
			'date'            => 'date',
			'modified'        => 'date',
			'post_type_label' => 'text',
			'featured_image'  => 'image',
		);

		return array(
			'post'     => $post,
			'post_ref' => $post,
			'site'     => array(
				'name'        => 'text',
				'tagline'     => 'text',
				'home_url'    => 'text',
				'custom_logo' => 'image',
			),
			'user'     => array(
				'display_name' => 'text',
				'first_name'   => 'text',
				'last_name'    => 'text',
				'nickname'     => 'text',
				'bio'          => 'text',
				'user_url'     => 'text',
				'user_email'   => 'text',
				'avatar'       => 'image',
			),
		);
	}

	/**
	 * Human-readable labels for the editor.
	 *
	 * @return string[]
	 */
	public static function labels() {
		return array(
			'title'           => __( 'Title', 'lanyard' ),
			'excerpt'         => __( 'Excerpt', 'lanyard' ),
			'permalink'       => __( 'Permalink', 'lanyard' ),
			'date'            => __( 'Publish date', 'lanyard' ),
			'modified'        => __( 'Modified date', 'lanyard' ),
			'post_type_label' => __( 'Post type label', 'lanyard' ),
			'featured_image'  => __( 'Featured image', 'lanyard' ),
			'name'            => __( 'Site title', 'lanyard' ),
			'tagline'         => __( 'Site tagline', 'lanyard' ),
			'home_url'        => __( 'Home URL', 'lanyard' ),
			'custom_logo'     => __( 'Site logo', 'lanyard' ),
			'display_name'    => __( 'Display name', 'lanyard' ),
			'first_name'      => __( 'First name', 'lanyard' ),
			'last_name'       => __( 'Last name', 'lanyard' ),
			'nickname'        => __( 'Nickname', 'lanyard' ),
			'bio'             => __( 'Biographical info', 'lanyard' ),
			'user_url'        => __( 'Website (profile)', 'lanyard' ),
			'user_email'      => __( 'Email (post author only)', 'lanyard' ),
			'avatar'          => __( 'Avatar', 'lanyard' ),
		);
	}

	/**
	 * Whether a field exists for the source and fits the slot kind.
	 *
	 * @param string $source Source.
	 * @param string $field  Field slug.
	 * @param string $kind   'text' or 'image'.
	 * @return string '' when not allowed, else the field kind (text|date|image).
	 */
	public static function field_kind( $source, $field, $kind ) {
		$all = self::fields();

		if ( ! isset( $all[ $source ][ $field ] ) ) {
			return '';
		}

		$field_kind = $all[ $source ][ $field ];
		$fits       = 'image' === $kind ? 'image' === $field_kind : 'image' !== $field_kind;

		return $fits ? $field_kind : '';
	}

	/**
	 * Fields for the editor picker.
	 *
	 * @param string $source Source.
	 * @param string $kind   'text' or 'image'.
	 * @return array[] { arg, value, label, kind, enabled }
	 */
	public static function list_fields( $source, $kind ) {
		$all    = self::fields();
		$labels = self::labels();
		$out    = array();

		if ( ! isset( $all[ $source ] ) ) {
			return $out;
		}

		foreach ( $all[ $source ] as $field => $field_kind ) {
			if ( '' === self::field_kind( $source, $field, $kind ) ) {
				continue;
			}
			$out[] = array(
				'arg'     => 'field',
				'value'   => $field,
				'label'   => isset( $labels[ $field ] ) ? $labels[ $field ] : $field,
				'kind'    => $field_kind,
				'enabled' => true,
			);
		}

		return $out;
	}

	/**
	 * Resolve one core field.
	 *
	 * @param array  $tag     Validated tag (Tags::parse_args()).
	 * @param string $kind    'text' or 'image'.
	 * @param array  $context { post_id: int } (the post being rendered, from
	 *                        Card_Data::current_post_id()).
	 * @return mixed|null String, attachment ID (int) or null.
	 */
	public static function resolve( $tag, $kind, $context ) {
		$source = $tag['source'];
		$field  = $tag['field'];

		if ( '' === self::field_kind( $source, $field, $kind ) ) {
			return null;
		}

		$post_id = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;

		switch ( $source ) {
			case 'post':
				$post = $post_id > 0 ? get_post( $post_id ) : null;
				return $post instanceof \WP_Post ? self::post_field( $post, $field, $tag ) : null;

			case 'post_ref':
				// Same checks as 1.1: published, no password, eligible type, type matches.
				$ref = Acf_Source::checked_ref_post( $tag['id'], $tag['type'] );
				return null === $ref ? null : self::post_field( get_post( $ref ), $field, $tag );

			case 'site':
				return self::site_field( $field );

			case 'user':
				$user_id = self::target_user( $tag['user'], $post_id );
				return $user_id > 0 ? self::user_field( $user_id, $field, $post_id ) : null;
		}

		return null;
	}

	/**
	 * A post field.
	 *
	 * @param \WP_Post|null $post  Post.
	 * @param string        $field Field.
	 * @param array         $tag   Tag args (format).
	 * @return mixed|null
	 */
	private static function post_field( $post, $field, $tag ) {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		switch ( $field ) {
			case 'title':
				return wp_strip_all_tags( get_the_title( $post ) );

			case 'excerpt':
				return self::excerpt( $post );

			case 'permalink':
				$link = get_permalink( $post );
				return $link ? (string) $link : null;

			case 'date':
			case 'modified':
				$ts = get_post_timestamp( $post, 'modified' === $field ? 'modified' : 'date' );
				return false === $ts ? null : wp_date( self::date_format( $tag['format'] ), $ts );

			case 'post_type_label':
				$object = get_post_type_object( $post->post_type );
				return $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : null;

			case 'featured_image':
				$id = (int) get_post_thumbnail_id( $post );
				return $id > 0 ? $id : null;
		}

		return null;
	}

	/**
	 * Excerpt without running the_content filters (which would render this
	 * block again when the card sits in the same post).
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	private static function excerpt( $post ) {
		if ( self::$in_excerpt || post_password_required( $post ) ) {
			return null;
		}

		self::$in_excerpt = true;

		$text = '' !== trim( (string) $post->post_excerpt )
			? (string) $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true ), 30 );

		self::$in_excerpt = false;

		return wp_strip_all_tags( $text, true );
	}

	/**
	 * Map a format key onto a PHP date format.
	 *
	 * @param string $key Format key.
	 * @return string
	 */
	public static function date_format( $key ) {
		switch ( $key ) {
			case 'iso':
				return 'Y-m-d';
			case 'dmy':
				return 'd/m/Y';
			case 'mdy':
				return 'm/d/Y';
			case 'long':
				return 'j F Y';
		}

		$site = get_option( 'date_format' );

		return is_string( $site ) && '' !== $site ? $site : 'Y-m-d';
	}

	/**
	 * A site field.
	 *
	 * @param string $field Field.
	 * @return mixed|null
	 */
	private static function site_field( $field ) {
		switch ( $field ) {
			case 'name':
				return wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
			case 'tagline':
				return wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
			case 'home_url':
				return home_url( '/' );
			case 'custom_logo':
				$id = (int) get_theme_mod( 'custom_logo' );
				return $id > 0 ? $id : null;
		}

		return null;
	}

	/**
	 * Which user a tag points at.
	 *
	 * 'author' is the author of the post being rendered. A chosen user ID is
	 * only used when that user is "public" (see user_is_public()), so a block
	 * cannot surface profile data of users who never published anything.
	 *
	 * @param string $user    'author' or a numeric ID.
	 * @param int    $post_id Rendered post.
	 * @return int 0 when not allowed.
	 */
	public static function target_user( $user, $post_id ) {
		$author = $post_id > 0 ? (int) get_post_field( 'post_author', $post_id ) : 0;

		if ( 'author' === $user ) {
			return $author > 0 && get_userdata( $author ) ? $author : 0;
		}

		$user_id = absint( $user );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 0;
		}

		return ( $user_id === $author || self::user_is_public( $user_id ) ) ? $user_id : 0;
	}

	/**
	 * Whether a user has published content in a public, REST-visible post type
	 * (the same rule core's /wp/v2/users uses for unauthenticated listings).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_is_public( $user_id ) {
		$types  = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		$public = count_user_posts( $user_id, array_values( $types ), true ) > 0;

		/**
		 * Filter whether core profile fields of a chosen user may be shown on a card.
		 *
		 * @param bool $public  Whether the user has published posts.
		 * @param int  $user_id User ID.
		 */
		return (bool) apply_filters( 'lnyd_user_is_public', $public, $user_id );
	}

	/**
	 * Whether user_email may be resolved.
	 *
	 * Only for the author of the post being rendered, unless the site owner
	 * opts in with the lnyd_allow_user_email filter.
	 *
	 * @param int $user_id Target user.
	 * @param int $post_id Rendered post.
	 * @return bool
	 */
	public static function user_email_allowed( $user_id, $post_id ) {
		$author  = $post_id > 0 ? (int) get_post_field( 'post_author', $post_id ) : 0;
		$allowed = $author > 0 && $author === (int) $user_id;

		// 2.2.1: in the editor preview the post can be a "Preview with" post
		// of another author. Only show that author's email to someone who may
		// edit the post (or to the author themselves), so the preview cannot
		// be used to look up email addresses.
		if ( $allowed && Renderer::is_editor_preview() && get_current_user_id() !== (int) $user_id && ! current_user_can( 'edit_post', $post_id ) ) {
			$allowed = false;
		}

		/**
		 * Filter whether a user's email address may be shown on a card.
		 *
		 * Default: only when the user is the author of the post being rendered.
		 *
		 * @param bool $allowed Whether the email may be shown.
		 * @param int  $user_id Target user ID.
		 * @param int  $post_id Post being rendered (0 when unknown).
		 */
		return (bool) apply_filters( 'lnyd_allow_user_email', $allowed, (int) $user_id, (int) $post_id );
	}

	/**
	 * A user field.
	 *
	 * @param int    $user_id User ID.
	 * @param string $field   Field.
	 * @param int    $post_id Rendered post.
	 * @return mixed|null
	 */
	private static function user_field( $user_id, $field, $post_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		switch ( $field ) {
			case 'display_name':
				return (string) $user->display_name;
			case 'first_name':
				return (string) get_user_meta( $user_id, 'first_name', true );
			case 'last_name':
				return (string) get_user_meta( $user_id, 'last_name', true );
			case 'nickname':
				return (string) get_user_meta( $user_id, 'nickname', true );
			case 'bio':
				return (string) get_user_meta( $user_id, 'description', true );
			case 'user_url':
				return (string) $user->user_url;
			case 'user_email':
				return self::user_email_allowed( $user_id, $post_id ) ? (string) $user->user_email : null;
			case 'avatar':
				// May be a Gravatar URL; Card_Data only keeps same-site image
				// hosts (filter lnyd_allowed_image_hosts) for GDPR reasons.
				$url = get_avatar_url( $user_id, array( 'size' => 192 ) );
				return is_string( $url ) && '' !== $url ? $url : null;
		}

		return null;
	}
}
