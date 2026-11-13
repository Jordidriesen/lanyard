<?php
/**
 * Front-end asset loading: the card stylesheet only on pages that show a card.
 *
 * The card has no front-end JavaScript; its only front-end asset is the
 * block stylesheet (build/card/style-index.css).
 *
 * Block themes, and classic themes where WordPress loads block assets on
 * demand, already enqueue it only when a card renders. Classic themes
 * without on-demand loading (wp_should_load_separate_core_block_assets()
 * is false) get every registered block stylesheet on every page; there the
 * plugin removes it again unless the page's own content holds a card.
 *
 * A card rendered outside the post content (a Blocksy Content Block, a
 * widget, a hook) after the head was printed gets its stylesheet as a
 * <link> right before the card, so it never shows unstyled and is never
 * printed twice.
 *
 * Filter lnyd_style_in_head to force the stylesheet into the head (true)
 * or leave it to render time (false) on a given page.
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Style handles of the card block.
 *
 * @return string[]
 */
function lnyd_style_handles() {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( LNYD_BLOCK );

	return $type instanceof WP_Block_Type ? array_values( array_filter( (array) $type->style_handles, 'is_string' ) ) : array();
}

/**
 * Whether the main content of the current request holds a card.
 *
 * @return bool
 */
function lnyd_content_has_card() {
	$has = false;

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$has = has_block( LNYD_BLOCK, $post ) || has_block( 'business/card', $post );
		}
	}

	/**
	 * Filter whether the card stylesheet loads in the head on this request.
	 *
	 * @param bool $has True when the queried post's content holds a card.
	 */
	return (bool) apply_filters( 'lnyd_style_in_head', $has );
}

/**
 * Remove the globally enqueued stylesheet on pages without a card.
 *
 * Runs after enqueue_block_assets (wp_enqueue_scripts, priority 10).
 *
 * @return void
 */
function lnyd_trim_front_styles() {
	if ( wp_should_load_separate_core_block_assets() || lnyd_content_has_card() ) {
		return;
	}

	foreach ( lnyd_style_handles() as $handle ) {
		wp_dequeue_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'lnyd_trim_front_styles', 100 );

/**
 * The card stylesheet as markup, when it has to be printed with the card.
 *
 * Only on the front end, after the head styles were printed, and only the
 * first time (WP_Styles marks the handle done, so the footer skips it).
 *
 * @return string <link> markup or ''.
 */
function lnyd_late_style_markup() {
	if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
		return '';
	}

	if ( ! did_action( 'wp_print_styles' ) ) {
		return ''; // Head not printed yet: WP_Block::render's enqueue lands there.
	}

	$todo = array();
	foreach ( lnyd_style_handles() as $handle ) {
		if ( ! wp_style_is( $handle, 'done' ) ) {
			$todo[] = $handle;
		}
	}

	if ( empty( $todo ) ) {
		return '';
	}

	ob_start();
	wp_print_styles( $todo );

	return (string) ob_get_clean();
}
