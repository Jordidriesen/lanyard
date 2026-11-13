<?php
/**
 * Card data model.
 *
 * Turns block attributes (static text with optional inline tags, see
 * class-tags.php) into one
 * normalised, sanitised array that the renderer, the vCard builder and the
 * JSON-LD builder all read. Values here are sanitised plain text and raw
 * URLs; escaping for the output context happens in the renderer.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Card_Data {

	/**
	 * Text content slots, in display order. Attribute name => contact type.
	 *
	 * @return string[]
	 */
	public static function text_fields() {
		return array( 'name', 'jobTitle', 'company', 'tagline', 'phone', 'mobile', 'email', 'website', 'address', 'linkedin' );
	}

	/**
	 * Slots that become links. Their href must come from exactly one value:
	 * either static text, or a field that is exactly one tag. Mixed content
	 * (text plus tags, or several tags) is shown as text without a link.
	 *
	 * @return string[]
	 */
	public static function link_slots() {
		return array( 'phone', 'mobile', 'email', 'website', 'linkedin' );
	}

	/**
	 * Build the model.
	 *
	 * @param array          $attributes Block attributes.
	 * @param \WP_Block|null $block      Block instance (for context).
	 * @return array
	 */
	public static function build( $attributes, $block = null ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		// Blocks saved by 1.1.0 and not opened in the editor since.
		$attributes = Tags::migrate_v110( $attributes );

		// post_id is always the post being rendered or viewed (see
		// current_post_id()), never an ID from a tag. The editor-only preview
		// post is the one exception, and only inside the block-renderer REST
		// request. Everything a tag carries (post type, post ID, user ID) is
		// untrusted and re-checked by the source classes.
		$post_id = self::current_post_id( $block, $attributes );
		$context = array( 'post_id' => $post_id );
		$bound   = self::bound_attributes( $block );

		$values = array();
		$nolink = array();
		foreach ( self::text_fields() as $slot ) {
			$raw = isset( $attributes[ $slot ] ) && is_scalar( $attributes[ $slot ] ) ? (string) $attributes[ $slot ] : '';

			if ( isset( $bound[ $slot ] ) ) {
				// Filled by the Block Bindings API: a resolved value, so it is
				// never parsed for tags.
				$value = $raw;
			} elseif ( in_array( $slot, self::link_slots(), true ) && Tags::has_tags( $raw ) ) {
				$single = Tags::single( $raw );
				if ( null !== $single ) {
					$value = Tags::text_value( $single, $context );
				} else {
					$value           = Tags::render_text( $raw, $context );
					$nolink[ $slot ] = true;
				}
			} else {
				$value = Tags::render_text( $raw, $context );
			}

			$values[ $slot ] = self::text( $value );
		}

		$avatar = self::avatar( $attributes, $context, $bound );

		$model = array(
			'identity' => array(
				'name'         => $values['name'],
				'title'        => $values['jobTitle'],
				'organization' => $values['company'],
				'tagline'      => $values['tagline'],
				'avatar'       => $avatar,
			),
			'contacts' => self::contacts( $values, ! empty( $attributes['linkAddress'] ), $nolink ),
			'permalink'  => $post_id ? (string) get_permalink( $post_id ) : home_url( '/' ),
		);

		/**
		 * Filter the resolved card data before rendering.
		 *
		 * Values are escaped at output, so filtered values need not be.
		 *
		 * @param array $model      Card model.
		 * @param array $attributes Block attributes.
		 */
		$filtered = apply_filters( 'lnyd_card_data', $model, $attributes );

		return is_array( $filtered ) ? $filtered : $model;
	}

	/**
	 * Post types that hold reusable content shown on other pages ("containers").
	 *
	 * A card inside one of these must not read "this post" from the container
	 * itself. Slugs verified for 2.1.0: wp_block, wp_template,
	 * wp_template_part and wp_navigation are registered in WordPress 6.6
	 * wp-includes/post.php; ct_content_block is Blocksy Companion Pro's
	 * Content Blocks type (admin link edit.php?post_type=ct_content_block in
	 * the free Blocksy Companion 2.1.61); elementor_library is Elementor's
	 * Source_Local::CPT.
	 *
	 * @return string[]
	 */
	public static function container_post_types() {
		$types = array( 'ct_content_block', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'elementor_library' );

		/**
		 * Filter the post types treated as containers.
		 *
		 * A card rendered while the block context or the global post is one
		 * of these reads "this post" from the page being viewed instead.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = apply_filters( 'lnyd_container_post_types', $types );

		if ( ! is_array( $types ) ) {
			return array();
		}

		$out = array();
		foreach ( $types as $type ) {
			if ( is_string( $type ) && '' !== $type && sanitize_key( $type ) === $type ) {
				$out[] = $type;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a post type slug is a container type.
	 *
	 * @param mixed $post_type Post type slug.
	 * @return bool
	 */
	public static function is_container_type( $post_type ) {
		return is_string( $post_type ) && '' !== $post_type && in_array( $post_type, self::container_post_types(), true );
	}

	/**
	 * A post ID when it is an existing post that is not a container, else 0.
	 *
	 * @param mixed $post_id Candidate.
	 * @return int
	 */
	private static function content_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return 0;
		}

		$type = get_post_type( $post_id );

		return ( is_string( $type ) && ! self::is_container_type( $type ) ) ? $post_id : 0;
	}

	/**
	 * The post "this post" reads from.
	 *
	 * 1. Block context postId (a Query Loop item, or the post the block is
	 *    rendered for), unless it is a container type;
	 * 2. otherwise get_the_ID(), unless it is a container type (Blocksy
	 *    Content Blocks, synced patterns and templates may be rendered with
	 *    the container as the global post);
	 * 3. otherwise, on a singular request, the queried object (the page being
	 *    viewed), unless it is a container type;
	 * 4. otherwise, only in the editor preview (block-renderer REST request),
	 *    the "Preview with" post, validated like "another post";
	 * 5. otherwise 0 (tags show their fallbacks).
	 *
	 * On the front end a password-protected post that the visitor has not
	 * unlocked gives 0, so a card outside the post content (a hook, a
	 * sidebar, a loop) cannot show its fields either.
	 *
	 * Never reads an ID from a tag.
	 *
	 * @param \WP_Block|null $block      Block instance.
	 * @param array          $attributes Block attributes.
	 * @return int
	 */
	public static function current_post_id( $block = null, $attributes = array() ) {
		$post_id = 0;
		$preview = Renderer::is_editor_preview();

		if ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$post_id = self::content_post( $block->context['postId'] );
		}

		if ( 0 === $post_id ) {
			$post_id = self::content_post( get_the_ID() );
		}

		if ( 0 === $post_id && is_singular() ) {
			$post_id = self::content_post( get_queried_object_id() );
		}

		if ( 0 === $post_id && $preview ) {
			$post_id = self::preview_post_id( $attributes );
		}

		if ( $post_id > 0 && ! $preview && post_password_required( $post_id ) ) {
			$post_id = 0;
		}

		/**
		 * Filter the post a card's "this post" tags read from.
		 *
		 * @param int            $post_id    Resolved post ID (0 = none).
		 * @param \WP_Block|null $block      Block instance.
		 * @param array          $attributes Block attributes.
		 */
		return absint( apply_filters( 'lnyd_current_post_id', $post_id, $block, $attributes ) );
	}

	/**
	 * The editor-only "Preview with" post, when it may be used.
	 *
	 * Only called inside the block-renderer REST request (core requires
	 * edit_post for its post_id, or edit_posts). The post must pass the
	 * "another post" checks (exists, type equals previewPostType, eligible
	 * type, published, no password) and must not be a container.
	 *
	 * @param array $attributes Block attributes.
	 * @return int 0 when unset or not allowed.
	 */
	private static function preview_post_id( $attributes ) {
		$id   = isset( $attributes['previewPostId'] ) ? $attributes['previewPostId'] : 0;
		$type = isset( $attributes['previewPostType'] ) && is_string( $attributes['previewPostType'] ) ? $attributes['previewPostType'] : '';

		if ( self::is_container_type( $type ) ) {
			return 0;
		}

		$checked = Acf_Source::checked_ref_post( is_scalar( $id ) ? $id : 0, $type );

		return null === $checked ? 0 : $checked;
	}

	/**
	 * Attribute names filled by the Block Bindings API for this block.
	 *
	 * @param \WP_Block|null $block Block.
	 * @return array Attribute name => true.
	 */
	private static function bound_attributes( $block ) {
		if ( ! $block instanceof \WP_Block || empty( $block->parsed_block['attrs']['metadata']['bindings'] ) || ! is_array( $block->parsed_block['attrs']['metadata']['bindings'] ) ) {
			return array();
		}

		return array_fill_keys( array_map( 'strval', array_keys( $block->parsed_block['attrs']['metadata']['bindings'] ) ), true );
	}

	/**
	 * Resolve the avatar.
	 *
	 * The image slot takes a single tag (avatarTag); the static image
	 * (avatarId / avatarUrl) is the fallback.
	 *
	 * @param array $attributes Attributes.
	 * @param array $context    Render context.
	 * @param array $bound      Bound attribute names.
	 * @return array { id: int, url: string, alt: string, size: string, sized: bool (size set by the tag) }
	 */
	private static function avatar( $attributes, $context, $bound ) {
		$id    = isset( $attributes['avatarId'] ) ? absint( $attributes['avatarId'] ) : 0;
		$url   = isset( $attributes['avatarUrl'] ) && is_string( $attributes['avatarUrl'] ) ? $attributes['avatarUrl'] : '';
		$size  = 'thumbnail';
		$sized = false;

		$tag = ! isset( $bound['avatarTag'] ) && isset( $attributes['avatarTag'] ) ? Tags::single( $attributes['avatarTag'] ) : null;

		if ( null !== $tag ) {
			$value = Tags::resolve( $tag, 'image', $context );
			if ( is_int( $value ) && $value > 0 ) {
				$id  = $value;
				$url = '';
			} elseif ( is_string( $value ) && '' !== $value ) {
				$id  = 0;
				$url = $value;
			}
			if ( '' !== $tag['size'] ) {
				$size  = $tag['size'];
				$sized = true;
			}
		}

		if ( $id && ! wp_attachment_is_image( $id ) ) {
			$id = 0;
		}

		$url = '' === $url ? '' : esc_url_raw( $url, array( 'http', 'https' ) );

		// Only same-site image URLs: an external photo URL would make every
		// visitor's browser contact a third party (GDPR), and attributes can
		// be written by contributors.
		if ( '' !== $url && ! self::is_local_url( $url ) ) {
			$url = '';
		}

		return array(
			'id'    => $id,
			'url'   => $url,
			'alt'   => isset( $attributes['avatarAlt'] ) ? self::text( $attributes['avatarAlt'] ) : '',
			'size'  => $size,
			'sized' => $sized,
		);
	}

	/**
	 * Whether a URL points at this site (home or uploads host).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_local_url( $url ) {
		$host    = wp_parse_url( $url, PHP_URL_HOST );
		$uploads = wp_get_upload_dir();
		$hosts   = array_filter(
			array(
				wp_parse_url( home_url(), PHP_URL_HOST ),
				wp_parse_url( site_url(), PHP_URL_HOST ),
				isset( $uploads['baseurl'] ) ? wp_parse_url( $uploads['baseurl'], PHP_URL_HOST ) : '',
			)
		);

		/**
		 * Filter the hosts allowed for avatar image URLs.
		 *
		 * @param string[] $hosts Allowed hosts.
		 */
		$hosts = (array) apply_filters( 'lnyd_allowed_image_hosts', $hosts );

		return is_string( $host ) && in_array( strtolower( $host ), array_map( 'strtolower', $hosts ), true );
	}

	/**
	 * Build contact rows with link targets.
	 *
	 * @param array $values       Resolved text values.
	 * @param bool  $link_address Whether to link the address to a map search.
	 * @param array $nolink       Slots with mixed content (shown without a link).
	 * @return array[] { type, text, href }
	 */
	private static function contacts( $values, $link_address, $nolink = array() ) {
		$rows = array();

		foreach ( array( 'phone', 'mobile' ) as $slot ) {
			if ( '' === $values[ $slot ] ) {
				continue;
			}
			$tel    = isset( $nolink[ $slot ] ) ? '' : self::tel( $values[ $slot ] );
			$rows[] = array(
				'type' => $slot,
				'text' => $values[ $slot ],
				'href' => '' === $tel ? '' : 'tel:' . $tel,
			);
		}

		if ( '' !== $values['email'] ) {
			$email  = isset( $nolink['email'] ) ? '' : sanitize_email( $values['email'] );
			$valid  = '' !== $email && is_email( $email );
			$rows[] = array(
				'type' => 'email',
				'text' => $valid ? $email : $values['email'],
				'href' => $valid ? 'mailto:' . $email : '',
			);
		}

		if ( '' !== $values['website'] ) {
			$url    = isset( $nolink['website'] ) ? '' : self::web_url( $values['website'] );
			$rows[] = array(
				'type' => 'website',
				'text' => '' === $url ? $values['website'] : preg_replace( '#^https?://(www\.)?#i', '', untrailingslashit( $values['website'] ) ),
				'href' => $url,
			);
		}

		if ( '' !== $values['linkedin'] ) {
			$url    = isset( $nolink['linkedin'] ) ? '' : self::web_url( $values['linkedin'] );
			$rows[] = array(
				'type' => 'linkedin',
				'text' => '' === $url ? $values['linkedin'] : __( 'Connect on LinkedIn', 'lanyard' ),
				'href' => $url,
			);
		}

		if ( '' !== $values['address'] ) {
			$rows[] = array(
				'type' => 'address',
				'text' => $values['address'],
				'href' => $link_address ? 'https://www.openstreetmap.org/search?query=' . rawurlencode( $values['address'] ) : '',
			);
		}

		return $rows;
	}

	/**
	 * Normalise a web address to an http(s) URL, or '' when invalid.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function web_url( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		return (string) esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * Digits for a tel: link, keeping a leading "+".
	 *
	 * @param string $value Raw phone number.
	 * @return string
	 */
	private static function tel( $value ) {
		$plus   = 0 === strpos( ltrim( $value ), '+' ) ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $value );

		return '' === $digits ? '' : $plus . $digits;
	}

	/**
	 * Flatten any value to safe single-line plain text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = wp_strip_all_tags( (string) $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		return trim( sanitize_text_field( $value ) );
	}
}
