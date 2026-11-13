<?php
/**
 * Uninstall: remove everything this plugin stored.
 *
 * The plugin stores no options, meta, tables, cron events or transients.
 * QR codes are cached only in the object cache (group "lnyd", expiring after
 * a day). Card content lives in post content as block markup and is
 * deliberately left in place: deleting it would destroy pages. ACF field
 * values belong to ACF and are not touched.
 *
 * @package Lanyard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
	wp_cache_flush_group( 'lnyd' );
}
