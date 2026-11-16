<?php
/**
 * Block pattern: "Lanyard: Profile", in a "Lanyard" category.
 *
 * Static sample content only (no dynamic tags, no remote images), so the
 * pattern renders the same on every site until the editor changes it.
 * 2.0.0: Profile is the card's only look, so the four other layout patterns
 * (Stacked, Split, Two-tone panel, Centered with QR) were removed, and the
 * pattern no longer sets an is-style-* class.
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the pattern category and the Profile pattern.
 *
 * @return void
 */
function lnyd_register_patterns() {
	if ( ! function_exists( 'register_block_pattern' ) || ! function_exists( 'register_block_pattern_category' ) ) {
		return;
	}

	register_block_pattern_category(
		'lanyard',
		array( 'label' => __( 'Lanyard', 'lanyard' ) )
	);

	// Generic sample content (no real people, example.com addresses, a
	// fictional 555 number); translators may localise the visible text.
	// No photo: a pattern cannot ship a portable image, and a placeholder
	// would end up on live pages. The card works without one.
	$attrs = array(
		'name'       => __( 'Jane Doe', 'lanyard' ),
		'jobTitle'   => __( 'Head of Product', 'lanyard' ),
		'company'    => __( 'Example Company', 'lanyard' ),
		'phone'      => '+1 202 555 0143',
		'email'      => 'jane.doe@example.com',
		'website'    => 'https://www.example.com',
		'address'    => __( '1 Example Street, Example City', 'lanyard' ),
		'showQr'     => true,
		'showVcard'  => true,
	);

	register_block_pattern(
		'lanyard/card-profile',
		array(
			'title'       => __( 'Lanyard: Profile', 'lanyard' ),
			'description' => __( 'Digital business card on the page background: QR code button, name, job title, an Add to contacts button and contact rows with outline icons. Add a photo in the block settings.', 'lanyard' ),
			'categories'  => array( 'lanyard' ),
			'blockTypes'  => array( LNYD_BLOCK ),
			'keywords'    => array( 'business card', 'contact', 'vcard' ),
			'content'     => serialize_block(
				array(
					'blockName'    => LNYD_BLOCK,
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			),
		)
	);
}
add_action( 'init', 'lnyd_register_patterns', 20 );
