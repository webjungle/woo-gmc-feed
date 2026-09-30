<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Promotions {

	protected static function sanitize_promotion_id( $id ) {
		$id = trim( (string) $id );
		if ( $id === '' ) {
			return '';
		}
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '-', $id );
		$id = preg_replace( '/-+/', '-', $id );
		$id = trim( $id, '-' );
		if ( $id === '' ) {
			return '';
		}
		return substr( $id, 0, 50 );
	}

	protected static function parse_csv_values( $raw ) {
		$parts = preg_split( '/[\r\n,]+/', (string) $raw );
		$out   = [];
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	protected static function parse_destinations( $raw ) {
		$allowed = [ 'Shopping_ads', 'Free_listings', 'YouTube_affiliate', 'Local_inventory_ads' ];
		$in      = self::parse_csv_values( $raw );
		$out     = [];
		foreach ( $in as $dest ) {
			if ( in_array( $dest, $allowed, true ) ) {
				$out[] = $dest;
			}
		}
		if ( empty( $out ) ) {
			return [ 'Shopping_ads', 'Free_listings' ];
		}
		return array_values( array_unique( $out ) );
	}

	protected static function get_effective_dates() {
		$opts = WGF_Settings::get_settings();
		$days = max( 1, min( 183, intval( $opts['promotion_effective_days'] ?? 180 ) ) );

		$start = current_time( 'timestamp', true );
		$end   = $start + ( $days * DAY_IN_SECONDS );

		return gmdate( 'c', $start ) . '/' . gmdate( 'c', $end );
	}

	protected static function get_wwbp_tiers_for_product( $product ) {
		if ( ! function_exists( 'wwbp_get_settings' ) || ! function_exists( 'wwbp_normalize_tiers' ) ) {
			return [];
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$opts       = wwbp_get_settings();

		if ( ! empty( $opts['data_source'] ) && $opts['data_source'] === 'external_meta' ) {
			if ( ! empty( $opts['external_structure'] ) && $opts['external_structure'] === 'rows' ) {
				if ( ! empty( $opts['external_pairs'] ) && is_array( $opts['external_pairs'] ) && function_exists( 'wwbp_build_tiers_from_pairs_meta' ) ) {
					$rows = wwbp_build_tiers_from_pairs_meta( $opts['external_pairs'], $product_id );
					if ( ! empty( $rows ) ) {
						return $rows;
					}
				}
				// Fallback to local tiers meta if external rows are currently empty.
				$fallback_raw = get_post_meta( $product_id, '_woowin_bulk_tiers', true );
				return wwbp_normalize_tiers( $fallback_raw );
			}

			$external_meta_key = isset( $opts['external_meta_key'] ) ? (string) $opts['external_meta_key'] : '';
			if ( $external_meta_key === '' ) {
				$fallback_raw = get_post_meta( $product_id, '_woowin_bulk_tiers', true );
				return wwbp_normalize_tiers( $fallback_raw );
			}

			$raw = get_post_meta( $product_id, $external_meta_key, true );
			$parsed = wwbp_normalize_tiers( $raw );
			if ( ! empty( $parsed ) ) {
				return $parsed;
			}
			$fallback_raw = get_post_meta( $product_id, '_woowin_bulk_tiers', true );
			return wwbp_normalize_tiers( $fallback_raw );
		}

		$meta_key = defined( 'WWBP_META_TIERS' ) ? WWBP_META_TIERS : '_woowin_bulk_tiers';
		$raw      = get_post_meta( $product_id, $meta_key, true );
		return wwbp_normalize_tiers( $raw );
	}

	protected static function get_base_price( $product ) {
		$regular = (float) $product->get_regular_price();
		$current = (float) $product->get_price( 'edit' );
		$base    = $regular > 0 ? $regular : $current;
		return $base > 0 ? $base : 0.0;
	}

	protected static function is_tier_applicable( $product, $tier_price ) {
		if ( $tier_price < 0 ) {
			return false;
		}

		if ( ! function_exists( 'wwbp_get_settings' ) ) {
			return true;
		}

		$wwbp_opts        = wwbp_get_settings();
		$apply_sale_price = is_array( $wwbp_opts ) && ! empty( $wwbp_opts['apply_sale_price'] );
		if ( ! $apply_sale_price && $product->is_on_sale() ) {
			$regular = (float) $product->get_regular_price();
			if ( $regular > 0 && $tier_price >= $regular ) {
				return false;
			}
		}

		return true;
	}

	protected static function calculate_percent_off( $base_price, $tier_price ) {
		if ( $base_price <= 0 || $tier_price >= $base_price ) {
			return 0;
		}

		$raw_percent = ( ( $base_price - $tier_price ) / $base_price ) * 100;
		// Prevent overstated promotions: claim only the guaranteed integer discount.
		$percent_off = (int) floor( $raw_percent + 0.000001 );
		return max( 0, min( 99, $percent_off ) );
	}

	protected static function get_auto_qty_rules_for_product( $product ) {
		$opts = WGF_Settings::get_settings();
		if ( ( $opts['enable_qty_promotions'] ?? 'no' ) !== 'yes' ) {
			return [];
		}

		$tiers = self::get_wwbp_tiers_for_product( $product );
		if ( empty( $tiers ) || ! is_array( $tiers ) ) {
			return [];
		}

		$base_price = self::get_base_price( $product );
		if ( $base_price <= 0 ) {
			return [];
		}

		$item_id = WGF_Renderer::get_item_id_value( $product );
		if ( $item_id === '' ) {
			return [];
		}

		$rules = [];
		foreach ( $tiers as $tier ) {
			$min_qty    = isset( $tier['min_qty'] ) ? intval( $tier['min_qty'] ) : 0;
			$tier_price = isset( $tier['price'] ) ? (float) $tier['price'] : -1;

			if ( $min_qty < 2 || ! self::is_tier_applicable( $product, $tier_price ) ) {
				continue;
			}

			$percent_off = self::calculate_percent_off( $base_price, $tier_price );
			if ( $percent_off < 1 ) {
				continue;
			}

			$promotion_id = self::sanitize_promotion_id(
				'wgf-p' . $product->get_id() . '-q' . $min_qty . '-d' . $percent_off
			);

			if ( $promotion_id === '' ) {
				continue;
			}

			$rules[] = [
				'promotion_id'   => $promotion_id,
				'item_id'        => $item_id,
				'buy_this_qty'   => $min_qty,
				'percent_off'    => $percent_off,
				'long_title'     => sprintf(
					/* translators: 1: minimum quantity, 2: percent off */
					__( 'From %1$d pcs: %2$d%% off', 'woo-gmc-feed' ),
					$min_qty,
					$percent_off
				),
				'description'    => sprintf(
					/* translators: 1: minimum quantity, 2: percent off */
					__( 'Automatic quantity discount from %1$d pcs (%2$d%%).', 'woo-gmc-feed' ),
					$min_qty,
					$percent_off
				),
			];
		}

		return $rules;
	}

	protected static function get_manual_promotion_ids( $product ) {
		$raw = get_post_meta( $product->get_id(), '_gmc_promotion_ids', true );
		if ( $raw === '' && $product->is_type( 'variation' ) ) {
			$raw = get_post_meta( $product->get_parent_id(), '_gmc_promotion_ids', true );
		}

		$ids = self::parse_csv_values( $raw );
		$out = [];
		foreach ( $ids as $id ) {
			$clean = self::sanitize_promotion_id( $id );
			if ( $clean !== '' ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function get_product_promotion_ids( $product ) {
		$manual = self::get_manual_promotion_ids( $product );
		$auto   = [];
		foreach ( self::get_auto_qty_rules_for_product( $product ) as $rule ) {
			$auto[] = $rule['promotion_id'];
		}
		return array_values( array_unique( array_merge( $manual, $auto ) ) );
	}

	public static function get_feed_url() {
		$opts = WGF_Settings::get_settings();
		return home_url( '/' . trim( $opts['promotions_feed_slug'], '/' ) . '/' );
	}

	protected static function collect_promotion_items( $products ) {
		$items = [];
		foreach ( $products as $product ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				continue;
			}
			$rules = self::get_auto_qty_rules_for_product( $product );
			if ( empty( $rules ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$items[] = $rule;
			}
		}
		return $items;
	}

	public static function render_xml( $promotions ) {
		$blog_name = get_bloginfo( 'name' );
		$home      = home_url( '/' );
		$opts      = WGF_Settings::get_settings();

		$destinations = self::parse_destinations( $opts['promotion_destinations'] ?? '' );
		$channel      = (string) ( $opts['promotion_redemption_channel'] ?? 'online' );
		if ( $channel === 'in_store,online' ) {
			$channel = 'in_store, online';
		}
		if ( ! in_array( $channel, [ 'online', 'in_store', 'in_store, online' ], true ) ) {
			$channel = 'online';
		}
		$effective_dates = self::get_effective_dates();

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
		$xml .= "<channel>\n";
		$xml .= '<title>' . esc_xml( $blog_name . ' – Google Promotions Feed' ) . "</title>\n";
		$xml .= '<link>' . esc_url( $home ) . "</link>\n";
		$xml .= '<description>' . esc_xml( 'Promotions feed generated by Woo GMC Feed' ) . "</description>\n";

		foreach ( $promotions as $promotion ) {
			$xml .= "<item>\n";
			$xml .= '<g:promotion_id>' . esc_xml( $promotion['promotion_id'] ) . "</g:promotion_id>\n";
			$xml .= '<g:product_applicability>specific_products</g:product_applicability>' . "\n";
			$xml .= '<g:offer_type>no_code</g:offer_type>' . "\n";
			$xml .= '<g:long_title>' . esc_xml( $promotion['long_title'] ) . "</g:long_title>\n";
			$xml .= '<g:description>' . esc_xml( $promotion['description'] ) . "</g:description>\n";
			$xml .= '<g:promotion_effective_dates>' . esc_xml( $effective_dates ) . "</g:promotion_effective_dates>\n";
			$xml .= '<g:redemption_channel>' . esc_xml( $channel ) . "</g:redemption_channel>\n";
			foreach ( $destinations as $dest ) {
				$xml .= '<g:promotion_destination>' . esc_xml( $dest ) . "</g:promotion_destination>\n";
			}
			$xml .= '<g:coupon_value_type>buy_m_get_percent_off</g:coupon_value_type>' . "\n";
			$xml .= '<g:buy_this_quantity>' . esc_xml( (string) intval( $promotion['buy_this_qty'] ) ) . "</g:buy_this_quantity>\n";
			$xml .= '<g:percent_off>' . esc_xml( (string) intval( $promotion['percent_off'] ) ) . "</g:percent_off>\n";
			$xml .= '<g:item_id>' . esc_xml( $promotion['item_id'] ) . "</g:item_id>\n";
			$xml .= "</item>\n";
		}

		$xml .= "</channel>\n</rss>";
		return $xml;
	}

	public static function warm_cache() {
		delete_transient( WGF_PROMO_TRANSIENT_KEY );
		$opts       = WGF_Settings::get_settings();
		$products   = WGF_Feed::get_feed_products();
		$promotions = self::collect_promotion_items( $products );
		$xml        = self::render_xml( $promotions );
		$cache_ttl  = max( 0, intval( $opts['cache_hours'] ?? 0 ) ) * HOUR_IN_SECONDS;
		if ( $cache_ttl > 0 ) {
			set_transient( WGF_PROMO_TRANSIENT_KEY, $xml, $cache_ttl );
		}
	}

	public static function output_xml() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_die( esc_html__( 'WooCommerce is required for this feed.', 'woo-gmc-feed' ) );
		}

		$opts = WGF_Settings::get_settings();
		$xml  = get_transient( WGF_PROMO_TRANSIENT_KEY );
		if ( false === $xml ) {
			$products   = WGF_Feed::get_feed_products();
			$promotions = self::collect_promotion_items( $products );
			$xml        = self::render_xml( $promotions );

			$cache_ttl = max( 0, intval( $opts['cache_hours'] ?? 0 ) ) * HOUR_IN_SECONDS;
			if ( $cache_ttl > 0 ) {
				set_transient( WGF_PROMO_TRANSIENT_KEY, $xml, $cache_ttl );
			}
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
