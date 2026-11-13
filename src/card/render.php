<?php
/**
 * Server render for lanyard/card.
 *
 * @var array    $attributes Block attributes (Block Bindings values already merged in by core).
 * @var string   $content    Unused (dynamic block, saves no markup).
 * @var WP_Block $block      Block instance.
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Renderer::render() escapes every value at output (see includes/class-renderer.php).
$lnyd_html = Lanyard\Renderer::render( $attributes, $block );

if ( '' !== $lnyd_html ) {
	// Core <link> markup from wp_print_styles() when the card renders after
	// the head (includes/assets.php), then the card.
	echo lnyd_late_style_markup() . $lnyd_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
