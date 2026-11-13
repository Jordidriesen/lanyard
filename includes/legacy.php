<?php
/**
 * Front-end fallback for blocks saved by the original plugin.
 *
 * A fork with a new block name cannot use block `deprecated` entries to
 * migrate `business/card` content (deprecations only apply within one block
 * name). Instead, when the original "Business Card Block" plugin is NOT
 * active, this registers a server-only `business/card` renderer that maps the
 * old attributes onto this plugin's card, so existing pages keep showing a
 * card after the original is removed. Hex colours from the old block are kept
 * as custom colours; the editor offers a transform to convert to the new block
 * while the original plugin is still active. Since 2.0.0 every old design
 * renders as Profile.
 *
 * Disable with: add_filter( 'lnyd_enable_legacy_renderer', '__return_false' );
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the legacy renderer late, after the original plugin had its chance.
 *
 * @return void
 */
function lnyd_register_legacy_block() {
	if ( ! apply_filters( 'lnyd_enable_legacy_renderer', true ) ) {
		return;
	}

	if ( WP_Block_Type_Registry::get_instance()->is_registered( 'business/card' ) ) {
		return; // Original plugin is active: leave its block alone.
	}

	register_block_type(
		'business/card',
		array(
			'render_callback' => 'lnyd_render_legacy_block',
			'uses_context'    => array( 'postId' ),
		)
	);
}
add_action( 'init', 'lnyd_register_legacy_block', 20 );

/**
 * Render an old business/card block through the new renderer.
 *
 * @param array    $attributes Raw attributes of the old block.
 * @param string   $content    Saved content (unused; old block saved none).
 * @param WP_Block $block      Block instance.
 * @return string
 */
function lnyd_render_legacy_block( $attributes, $content = '', $block = null ) {
	$mapped = lnyd_map_legacy_attributes( is_array( $attributes ) ? $attributes : array() );

	$new_type = WP_Block_Type_Registry::get_instance()->get_registered( LNYD_BLOCK );
	if ( $new_type ) {
		foreach ( (array) $new_type->style_handles as $handle ) {
			wp_enqueue_style( $handle );
		}
	}

	// Every old design renders as Profile (2.0.0); no is-style-* class.
	$layout  = Lanyard\Renderer::layout_name( $mapped['style'] );
	$classes = array( 'wp-block-lanyard-card', 'lnyd', 'lnyd--legacy', 'lnyd--' . $layout );
	if ( ! empty( $attributes['align'] ) && in_array( $attributes['align'], array( 'wide', 'full' ), true ) ) {
		$classes[] = 'align' . $attributes['align'];
	}

	$wrapper = 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';

	$html = Lanyard\Renderer::render(
		$mapped,
		$block,
		array(
			'wrapper' => $wrapper,
		)
	);

	return '' === $html ? '' : lnyd_late_style_markup() . $html;
}

/**
 * Map Business Card Block 2.1.7 attributes onto this plugin's attributes.
 *
 * @param array $a Old attributes.
 * @return array New attributes (plus a 'style' key for the block style).
 */
function lnyd_map_legacy_attributes( $a ) {
	$bc = isset( $a['businessCard'] ) && is_array( $a['businessCard'] ) ? $a['businessCard'] : array();

	$new = array(
		'name'          => isset( $a['name'] ) ? $a['name'] : 'Jonathon Doe',
		'jobTitle'      => isset( $a['title'] ) ? $a['title'] : 'Chief Executive Officer',
		'company'       => isset( $bc['company'] ) ? $bc['company'] : '',
		'tagline'       => isset( $bc['tagline'] ) ? $bc['tagline'] : '',
		'showVcard'     => ! empty( $bc['isDownloadBtn'] ),
		'vcardLabel'    => isset( $bc['downloadLabel'] ) ? $bc['downloadLabel'] : '',
		'showQr'        => ! empty( $a['qr']['enable'] ),
		'qrSource'      => isset( $a['qr']['source'] ) && 'url' === $a['qr']['source'] ? 'url' : 'vcard',
		'qrCaption'     => isset( $a['qr']['caption'] ) ? $a['qr']['caption'] : '',
		'schemaEnabled' => ! isset( $a['schemaEnabled'] ) || ! empty( $a['schemaEnabled'] ),
		'schemaType'    => isset( $a['schemaType'] ) && in_array( $a['schemaType'], array( 'auto', 'person', 'organization' ), true ) ? $a['schemaType'] : 'auto',
		'showAvatar'    => ! empty( $a['showAvatar'] ),
		'avatarUrl'     => isset( $a['avatar']['url'] ) ? $a['avatar']['url'] : '',
		'avatarId'      => isset( $a['avatar']['id'] ) ? absint( $a['avatar']['id'] ) : 0,
		// Old hex colours become custom colours (theme presets are not guessable).
		'customNameColor'       => isset( $a['nameColor'] ) ? $a['nameColor'] : '#4527a4',
		'customMetaColor'       => isset( $a['titleColor'] ) ? $a['titleColor'] : '#828282',
		'customAccentColor'     => isset( $a['contactIconColors']['bg'] ) ? $a['contactIconColors']['bg'] : '#4527a4',
		'customAccentTextColor' => isset( $a['contactIconColors']['color'] ) ? $a['contactIconColors']['color'] : '#ffffff',
	);

	// Contacts: first row of each supported type wins.
	$contacts = isset( $a['contacts'] ) && is_array( $a['contacts'] )
		? $a['contacts']
		: array(
			array( 'type' => 'address', 'text' => 'Your Address, State, City, Country, 0000' ),
			array( 'type' => 'phone', 'text' => '+000 0000-00000' ),
			array( 'type' => 'email', 'text' => 'example@mail.com' ),
			array( 'type' => 'website', 'text' => 'https://example.com' ),
		);

	foreach ( $contacts as $row ) {
		if ( ! is_array( $row ) || empty( $row['type'] ) || ! isset( $row['text'] ) ) {
			continue;
		}
		$type = strtolower( (string) $row['type'] );
		$map  = array(
			'phone'   => 'phone',
			'email'   => 'email',
			'website' => 'website',
			'address' => 'address',
			'company' => 'company',
		);
		if ( isset( $map[ $type ] ) && empty( $new[ $map[ $type ] ] ) ) {
			$new[ $map[ $type ] ] = $row['text'];
		}
	}

	if ( ! empty( $a['socials'] ) && is_array( $a['socials'] ) ) {
		foreach ( $a['socials'] as $social ) {
			if ( is_array( $social ) && ! empty( $social['link'] ) && false !== stripos( (string) $social['link'], 'linkedin.com' ) ) {
				$new['linkedin'] = $social['link'];
				break;
			}
		}
	}

	// Every old design (the free default, theme1, theme2, theme3 and
	// minimal_qr, and the Pro theme4 to theme8) maps to Profile, the only
	// look since 2.0.0. The old `theme` value is not read any more.
	$new['style'] = 'profile';

	return $new;
}
