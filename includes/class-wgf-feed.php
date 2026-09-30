<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Feed {

	public static function get_allowed_locale_prefixes() {
		if ( class_exists( 'WGF_Translator' ) ) {
			return WGF_Translator::get_supported_languages();
		}
		return [ 'de', 'fr', 'it', 'en' ];
	}

	protected static function normalize_locale_segment( $segment ) {
		$segment = strtolower( trim( (string) $segment ) );
		if ( $segment === '' ) {
			return '';
		}

		if ( ! preg_match( '/^[a-z]{2}(?:-[a-z]{2,4})?$/', $segment ) ) {
			return '';
		}

		$parts = explode( '-', $segment );
		$base  = (string) $parts[0];
		if ( ! in_array( $base, self::get_allowed_locale_prefixes(), true ) ) {
			return '';
		}

		return $base;
	}

	protected static function is_locale_segment( $segment ) {
		return self::normalize_locale_segment( $segment ) !== '';
	}

	protected static function path_matches_feed_slug( $req_path, $feed_slug ) {
		$req_path  = trim( strtolower( (string) $req_path ), '/' );
		$feed_slug = trim( strtolower( (string) $feed_slug ), '/' );

		if ( $req_path === '' || $feed_slug === '' ) {
			return false;
		}

		// Canonical feed URL.
		if ( $req_path === $feed_slug ) {
			return true;
		}

		// Language-prefixed feed URL (e.g. /en/gmc-feed-xml/).
		$parts = array_values( array_filter( explode( '/', $req_path ), 'strlen' ) );
		if ( count( $parts ) !== 2 ) {
			return false;
		}

		return self::is_locale_segment( $parts[0] ) && $parts[1] === $feed_slug;
	}

	protected static function extract_locale_for_feed_slug( $req_path, $feed_slug ) {
		$req_path  = trim( strtolower( (string) $req_path ), '/' );
		$feed_slug = trim( strtolower( (string) $feed_slug ), '/' );
		if ( $req_path === '' || $feed_slug === '' ) {
			return '';
		}

		$parts = array_values( array_filter( explode( '/', $req_path ), 'strlen' ) );
		if ( count( $parts ) !== 2 || $parts[1] !== $feed_slug ) {
			return '';
		}

		return self::normalize_locale_segment( $parts[0] );
	}

	protected static function get_request_path() {
		$req_path = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$req_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			$req_path = trim( $req_path, '/' );
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = trim( $home_path, '/' );
		if ( $home_path !== '' && strpos( $req_path, $home_path . '/' ) === 0 ) {
			$req_path = substr( $req_path, strlen( $home_path ) + 1 );
		}

		return trim( (string) $req_path, '/' );
	}

	protected static function detect_product_feed_language() {
		$explicit = self::detect_explicit_language();
		if ( $explicit !== '' ) {
			return $explicit;
		}
		$opts = WGF_Settings::get_settings();
		$slug = trim( (string) $opts['feed_slug'], '/' );
		return self::extract_locale_for_feed_slug( self::get_request_path(), $slug );
	}

	protected static function detect_explicit_language() {
		$raw = '';
		if ( isset( $_GET['wgf_lang'] ) ) {
			$raw = wp_unslash( $_GET['wgf_lang'] );
		}
		if ( $raw === '' && isset( $_SERVER['HTTP_X_GT_LANG'] ) ) {
			$raw = (string) $_SERVER['HTTP_X_GT_LANG'];
		}

		$language = class_exists( 'WGF_Translator' ) ? WGF_Translator::normalize_language( $raw ) : strtolower( trim( (string) $raw ) );
		if ( $language !== '' && in_array( $language, self::get_allowed_locale_prefixes(), true ) ) {
			return $language;
		}
		return '';
	}

	protected static function get_cache_key( $language = '' ) {
		$language = class_exists( 'WGF_Translator' ) ? WGF_Translator::normalize_language( $language ) : strtolower( trim( (string) $language ) );
		return $language !== '' ? WGF_TRANSIENT_KEY . '_' . $language : WGF_TRANSIENT_KEY;
	}

	public static function flush_cache() {
		delete_transient( WGF_TRANSIENT_KEY );
		foreach ( self::get_allowed_locale_prefixes() as $language ) {
			delete_transient( self::get_cache_key( $language ) );
		}
	}

	public static function add_rewrite_rules() {
		$opts = WGF_Settings::get_settings();
		add_rewrite_rule( '^' . preg_quote( $opts['feed_slug'], '/' ) . '/?$', 'index.php?wgf_feed=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( $opts['promotions_feed_slug'], '/' ) . '/?$', 'index.php?wgf_promotions=1', 'top' );
	}

	public static function register_query_var( $vars ) {
		$vars[] = 'wgf_feed';
		$vars[] = 'wgf_promotions';
		return $vars;
	}

	public static function maybe_render_feed() {
		// Fallback path match (useful when host rewrite/canonical handling is inconsistent).
		$req_path = self::get_request_path();
		$opts = WGF_Settings::get_settings();
		$product_slug = trim( (string) $opts['feed_slug'], '/' );
		$promo_slug   = trim( (string) $opts['promotions_feed_slug'], '/' );
		if ( self::path_matches_feed_slug( $req_path, $product_slug ) ) {
			$language = self::extract_locale_for_feed_slug( $req_path, $product_slug );
			self::output_xml( $language );
			exit;
		}
		if ( self::path_matches_feed_slug( $req_path, $promo_slug ) ) {
			WGF_Promotions::output_xml();
			exit;
		}

		$render = get_query_var( 'wgf_feed' );
		if ( ! empty( $render ) ) {
			self::output_xml( self::extract_locale_for_feed_slug( $req_path, $product_slug ) );
			exit;
		}
		$render_promotions = get_query_var( 'wgf_promotions' );
		if ( ! empty( $render_promotions ) ) {
			WGF_Promotions::output_xml();
			exit;
		}
		// Also allow ?woo_gmc=1 for convenience.
		if ( isset( $_GET['woo_gmc'] ) && '1' === $_GET['woo_gmc'] ) {
			self::output_xml( self::extract_locale_for_feed_slug( $req_path, $product_slug ) );
			exit;
		}
		// Also allow ?woo_gmc_promotions=1 for convenience.
		if ( isset( $_GET['woo_gmc_promotions'] ) && '1' === $_GET['woo_gmc_promotions'] ) {
			WGF_Promotions::output_xml();
			exit;
		}
	}

	public static function get_feed_url() {
		$opts = WGF_Settings::get_settings();
		return home_url( '/' . trim( $opts['feed_slug'], '/' ) . '/' );
	}

	protected static function query_products( $page = 1, $per_page = 200 ) {
		$opts = WGF_Settings::get_settings();

		$tax_query = [];
		$meta_query = [];

		if ( 'no' === $opts['include_hidden'] ) {
			$tax_query[] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => [ 'exclude-from-catalog', 'exclude-from-search' ],
				'operator' => 'NOT IN',
			];
		}

		if ( 'no' === $opts['include_out_of_stock'] ) {
			$meta_query[] = [
				'key'     => '_stock_status',
				'value'   => 'outofstock',
				'compare' => '!=',
			];
		}

		if ( 'yes' === $opts['only_bundle_tile'] && ! empty( $opts['bundle_meta_key'] ) ) {
			$meta_query[] = [
				'key'     => $opts['bundle_meta_key'],
				'compare' => 'EXISTS',
			];
		}

		$args = [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'tax_query'      => $tax_query,
			'meta_query'     => $meta_query,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];

		if ( 'parent_only' === $opts['variations_mode'] ) {
			$args['post_type'] = [ 'product' ];
		}

		return new WP_Query( $args );
	}

	protected static function collect_items() {
		$opts = WGF_Settings::get_settings();
		$max  = intval( $opts['max_items'] );
		$items = [];
		$page = 1;
		$per_page = 200;

		while ( true ) {
			$q = self::query_products( $page, $per_page );
			if ( empty( $q->posts ) ) { break; }

			foreach ( $q->posts as $post_id ) {
				$product = wc_get_product( $post_id );
				if ( ! $product ) { continue; }

				// Skip variations if parent_only
				if ( 'parent_only' === $opts['variations_mode'] && $product->is_type( 'variation' ) ) {
					continue;
				}

				// Per-product include toggle.
				$include = get_post_meta( $product->get_id(), '_gmc_include', true );
				if ( $include === 'no' ) { continue; }
				if ( 'yes' === ( $opts['merchant_ready_only'] ?? 'no' ) && ! WGF_Renderer::is_merchant_ready( $product ) ) {
					continue;
				}

				$items[] = $product;

				if ( $max > 0 && count( $items ) >= $max ) {
					break 2;
				}
			}
			$page++;
			if ( $max > 0 && count( $items ) >= $max ) { break; }
		}

		return $items;
	}

	public static function get_feed_products() {
		return self::collect_items();
	}

	public static function warm_cache() {
		// Prepare XML and store transient without output.
		delete_transient( self::get_cache_key( '' ) );
		$opts = WGF_Settings::get_settings();
		$items = self::collect_items();
		$xml   = WGF_Renderer::render_xml( $items, [ 'language' => '' ] );
		$cache_seconds = max( 0, intval( $opts['cache_hours'] ) ) * HOUR_IN_SECONDS;
		if ( $cache_seconds > 0 ) {
			set_transient( WGF_TRANSIENT_KEY, $xml, $cache_seconds );
		}
	}

	public static function output_xml( $language = '' ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_die( esc_html__( 'WooCommerce is required for this feed.', 'woo-gmc-feed' ) );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'woo-gmc-feed' );

		$opts = WGF_Settings::get_settings();
		if ( class_exists( 'WGF_Translator' ) ) {
			$language = WGF_Translator::normalize_language( $language );
		}
		if ( $language === '' ) {
			$language = self::detect_product_feed_language();
		}
		$cache_key = self::get_cache_key( $language );

		// Caching.
		$xml = get_transient( $cache_key );
		if ( false === $xml ) {
			$items = self::collect_items();
			$xml   = WGF_Renderer::render_xml( $items, [ 'language' => $language ] );
			$cache_seconds = max( 0, intval( $opts['cache_hours'] ) ) * HOUR_IN_SECONDS;
			$translation_complete = ! method_exists( 'WGF_Renderer', 'last_translation_complete' ) || WGF_Renderer::last_translation_complete();
			if ( $cache_seconds > 0 && $translation_complete ) {
				set_transient( $cache_key, $xml, $cache_seconds );
			}
		}

		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
