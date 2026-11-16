<?php
/**
 * Plugin Name:       Lanyard
 * Description:       A contact card block for people and teams. Fill any field with dynamic data (this post, another post, the site, a user, or ACF fields) using inline tags, shown as a digital business card (Profile) that takes on your block theme's colours and fonts. Fork of Business Card Block 2.1.7 by bPlugins.
 * Version:           2.3.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Jordi Driesen; based on Business Card Block by bPlugins
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       lanyard
 * Domain Path:       /languages
 * Update URI:        false
 *
 * This program is a modified version of "Business Card Block" 2.1.7
 * (https://wordpress.org/plugins/business-card-block/), Copyright bPlugins,
 * licensed GPLv3 or later. Modifications Copyright 2026 Jordi Driesen.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version. It is distributed WITHOUT ANY WARRANTY; see the GNU
 * General Public License for more details.
 *
 * @package Lanyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LNYD_VERSION', '2.3.0' );
define( 'LNYD_FILE', __FILE__ );
define( 'LNYD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LNYD_URL', plugin_dir_url( __FILE__ ) );
define( 'LNYD_BLOCK', 'lanyard/card' );

/*
 * 2.2.0: the card classes load on first use (a card renders, the REST
 * picker runs, a filter needs them), not on every request.
 */
spl_autoload_register(
	static function ( $class_name ) {
		static $map = array(
			'lanyard\\qr'          => 'class-qr.php',
			'lanyard\\acf_source'  => 'class-acf-source.php',
			'lanyard\\core_source' => 'class-core-source.php',
			'lanyard\\tags'        => 'class-tags.php',
			'lanyard\\card_data'   => 'class-card-data.php',
			'lanyard\\vcard'       => 'class-vcard.php',
			'lanyard\\icons'       => 'class-icons.php',
			'lanyard\\renderer'    => 'class-renderer.php',
		);

		$key = strtolower( ltrim( (string) $class_name, '\\' ) );
		if ( isset( $map[ $key ] ) ) {
			require_once LNYD_DIR . 'includes/' . $map[ $key ];
		}
	}
);

require_once LNYD_DIR . 'includes/class-rest.php';
require_once LNYD_DIR . 'includes/assets.php';
require_once LNYD_DIR . 'includes/legacy.php';
require_once LNYD_DIR . 'includes/privacy.php';
require_once LNYD_DIR . 'includes/patterns.php';

/**
 * Load translations (private plugin, so not loaded automatically from wordpress.org).
 *
 * @return void
 */
function lnyd_load_textdomain() {
	load_plugin_textdomain( 'lanyard', false, dirname( plugin_basename( LNYD_FILE ) ) . '/languages' );
}
add_action( 'init', 'lnyd_load_textdomain', 5 );

/**
 * Register the block from the compiled build folder.
 *
 * @return void
 */
function lnyd_register_block() {
	$dir = LNYD_DIR . 'build/card';

	if ( ! file_exists( $dir . '/block.json' ) ) {
		// Source not built yet (npm run build). Fail quietly instead of fataling.
		return;
	}

	$type = register_block_type( $dir );

	if ( $type instanceof WP_Block_Type ) {
		foreach ( (array) $type->editor_script_handles as $handle ) {
			wp_set_script_translations( $handle, 'lanyard', LNYD_DIR . 'languages' );
		}
	}
}
add_action( 'init', 'lnyd_register_block' );

/**
 * Allow the content attributes to be bound with the Block Bindings API.
 *
 * The filter exists from WordPress 6.9; on older versions it simply never
 * fires. Sources such as `acf/field` (ACF 6.3+) and `core/post-meta` then
 * fill the attribute before render.php runs (WP_Block::render merges bound
 * values into $attributes before calling the render callback).
 *
 * @param string[] $supported Supported attribute names.
 * @return string[]
 */
function lnyd_bindable_attributes( $supported ) {
	$supported = is_array( $supported ) ? $supported : array();

	return array_values( array_unique( array_merge( $supported, Lanyard\Card_Data::text_fields(), array( 'avatarUrl' ) ) ) );
}
add_filter( 'block_bindings_supported_attributes_' . LNYD_BLOCK, 'lnyd_bindable_attributes' );
