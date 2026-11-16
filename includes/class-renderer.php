<?php
/**
 * Server-side card markup.
 *
 * Everything is rendered in PHP: no front-end JavaScript, no remote assets.
 * Since 2.0.0 the card has one look, Profile (the four other layouts and
 * the block styles were removed); its QR overlay uses the HTML Popover API
 * (popover and popovertarget attributes), which needs no script either.
 * Every value is escaped here, at output, for its exact context.
 *
 * @package Lanyard
 */

namespace Lanyard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Renderer {

	/**
	 * JSON flags that keep "<", ">", "&" and quotes from breaking out of a
	 * script element (same flags as the original plugin).
	 */
	const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

	/**
	 * Layouts. Since 2.0.0 Profile is the only one; see layout_name().
	 */
	const LAYOUTS = array( 'profile' );

	/**
	 * Every style name a stored card may still carry in its className
	 * (is-style-*): the 1.1.0 names, the 1.2.0 "Split, mirrored" and the
	 * 1.3.0 to 1.4.1 block styles. All of them render as Profile.
	 * Mirrors OBSOLETE_STYLES in src/card/tags.js.
	 */
	const OBSOLETE_STYLES = array( 'default', 'split', 'two-tone', 'centered', 'profile', 'mirrored', 'minimal', 'sidebar', 'banner' );

	/**
	 * Contact order (email first).
	 */
	const PROFILE_ORDER = array( 'email', 'phone', 'mobile', 'website', 'linkedin', 'address' );

	/**
	 * Profile photo "sizes" per card width preset (px at the 16px initial
	 * font size; the column is 30em on Auto). 'full' uses the wide value.
	 */
	const PROFILE_SIZES = array(
		'auto'   => 480,
		'narrow' => 360,
		'medium' => 560,
		'wide'   => 720,
		'full'   => 720,
	);

	/**
	 * Card width presets (attribute cardWidth). 'auto' = 30em (480px).
	 */
	const WIDTHS = array( 'auto', 'narrow', 'medium', 'wide', 'full' );

	/**
	 * Colour roles stored as preset slug + custom hex fallback.
	 * Attribute base name => CSS custom property.
	 */
	const COLOR_ROLES = array(
		'accentColor'     => '--lnyd-accent',
		'accentTextColor' => '--lnyd-accent-contrast',
		'nameColor'       => '--lnyd-name-color',
		'metaColor'       => '--lnyd-meta-color',
	);

	/**
	 * Render a card.
	 *
	 * @param array          $attributes Block attributes.
	 * @param \WP_Block|null $block      Block instance.
	 * @param array          $args       { wrapper: string|null pre-built wrapper attributes (legacy path) }.
	 * @return string
	 */
	public static function render( $attributes, $block = null, $args = array() ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$model      = Card_Data::build( $attributes, $block );
		$identity   = $model['identity'];

		$has_content = '' !== $identity['name'] || '' !== $identity['organization'] || ! empty( $model['contacts'] );
		if ( ! $has_content ) {
			return '';
		}

		$style  = self::style_vars( $attributes );
		$layout = self::layout( $attributes );

		if ( isset( $args['wrapper'] ) && is_string( $args['wrapper'] ) ) {
			$wrapper = $args['wrapper'] . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' );
		} else {
			$width = self::width( $attributes );
			$extra = array( 'class' => 'lnyd lnyd--' . $layout . ( 'auto' !== $width ? ' lnyd-width-' . $width : '' ) );
			if ( '' !== $style ) {
				$extra['style'] = $style;
			}
			// A stored is-style-* class (any old layout) stays in the
			// className that core adds here; no rule styles it any more, so
			// the card renders as Profile. The editor removes the class
			// through the v141 deprecation.
			$wrapper = get_block_wrapper_attributes( $extra );
		}

		$name_tag = isset( $attributes['nameTag'] ) && in_array( $attributes['nameTag'], array( 'h2', 'h3', 'h4', 'p' ), true )
			? $attributes['nameTag']
			: 'p';

		$out  = '<div ' . $wrapper . '>'; // get_block_wrapper_attributes() output is escaped by core; the legacy wrapper is built from esc_attr() parts.
		$out .= '<div class="lnyd__inner">';
		$out .= self::profile( $model, $attributes, $name_tag );
		$out .= '</div>';
		$out .= self::schema( $model, $attributes );
		$out .= '</div>';

		return $out;
	}

	/**
	 * Layout of a card: always 'profile' since 2.0.0.
	 *
	 * Kept as public API (themes may call it). Any stored is-style-* class,
	 * including the 1.1.0 to 1.4.1 names in OBSOLETE_STYLES, renders as
	 * Profile; the className is not read any more.
	 *
	 * @param array $attributes Attributes (unused).
	 * @return string One of LAYOUTS.
	 */
	public static function layout( $attributes ) {
		unset( $attributes );

		return 'profile';
	}

	/**
	 * Normalise a style name to a layout.
	 *
	 * Every old name (default, split, two-tone, centered, profile, and the
	 * older minimal, sidebar, banner and mirrored) and any unknown value map
	 * to 'profile' since 2.0.0.
	 *
	 * @param string $style Style name (unused).
	 * @return string One of LAYOUTS.
	 */
	public static function layout_name( $style ) {
		unset( $style );

		return 'profile';
	}

	/**
	 * Card width preset, validated against WIDTHS.
	 *
	 * @param array $attributes Attributes.
	 * @return string One of WIDTHS ('auto' when missing or invalid).
	 */
	public static function width( $attributes ) {
		$width = isset( $attributes['cardWidth'] ) && is_string( $attributes['cardWidth'] ) ? $attributes['cardWidth'] : 'auto';

		return in_array( $width, self::WIDTHS, true ) ? $width : 'auto';
	}

	/**
	 * Profile markup (1.3.0; the only look since 2.0.0): no card chrome, a QR icon button over a hero
	 * photo, a centred heading, a wide "Add to contacts" button, then
	 * left-aligned contact rows with outline icons (email first).
	 *
	 * The QR code opens in a popover (popover + popovertarget, no script).
	 * Its markup sits after the contacts, so a browser without the Popover
	 * API shows it inline there and style.scss hides the icon button. In the
	 * editor preview the button is an inert icon and no overlay is rendered,
	 * so clicking the preview never opens it.
	 *
	 * @param array  $model      Card model.
	 * @param array  $attributes Attributes.
	 * @param string $name_tag   Validated name element.
	 * @return string
	 */
	private static function profile( $model, $attributes, $name_tag ) {
		$identity = $model['identity'];

		$qr     = ! empty( $attributes['showQr'] ) ? self::qr( $model, $attributes ) : '';
		$toggle = '';
		$dialog = '';

		if ( '' !== $qr ) {
			if ( self::is_editor_preview() ) {
				$toggle = '<span class="lnyd__qr-toggle is-inert" aria-hidden="true">' . Icons::svg( 'qrcode' ) . '</span>';
			} else {
				$id      = wp_unique_id( 'lnyd-qr-' );
				$toggle  = '<button type="button" class="lnyd__qr-toggle" popovertarget="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Show QR code', 'lanyard' ) . '">' . Icons::svg( 'qrcode' ) . '</button>';
				$dialog  = '<div class="lnyd__qr-pop" id="' . esc_attr( $id ) . '" popover role="dialog" aria-label="' . esc_attr__( 'QR code', 'lanyard' ) . '">';
				$dialog .= '<button type="button" class="lnyd__qr-close" popovertarget="' . esc_attr( $id ) . '" popovertargetaction="hide" aria-label="' . esc_attr__( 'Close', 'lanyard' ) . '" autofocus>' . Icons::svg( 'x' ) . '</button>';
				$dialog .= $qr . '</div>';
			}
		}

		$photo = '';
		if ( ! empty( $attributes['showAvatar'] ) ) {
			$size  = ! empty( $identity['avatar']['sized'] ) && Tags::valid_size( $identity['avatar']['size'] ) ? $identity['avatar']['size'] : ( Tags::valid_size( 'large' ) ? 'large' : 'full' );
			$px    = self::PROFILE_SIZES[ self::width( $attributes ) ];
			$photo = self::avatar(
				$identity,
				array(
					'class'  => 'lnyd__avatar lnyd__photo',
					'size'   => $size,
					'sizes'  => '(max-width: ' . $px . 'px) 100vw, ' . $px . 'px',
					'width'  => $px,
					'height' => (int) round( $px * 1.25 ),
					'lazy'   => false,
				)
			);
		}

		$out = '';
		if ( '' !== $photo || '' !== $toggle ) {
			$out .= '<div class="lnyd__hero' . ( '' === $photo ? ' lnyd__hero--no-photo' : '' ) . '">' . $toggle . $photo . '</div>';
		}

		$out .= '<div class="lnyd__heading">';
		$out .= '' !== $identity['name'] ? '<' . $name_tag . ' class="lnyd__name">' . esc_html( $identity['name'] ) . '</' . $name_tag . '>' : '';
		$out .= '' !== $identity['title'] ? '<p class="lnyd__title">' . esc_html( $identity['title'] ) . '</p>' : '';
		$out .= '' !== $identity['organization'] ? '<p class="lnyd__company">' . esc_html( $identity['organization'] ) . '</p>' : '';
		$out .= '' !== $identity['tagline'] ? '<p class="lnyd__tagline">' . esc_html( $identity['tagline'] ) . '</p>' : '';
		$out .= '</div>';

		$out .= ! empty( $attributes['showVcard'] ) ? self::vcard_button( $model, $attributes, Icons::svg( 'plus' ) ) : '';

		$rows = array();
		foreach ( self::PROFILE_ORDER as $type ) {
			foreach ( $model['contacts'] as $row ) {
				if ( isset( $row['type'] ) && $type === $row['type'] ) {
					$rows[] = $row;
				}
			}
		}

		$contacts = self::contacts( $rows );
		if ( '' !== $contacts ) {
			$out .= '<div class="lnyd__body">' . $contacts . '</div>';
		}

		return $out . $dialog;
	}

	/**
	 * Whether this render is the editor preview (ServerSideRender calls the
	 * core block renderer REST route).
	 *
	 * wp_is_serving_rest_request() exists since WordPress 6.5 (Lanyard needs
	 * 6.6); the route is read from the rest_route query var that
	 * rest_api_loaded() dispatches, so other REST responses (for example
	 * rendered post content) keep the working button.
	 *
	 * Public since 2.1.0: Card_Data::current_post_id() uses it to allow the
	 * editor-only "Preview with" post (never on the front end). Core's
	 * block-renderer route requires edit_post for its post_id, or
	 * edit_posts without one (WP_REST_Block_Renderer_Controller, 6.6).
	 *
	 * @return bool
	 */
	public static function is_editor_preview() {
		if ( ! function_exists( 'wp_is_serving_rest_request' ) || ! wp_is_serving_rest_request() ) {
			return false;
		}

		global $wp;
		$route = $wp instanceof \WP && isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ? $wp->query_vars['rest_route'] : '';

		return 0 === strpos( $route, '/wp/v2/block-renderer/' );
	}

	/**
	 * CSS custom properties from the theme-aware style attributes.
	 *
	 * Preset slugs become var(--wp--preset--...) references, so the card
	 * follows theme.json changes; custom colours fall back to a validated hex.
	 *
	 * Explicit colours win (1.4.0). A role chosen in the block settings is an
	 * inline property, which beats any stylesheet value of the same property
	 * (theme, skin, light or dark mode). Skins and themes may also set the
	 * derived properties (button, icon, job title); when the role they derive
	 * from is chosen here, those are reset inline to `initial` (the
	 * guaranteed-invalid value), so style.scss derives them from the chosen
	 * role again instead of using the skin's value. The meta colour also
	 * becomes the job title colour, so "Job title, company, tagline" beats
	 * the accent-coloured title. (2.0.0: the Two-tone --lnyd-panel-meta reset
	 * was removed with that layout.)
	 *
	 * @param array $attributes Attributes.
	 * @return string Inline style declarations (unescaped; escaped by caller/core).
	 */
	public static function style_vars( $attributes ) {
		$decl = array();
		$set  = array();

		foreach ( self::COLOR_ROLES as $attr => $var ) {
			$value = self::color_value( $attributes, $attr );
			if ( '' !== $value ) {
				$decl[]       = $var . ':' . $value;
				$set[ $attr ] = $value;
			}
		}

		if ( isset( $set['metaColor'] ) ) {
			$decl[] = '--lnyd-title-color:' . $set['metaColor'];
		} elseif ( isset( $set['accentColor'] ) ) {
			$decl[] = '--lnyd-title-color:initial';
		}

		if ( isset( $set['accentColor'] ) ) {
			$decl[] = '--lnyd-button-bg:initial';
			$decl[] = '--lnyd-icon-color:initial';
			$decl[] = '--lnyd-button-gradient-end:initial';
		}

		if ( isset( $set['accentTextColor'] ) ) {
			$decl[] = '--lnyd-button-color:initial';
		}

		if ( isset( $set['accentColor'] ) || isset( $set['accentTextColor'] ) ) {
			$decl[] = '--lnyd-button-hover-bg:initial';
			$decl[] = '--lnyd-button-hover-color:initial';
		}

		$font = self::slug( isset( $attributes['nameFontFamily'] ) ? $attributes['nameFontFamily'] : '' );
		if ( '' !== $font ) {
			$decl[] = '--lnyd-name-font:var(--wp--preset--font-family--' . $font . ')';
		}

		$size = self::slug( isset( $attributes['nameFontSize'] ) ? $attributes['nameFontSize'] : '' );
		if ( '' !== $size ) {
			$decl[] = '--lnyd-name-size:var(--wp--preset--font-size--' . $size . ')';
		}

		return implode( ';', $decl );
	}

	/**
	 * Resolve one colour role.
	 *
	 * @param array  $attributes Attributes.
	 * @param string $attr       Base attribute name, e.g. accentColor.
	 * @return string CSS value or ''.
	 */
	private static function color_value( $attributes, $attr ) {
		$slug = self::slug( isset( $attributes[ $attr ] ) ? $attributes[ $attr ] : '' );
		if ( '' !== $slug ) {
			return 'var(--wp--preset--color--' . $slug . ')';
		}

		$custom_key = 'custom' . ucfirst( $attr );
		$hex        = isset( $attributes[ $custom_key ] ) && is_string( $attributes[ $custom_key ] ) ? sanitize_hex_color( $attributes[ $custom_key ] ) : null;

		return $hex ? $hex : '';
	}

	/**
	 * Validate a preset slug (core slugs are kebab-case).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function slug( $value ) {
		return is_string( $value ) && preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $value ) ? $value : '';
	}

	/**
	 * Avatar markup.
	 *
	 * @param array $identity Identity part of the model.
	 * @param array $opts     Optional { class, size, sizes, width, height, lazy } (Profile photo).
	 * @return string
	 */
	private static function avatar( $identity, $opts = array() ) {
		$alt   = '' !== $identity['avatar']['alt'] ? $identity['avatar']['alt'] : $identity['name'];
		$class = isset( $opts['class'] ) ? $opts['class'] : 'lnyd__avatar';
		$lazy  = ! isset( $opts['lazy'] ) || $opts['lazy'];

		if ( ! empty( $identity['avatar']['id'] ) ) {
			if ( isset( $opts['size'] ) ) {
				$size = $opts['size'];
			} else {
				$size = isset( $identity['avatar']['size'] ) && Tags::valid_size( $identity['avatar']['size'] ) ? $identity['avatar']['size'] : 'thumbnail';
			}

			$attr = array(
				'class' => $class,
				'alt'   => $alt,
			);
			if ( $lazy ) {
				$attr['loading'] = 'lazy';
			}
			if ( isset( $opts['sizes'] ) ) {
				// Core builds srcset; this only describes the rendered width.
				$attr['sizes'] = $opts['sizes'];
			}

			// Without 'loading', core decides (lazy, or fetchpriority for an
			// image near the top of the page).
			$img = wp_get_attachment_image( (int) $identity['avatar']['id'], $size, false, $attr );
			return $img ? $img : '';
		}

		if ( '' !== $identity['avatar']['url'] ) {
			$width  = isset( $opts['width'] ) ? absint( $opts['width'] ) : 96;
			$height = isset( $opts['height'] ) ? absint( $opts['height'] ) : 96;

			return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $identity['avatar']['url'] ) . '" alt="' . esc_attr( $alt ) . '" width="' . $width . '" height="' . $height . '"' . ( $lazy ? ' loading="lazy"' : '' ) . ' decoding="async" />';
		}

		return '';
	}

	/**
	 * Contact list.
	 *
	 * Tabler outline icons (Icons::contact()), in the order given (profile()
	 * passes PROFILE_ORDER).
	 *
	 * @param array[] $contacts Contact rows.
	 * @return string
	 */
	private static function contacts( $contacts ) {
		if ( empty( $contacts ) ) {
			return '';
		}

		$labels = array(
			'phone'    => __( 'Phone', 'lanyard' ),
			'mobile'   => __( 'Mobile', 'lanyard' ),
			'email'    => __( 'Email', 'lanyard' ),
			'website'  => __( 'Website', 'lanyard' ),
			'linkedin' => __( 'LinkedIn', 'lanyard' ),
			'address'  => __( 'Address', 'lanyard' ),
		);

		$out = '<ul class="lnyd__contacts">';

		foreach ( $contacts as $row ) {
			$type = isset( $row['type'], $labels[ $row['type'] ] ) ? $row['type'] : '';
			if ( '' === $type ) {
				continue;
			}

			$text = (string) $row['text'];
			$href = (string) $row['href'];

			$out .= '<li class="lnyd__contact lnyd__contact--' . esc_attr( $type ) . '">';
			$out .= '<span class="lnyd__icon" aria-hidden="true">' . Icons::contact( $type ) . '</span>';
			$out .= '<span class="screen-reader-text">' . esc_html( $labels[ $type ] ) . ': </span>';

			$url = self::contact_url( $type, $href );

			if ( '' !== $url ) {
				$display = 'email' === $type ? antispambot( $text ) : esc_html( $text );
				$rel     = in_array( $type, array( 'website', 'linkedin', 'address' ), true ) ? ' rel="noopener"' : '';
				$out    .= '<a class="lnyd__value" href="' . $url . '"' . $rel . '>' . $display . '</a>';
			} else {
				$out .= '<span class="lnyd__value">' . esc_html( $text ) . '</span>';
			}

			$out .= '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Escape a contact link with a protocol allow-list per type.
	 *
	 * @param string $type Contact type.
	 * @param string $href Raw href.
	 * @return string Escaped URL, or ''.
	 */
	private static function contact_url( $type, $href ) {
		if ( '' === $href ) {
			return '';
		}

		$protocols = array(
			'phone'    => array( 'tel' ),
			'mobile'   => array( 'tel' ),
			'email'    => array( 'mailto' ),
			'website'  => array( 'http', 'https' ),
			'linkedin' => array( 'http', 'https' ),
			'address'  => array( 'https' ),
		);

		if ( 'email' === $type ) {
			// Obfuscate the address part; antispambot() returns HTML entities, which are valid in an attribute.
			$email = substr( $href, 7 );
			// esc_attr() does not double-encode the entities antispambot() adds.
			return is_email( $email ) ? esc_attr( 'mailto:' . antispambot( $email, 1 ) ) : '';
		}

		return esc_url( $href, $protocols[ $type ] );
	}

	/**
	 * QR code figure.
	 *
	 * @param array $model      Card model.
	 * @param array $attributes Attributes.
	 * @return string
	 */
	private static function qr( $model, $attributes ) {
		$source = isset( $attributes['qrSource'] ) && 'url' === $attributes['qrSource'] ? 'url' : 'vcard';
		$text   = 'url' === $source ? $model['permalink'] : VCard::build( $model, true );

		if ( '' === trim( (string) $text ) ) {
			return '';
		}

		$name  = $model['identity']['name'];
		$title = '' !== $name
			/* translators: %s: name on the business card. */
			? sprintf( __( 'QR code with the contact details of %s', 'lanyard' ), $name )
			: __( 'QR code with the contact details on this card', 'lanyard' );

		$args = array(
			'size'  => 140,
			'dark'  => '#000000',
			'light' => '#ffffff',
			'ecc'   => 'M',
			'title' => $title,
		);

		// Non-persistent unless the site runs an object cache; nothing is
		// written to the options table.
		$key = 'qr_' . md5( $text . '|' . wp_json_encode( $args ) );
		$svg = wp_cache_get( $key, 'lnyd' );

		if ( ! is_string( $svg ) ) {
			$svg = QR::svg( $text, $args );
			$svg = is_wp_error( $svg ) ? '' : $svg;
			wp_cache_set( $key, $svg, 'lnyd', DAY_IN_SECONDS );
		}

		if ( '' === $svg ) {
			return '';
		}

		$caption = isset( $attributes['qrCaption'] ) ? Card_Data::text( $attributes['qrCaption'] ) : '';

		// QR::svg() builds its markup only from escaped numbers, a validated
		// colour and an esc_html() title; kses keeps that guarantee explicit.
		$out  = '<figure class="lnyd__qr">';
		$out .= '<span class="lnyd__qr-plate">' . wp_kses( $svg, self::svg_kses() ) . '</span>';
		if ( '' !== $caption ) {
			$out .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}

		return $out . '</figure>';
	}

	/**
	 * Allowed SVG markup for the QR code.
	 *
	 * @return array
	 */
	private static function svg_kses() {
		return array(
			'svg'   => array(
				'xmlns'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'shape-rendering' => true,
				'focusable'       => true,
				'role'            => true,
				'aria-labelledby' => true,
				'aria-hidden'     => true,
			),
			'title' => array( 'id' => true ),
			'rect'  => array(
				'width'  => true,
				'height' => true,
				'fill'   => true,
			),
			'path'  => array(
				'fill' => true,
				'd'    => true,
			),
		);
	}

	/**
	 * "Add to contacts" link with the vCard inline as a data: URI.
	 *
	 * No endpoint and no JavaScript: the browser downloads the file directly.
	 *
	 * @param array  $model      Card model.
	 * @param array  $attributes Attributes.
	 * @param string $icon       Optional static icon markup before the label (Icons::svg()).
	 * @return string
	 */
	private static function vcard_button( $model, $attributes, $icon = '' ) {
		$vcard = VCard::build( $model );
		if ( '' === $vcard ) {
			return '';
		}

		$label = isset( $attributes['vcardLabel'] ) ? Card_Data::text( $attributes['vcardLabel'] ) : '';
		if ( '' === $label ) {
			$label = __( 'Add to contacts', 'lanyard' );
		}

		$href = esc_url( 'data:text/vcard;charset=utf-8,' . rawurlencode( $vcard ), array( 'data' ) );
		if ( '' === $href ) {
			return '';
		}

		// The look comes from style.scss and the --lnyd-button-* properties.
		// wp-element-button is kept as a hook for themes; the core
		// wp-block-button(__link) classes are left out (1.3.0) so classic-theme
		// button rules (full width, fixed height, icons) do not reach this link.
		return '<div class="lnyd__actions"><a class="lnyd__vcard wp-element-button" href="' . $href . '" download="' . esc_attr( VCard::filename( $model ) ) . '">' . ( '' !== $icon ? $icon . '<span class="lnyd__vcard-label">' . esc_html( $label ) . '</span>' : esc_html( $label ) ) . '</a></div>';
	}

	/**
	 * JSON-LD block.
	 *
	 * @param array $model      Card model.
	 * @param array $attributes Attributes.
	 * @return string
	 */
	private static function schema( $model, $attributes ) {
		if ( isset( $attributes['schemaEnabled'] ) && ! $attributes['schemaEnabled'] ) {
			return '';
		}

		$identity = $model['identity'];
		$type     = isset( $attributes['schemaType'] ) ? $attributes['schemaType'] : 'auto';

		if ( 'person' === $type || ( 'auto' === $type && '' !== $identity['name'] ) ) {
			$type = 'Person';
		} elseif ( 'organization' === $type || ( 'auto' === $type && '' !== $identity['organization'] ) ) {
			$type = 'Organization';
		} else {
			return '';
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => 'Person' === $type && '' !== $identity['name'] ? $identity['name'] : $identity['organization'],
		);

		if ( '' === $data['name'] ) {
			return '';
		}

		if ( 'Person' === $type ) {
			if ( '' !== $identity['title'] ) {
				$data['jobTitle'] = $identity['title'];
			}
			if ( '' !== $identity['organization'] ) {
				$data['worksFor'] = array(
					'@type' => 'Organization',
					'name'  => $identity['organization'],
				);
			}
		}

		foreach ( $model['contacts'] as $row ) {
			switch ( $row['type'] ) {
				case 'phone':
				case 'mobile':
					if ( ! isset( $data['telephone'] ) ) {
						$data['telephone'] = $row['text'];
					}
					break;
				case 'email':
					if ( is_email( $row['text'] ) ) {
						$data['email'] = $row['text'];
					}
					break;
				case 'website':
					if ( '' !== $row['href'] ) {
						$data['url'] = $row['href'];
					}
					break;
				case 'linkedin':
					if ( '' !== $row['href'] ) {
						$data['sameAs'] = array( $row['href'] );
					}
					break;
				case 'address':
					$data['address'] = array(
						'@type'         => 'PostalAddress',
						'streetAddress' => $row['text'],
					);
					break;
			}
		}

		$json = wp_json_encode( $data, self::JSON_FLAGS );

		return false === $json ? '' : '<script type="application/ld+json">' . $json . '</script>';
	}
}
