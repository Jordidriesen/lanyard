<?php
/**
 * Privacy policy text suggestion.
 *
 * The plugin stores no data of its own: card content lives in post content
 * (block attributes) or in ACF fields that the site already manages. See the
 * readme for the export/erase reasoning.
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggest privacy policy text.
 *
 * @return void
 */
function lnyd_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$text = '<p>' . __( 'Business cards on this site show work contact details of our staff (name, job title, phone, email, address, photo, LinkedIn profile). These details are published with the staff member’s knowledge for the purpose of being contacted in a business context. The cards and their "save contact" files are generated on this server; no data is sent to third parties when a card is displayed or downloaded.', 'lanyard' ) . '</p>'
		. '<p>' . __( 'A card can also show details from a user profile on this site (display name, first and last name, nickname, biographical info, website, profile picture). A user’s email address is only shown on posts that user wrote, unless the site owner allows more. Profile pictures from external services such as Gravatar are not loaded unless the site owner allows that service.', 'lanyard' ) . '</p>';

	wp_add_privacy_policy_content( __( 'Lanyard', 'lanyard' ), wp_kses_post( $text ) );
}
add_action( 'admin_init', 'lnyd_privacy_policy_content' );
