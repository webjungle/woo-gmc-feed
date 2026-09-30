<?php
/**
 * Plugin Name: Woo GMC Feed (Custom)
 * Description: Generates Google Merchant Center product and promotions feeds for WooCommerce with category mapping, shipping settings, COGS export, quantity-discount promotions, per-product overrides, and a cron cache warmer.
 * Version: 1.3.3
 * Author: ChatGPT for Luca
 * Text Domain: woo-gmc-feed
 * Domain Path: /languages
 *
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WGF_VERSION', '1.3.3' );
define( 'WGF_FILE', __FILE__ );
define( 'WGF_DIR', plugin_dir_path( __FILE__ ) );
define( 'WGF_URL', plugin_dir_url( __FILE__ ) );
define( 'WGF_OPTION_KEY', 'wgf_settings' );
define( 'WGF_TRANSIENT_KEY', 'wgf_feed_xml_v130' );
define( 'WGF_PROMO_TRANSIENT_KEY', 'wgf_promotions_xml' );
define( 'WGF_CRON_HOOK', 'wgf_warm_cache_event' );

// Load textdomain on init (avoid WP 6.7 notice).
add_action( 'init', function() {
	load_plugin_textdomain( 'woo-gmc-feed', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

require_once WGF_DIR . 'includes/class-wgf-settings.php';
require_once WGF_DIR . 'includes/class-wgf-feed.php';
require_once WGF_DIR . 'includes/class-wgf-renderer.php';
require_once WGF_DIR . 'includes/class-wgf-promotions.php';
require_once WGF_DIR . 'includes/class-wgf-metabox.php';
require_once WGF_DIR . 'includes/class-wgf-translator.php';

/**
 * Activation: add rewrite rule and schedule cron.
 */
function wgf_activate() {
	WGF_Feed::add_rewrite_rules();
	flush_rewrite_rules();
	WGF_Settings::maybe_reschedule_cron();
}
register_activation_hook( __FILE__, 'wgf_activate' );

/**
 * Deactivation: unschedule cron + flush rewrites.
 */
function wgf_deactivate() {
	$timestamp = wp_next_scheduled( WGF_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, WGF_CRON_HOOK );
	}
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wgf_deactivate' );

// Admin.
add_action( 'admin_menu', [ 'WGF_Settings', 'register_menu' ] );
add_action( 'admin_init', [ 'WGF_Settings', 'register_settings' ] );
add_action( 'admin_post_wgf_flush_feed', [ 'WGF_Settings', 'handle_flush_feed' ] );
add_action( 'admin_post_wgf_save_settings', [ 'WGF_Settings', 'handle_save_settings' ] );

// Metabox.
add_action( 'add_meta_boxes', [ 'WGF_Metabox', 'register' ] );
add_action( 'save_post_product', [ 'WGF_Metabox', 'save' ] );

// Front: rewrite + render feed.
add_action( 'init', [ 'WGF_Feed', 'add_rewrite_rules' ] );
add_filter( 'query_vars', [ 'WGF_Feed', 'register_query_var' ] );
add_action( 'template_redirect', [ 'WGF_Feed', 'maybe_render_feed' ] );

// Invalidate cache on product/save updates and settings save.
add_action( 'save_post_product', function() {
	WGF_Feed::flush_cache();
	delete_transient( WGF_PROMO_TRANSIENT_KEY );
} );
add_action( 'updated_option', function( $opt ) {
	if ( $opt === WGF_OPTION_KEY ) {
		WGF_Feed::flush_cache();
		delete_transient( WGF_PROMO_TRANSIENT_KEY );
		WGF_Settings::maybe_reschedule_cron();
	}
	if ( $opt === 'woowin_bulk_pricing_settings' ) {
		WGF_Feed::flush_cache();
		delete_transient( WGF_PROMO_TRANSIENT_KEY );
	}
}, 10, 1 );

// Cron warm cache.
add_action( WGF_CRON_HOOK, function() {
	WGF_Feed::warm_cache();
	WGF_Promotions::warm_cache();
} );

// Custom schedules.
add_filter( 'cron_schedules', function( $schedules ) {
	$opts = WGF_Settings::get_settings();
	$mins = max( 5, intval( $opts['cron_interval_minutes'] ?? 240 ) );
	$key  = 'wgf_every_' . $mins . '_minutes';
	$schedules[ $key ] = [
		'interval' => $mins * 60,
		'display'  => sprintf( __( 'Every %d minutes (WGF)', 'woo-gmc-feed' ), $mins ),
	];
	return $schedules;
});
