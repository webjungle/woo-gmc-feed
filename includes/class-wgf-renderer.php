<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Renderer {
	protected static $last_translation_complete = true;

	public static function last_translation_complete() {
		return self::$last_translation_complete;
	}

	protected static function get_brand( $product ) {
		$opts = WGF_Settings::get_settings();
		$tax  = $opts['brand_taxonomy'];
		$terms = $tax ? get_the_terms( $product->get_id(), $tax ) : false;
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$first = array_shift( $terms );
			return $first ? $first->name : '';
		}
		// Fallback: store name
		return get_bloginfo( 'name' );
	}

	protected static function get_meta_first( $post_id, $csv_keys ) {
		$keys = array_filter( array_map( 'trim', explode( ',', (string) $csv_keys ) ) );
		foreach ( $keys as $k ) {
			$v = get_post_meta( $post_id, $k, true );
			if ( $v !== '' && $v !== null ) {
				return $v;
			}
		}
		return '';
	}

	protected static function get_meta_first_for_product( $product, $csv_keys ) {
		$value = self::get_meta_first( $product->get_id(), $csv_keys );
		if ( $value !== '' && $value !== null ) {
			return $value;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				return self::get_meta_first( $parent_id, $csv_keys );
			}
		}
		return '';
	}

	protected static function get_gtin( $product ) {
		$opts = WGF_Settings::get_settings();
		$keys = array_filter( array_map( 'trim', explode( ',', (string) $opts['gtin_meta_keys'] ) ) );
		$post_ids = array( $product->get_id() );

		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$post_ids[] = $product->get_parent_id();
		}

		// A stale or malformed primary field must not hide a valid GTIN stored in
		// another approved product-data field.
		foreach ( $post_ids as $post_id ) {
			foreach ( $keys as $key ) {
				$gtin = preg_replace( '/\D+/', '', (string) get_post_meta( $post_id, $key, true ) );
				if ( '' !== $gtin && self::is_valid_gtin( $gtin ) ) {
					return $gtin;
				}
			}
		}

		return '';
	}

	protected static function is_valid_gtin( $gtin ) {
		$gtin = (string) $gtin;
		$len  = strlen( $gtin );
		if ( ! in_array( $len, [ 8, 12, 13, 14 ], true ) ) {
			return false;
		}
		if ( ! preg_match( '/^\d+$/', $gtin ) ) {
			return false;
		}

		$body       = substr( $gtin, 0, -1 );
		$check_digit = (int) substr( $gtin, -1 );
		$sum        = 0;
		$weight     = 3;

		for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
			$sum += (int) $body[ $i ] * $weight;
			$weight = ( 3 === $weight ) ? 1 : 3;
		}

		$calc = ( 10 - ( $sum % 10 ) ) % 10;
		return $calc === $check_digit;
	}

	protected static function get_mpn( $product ) {
		$opts = WGF_Settings::get_settings();
		// A shop SKU is not necessarily a manufacturer part number. Only export an
		// MPN when it is explicitly stored as such, otherwise rely on the GTIN.
		return self::get_meta_first_for_product( $product, $opts['mpn_meta_keys'] );
	}

	public static function is_merchant_ready( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$opts = WGF_Settings::get_settings();
		$pair = self::get_product_title_and_description( $product, ( $opts['use_short_description'] ?? 'yes' ) === 'yes' );

		if ( '' === $pair[0] || '' === $pair[1] || empty( self::get_images( $product ) ) ) {
			return false;
		}

		if ( (float) $product->get_price() <= 0 || '' === self::get_brand( $product ) ) {
			return false;
		}

		return '' !== self::get_gtin( $product ) || '' !== self::get_mpn( $product );
	}

	protected static function get_google_category_from_map( $product ) {
		$opts = WGF_Settings::get_settings();
		$map  = WGF_Settings::parse_category_map( $opts['category_map'] );
		if ( empty( $map ) ) { return ''; }

		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) { return ''; }

		// Products often have a brand and several functional categories. Check
		// every assigned category (and its ancestors), not just an arbitrary first
		// result, so mappings such as coffee machines are applied reliably.
		foreach ( $terms as $assigned_term ) {
			$term = $assigned_term;
			while ( $term && ! is_wp_error( $term ) ) {
				$key_slug = $term->slug;
				$key_id   = (string) $term->term_id;
				if ( isset( $map[ $key_slug ] ) ) {
					return $map[ $key_slug ];
				}
				if ( isset( $map[ $key_id ] ) ) {
					return $map[ $key_id ];
				}
				$term = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
			}
		}
		return '';
	}

	protected static function get_google_category( $product ) {
		// 1) Per-product override meta
		$meta = get_post_meta( $product->get_id(), '_gmc_google_product_category', true );
		if ( $meta ) { return $meta; }
		// 2) Category map
		$mapped = self::get_google_category_from_map( $product );
		if ( $mapped ) { return $mapped; }
		// 3) Global default
		$opts = WGF_Settings::get_settings();
		return $opts['google_category'];
	}

	protected static function get_shipping_price_for_product( $product ) {
		$opts = WGF_Settings::get_settings();
		$threshold = (float) $opts['free_shipping_threshold'];
		$flat      = (float) $opts['shipping_price'];
		$price     = (float) $product->get_price();
		if ( $threshold > 0 && $price >= $threshold ) {
			return 0.0;
		}
		return max( 0.0, $flat );
	}

	protected static function get_images( $product ) {
		$ids = [];
		$main = $product->get_image_id();
		if ( $main ) { $ids[] = $main; }
		$gallery = $product->get_gallery_image_ids();
		if ( ! empty( $gallery ) ) {
			$ids = array_merge( $ids, $gallery );
		}
		$urls = array_values( array_unique( array_filter( array_map( 'wp_get_attachment_url', $ids ) ) ) );
		return $urls;
	}

	protected static function get_availability( $product ) {
		if ( $product->is_in_stock() ) {
			return 'in stock';
		}
		if ( $product->is_on_backorder() ) {
			return 'backorder';
		}
		return 'out of stock';
	}

	protected static function get_item_id( $product ) {
		$sku = $product->get_sku();
		if ( $sku ) { return $sku; }
		if ( $product->is_type( 'variation' ) ) {
			return 'var-' . $product->get_parent_id() . '-' . $product->get_id();
		}
		return (string) $product->get_id();
	}

	public static function get_item_id_value( $product ) {
		return self::get_item_id( $product );
	}

	protected static function get_item_group_id( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			return (string) $product->get_parent_id();
		}
		if ( $product->is_type( 'variable' ) ) {
			return (string) $product->get_id();
		}
		return '';
	}

	protected static function get_effective_sale_date( $product ) {
		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();
		if ( ! $from && ! $to ) { return ''; }

		$from_s = $from ? $from->date_i18n( 'c' ) : gmdate( 'c', time() - DAY_IN_SECONDS );
		$to_s   = $to ? $to->date_i18n( 'c' ) : gmdate( 'c', time() + 180 * DAY_IN_SECONDS );
		return $from_s . '/' . $to_s;
	}

	protected static function clean_text( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = trim( $text );
		return $text;
	}

	protected static function get_product_title_and_description( $product, $use_short_description ) {
		$title = self::clean_text( $product->get_name() );
		$desc  = $use_short_description
			? self::clean_text( $product->get_short_description() ?: $product->get_description() )
			: self::clean_text( $product->get_description() ?: $product->get_short_description() );

		return [ $title, $desc ];
	}

	protected static function apply_translation_map( $text, $translation_map ) {
		if ( ! is_array( $translation_map ) ) {
			return $text;
		}
		if ( isset( $translation_map[ $text ] ) && $translation_map[ $text ] !== '' ) {
			return (string) $translation_map[ $text ];
		}
		return $text;
	}

	protected static function localize_permalink( $url, $language ) {
		if ( ! class_exists( 'WGF_Translator' ) ) {
			return $url;
		}

		$language = WGF_Translator::normalize_language( $language );
		if ( $language === '' || ! in_array( $language, WGF_Translator::get_supported_languages(), true ) ) {
			return $url;
		}

		// Keep canonical URLs for source language feed entries.
		if ( $language === WGF_Translator::get_source_language() ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parsed ) || ! is_array( $home ) ) {
			return $url;
		}
		if ( empty( $parsed['host'] ) || empty( $home['host'] ) || strtolower( (string) $parsed['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return $url;
		}

		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
		$has_trailing_slash = substr( $path, -1 ) === '/';
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		$base_segments = array_values( array_filter( explode( '/', trim( (string) ( $home['path'] ?? '' ), '/' ) ), 'strlen' ) );

		if ( ! empty( $base_segments ) && array_slice( $segments, 0, count( $base_segments ) ) === $base_segments ) {
			$segments = array_slice( $segments, count( $base_segments ) );
		}

		if ( ! empty( $segments ) ) {
			$first_language = WGF_Translator::normalize_language( $segments[0] );
			if ( $first_language !== '' ) {
				$segments[0] = $language;
			} else {
				array_unshift( $segments, $language );
			}
		} else {
			$segments[] = $language;
		}

		$new_segments = array_merge( $base_segments, $segments );
		$new_path = '/' . implode( '/', $new_segments );
		if ( $has_trailing_slash ) {
			$new_path = rtrim( $new_path, '/' ) . '/';
		}

		$scheme = isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : ( isset( $home['scheme'] ) ? (string) $home['scheme'] : 'https' );
		$host   = (string) $parsed['host'];
		$port   = isset( $parsed['port'] ) ? ':' . intval( $parsed['port'] ) : '';

		$localized = $scheme . '://' . $host . $port . $new_path;
		if ( isset( $parsed['query'] ) && $parsed['query'] !== '' ) {
			$localized .= '?' . $parsed['query'];
		}
		if ( isset( $parsed['fragment'] ) && $parsed['fragment'] !== '' ) {
			$localized .= '#' . $parsed['fragment'];
		}

		return $localized;
	}

	protected static function normalize_price_from_meta( $raw ) {
		if ( $raw === '' || $raw === null ) {
			return '';
		}
		$value = trim( (string) $raw );
		if ( $value === '' ) {
			return '';
		}
		$value = str_replace( ',', '.', $value );
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		if ( $value === '' || ! is_numeric( $value ) ) {
			return '';
		}
		$float = (float) $value;
		if ( $float <= 0 ) {
			return '';
		}
		return $float;
	}

	protected static function get_cost_of_goods_sold( $product ) {
		$opts     = WGF_Settings::get_settings();
		$meta_raw = self::get_meta_first_for_product( $product, $opts['purchase_price_meta_keys'] ?? '' );
		return self::normalize_price_from_meta( $meta_raw );
	}

	protected static function get_manual_auto_pricing_min_price( $product ) {
		$opts     = WGF_Settings::get_settings();
		$meta_raw = self::get_meta_first_for_product( $product, $opts['auto_pricing_min_price_meta_keys'] ?? '' );
		return self::normalize_price_from_meta( $meta_raw );
	}

	protected static function is_valid_auto_pricing_min_price( $candidate, $upper_bound, $cogs ) {
		if ( $candidate <= 0 ) {
			return false;
		}
		if ( $candidate > $upper_bound ) {
			return false;
		}
		if ( $cogs > 0 && $candidate < $cogs ) {
			return false;
		}
		return true;
	}

	protected static function get_auto_pricing_min_price( $current_price, $cogs, $manual_price = '' ) {
		$opts    = WGF_Settings::get_settings();
		$current = (float) $current_price;
		$cogs    = (float) $cogs;
		$manual  = (float) $manual_price;
		if ( $current <= 0 ) {
			return '';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
		$factor   = pow( 10, $decimals );
		$floor_price = static function( $value ) use ( $factor ) {
			return floor( (float) $value * $factor ) / $factor;
		};

		$upper_bound = $floor_price( $current * 0.95 );
		if ( $cogs > $upper_bound ) {
			return '';
		}

		if ( $manual > 0 ) {
			$manual = $floor_price( $manual );
			if ( self::is_valid_auto_pricing_min_price( $manual, $upper_bound, $cogs ) ) {
				return $manual;
			}
		}

		$fallback_discount_percent = max( 0, min( 95, (float) ( $opts['auto_min_price_default_discount_percent'] ?? 5 ) ) );
		$fallback = $current * ( 1 - ( $fallback_discount_percent / 100 ) );
		if ( $cogs > 0 && $fallback < $cogs ) {
			$fallback = $cogs;
		}
		$fallback = $floor_price( $fallback );

		if ( ! self::is_valid_auto_pricing_min_price( $fallback, $upper_bound, $cogs ) ) {
			return '';
		}

		return $fallback;
	}

	public static function render_xml( $products, $context = [] ) {
		$blog_name = get_bloginfo( 'name' );
		$home      = home_url( '/' );
		$opts      = WGF_Settings::get_settings();
		$curr      = ( $opts['currency'] ?: get_woocommerce_currency() );
		$feed_language = '';
		if ( class_exists( 'WGF_Translator' ) ) {
			$feed_language = WGF_Translator::normalize_language( $context['language'] ?? '' );
		}
		$channel_link = self::localize_permalink( $home, $feed_language );
		$use_short_description = ( $opts['use_short_description'] ?? 'yes' ) === 'yes';
		$translation_map = [];
		self::$last_translation_complete = true;
		if ( class_exists( 'WGF_Translator' ) && WGF_Translator::is_enabled_for_language( $feed_language ) ) {
			$texts = [];
			foreach ( $products as $product ) {
				if ( $product->is_type( 'variable' ) ) {
					continue;
				}
				$pair = self::get_product_title_and_description( $product, $use_short_description );
				if ( $pair[0] !== '' ) {
					$texts[] = $pair[0];
				}
				if ( $pair[1] !== '' ) {
					$texts[] = $pair[1];
				}
			}
			if ( ! empty( $texts ) ) {
				$expected_translation_count = count( array_unique( $texts ) );
				$translation_map = WGF_Translator::translate_strings( $texts, $feed_language );
				self::$last_translation_complete = count( $translation_map ) >= $expected_translation_count;
			}
		}
		$item_context = [
			'language'              => $feed_language,
			'translation_map'       => $translation_map,
			'use_short_description' => $use_short_description,
		];

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
		$xml .= "<channel>\n";
		$xml .= '<title>' . esc_xml( $blog_name . ' – Google Merchant Feed' ) . "</title>\n";
		$xml .= '<link>' . esc_url( $channel_link ) . "</link>\n";
		$xml .= '<description>' . esc_xml( 'Product feed generated by Woo GMC Feed' ) . "</description>\n";

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) { continue; }
			$xml .= self::item_xml( $product, $curr, $item_context );
		}

		$xml .= "</channel>\n</rss>";
		return $xml;
	}

	protected static function item_xml( $product, $curr, $context = [] ) {
		$use_short_description = ! empty( $context['use_short_description'] );
		$pair = self::get_product_title_and_description( $product, $use_short_description );
		$title = $pair[0];
		$desc  = $pair[1];
		$translation_map = is_array( $context['translation_map'] ?? null ) ? $context['translation_map'] : [];
		$title = self::apply_translation_map( $title, $translation_map );
		$desc  = self::apply_translation_map( $desc, $translation_map );
		$link  = self::localize_permalink( $product->get_permalink(), $context['language'] ?? '' );
		$imgs  = self::get_images( $product );
		$brand = self::get_brand( $product );
		$gtin  = self::get_gtin( $product );
		$mpn   = self::get_mpn( $product );
		$availability = self::get_availability( $product );
		$condition    = 'new';
		$google_cat   = self::get_google_category( $product );

		$price        = $product->get_regular_price();
		$current_price= $product->get_price();
		$sale_price   = $product->get_sale_price();
		$effective    = self::get_effective_sale_date( $product );
		$regular_price_value = (float) $price;
		$current_price_value = (float) $current_price;
		$sale_price_value    = (float) $sale_price;
		$has_valid_sale = $sale_price
			&& $sale_price_value > 0
			&& $regular_price_value > 0
			&& $sale_price_value < $regular_price_value;
		$feed_price_value = $has_valid_sale ? $regular_price_value : $current_price_value;
		if ( $feed_price_value <= 0 && $regular_price_value > 0 ) {
			$feed_price_value = $regular_price_value;
		}

			$id = self::get_item_id( $product );
			$group_id = self::get_item_group_id( $product );
			$cogs     = self::get_cost_of_goods_sold( $product );
			$manual_auto_min_price = self::get_manual_auto_pricing_min_price( $product );
			$active_price = ( $sale_price && (float) $sale_price > 0 && (float) $sale_price < (float) $price ) ? (float) $sale_price : (float) $current_price;
			$auto_min_price = self::get_auto_pricing_min_price( $active_price, $cogs, $manual_auto_min_price );

		// Shipping block
		$opts = WGF_Settings::get_settings();
		$ship_country = $opts['shipping_country'];
		$ship_service = $opts['shipping_service'];
		$ship_price   = self::get_shipping_price_for_product( $product );

		// Variation attributes to expose
		$attrs_xml = '';
		if ( $product->is_type( 'variation' ) ) {
			$attributes = $product->get_attributes();
			foreach ( $attributes as $name => $value ) {
				$lc = strtolower( wc_attribute_label( $name ) );
				if ( strpos( $lc, 'size' ) !== false ) {
					$attrs_xml .= '<g:size>' . esc_xml( $value ) . '</g:size>' . "\n";
				} elseif ( strpos( $lc, 'color' ) !== false || strpos( $lc, 'farbe' ) !== false ) {
					$attrs_xml .= '<g:color>' . esc_xml( $value ) . '</g:color>' . "\n";
				}
			}
		}

		$xml  = "<item>\n";
		$xml .= '<g:id>' . esc_xml( $id ) . "</g:id>\n";
			$xml .= '<title>' . esc_xml( $title ) . "</title>\n";
			$xml .= '<description>' . esc_xml( $desc ) . "</description>\n";
			$xml .= '<link>' . esc_url( $link ) . "</link>\n";
			$xml .= '<g:canonical_link>' . esc_url( $link ) . "</g:canonical_link>\n";
			if ( ! empty( $imgs ) ) {
			$xml .= '<g:image_link>' . esc_url( $imgs[0] ) . "</g:image_link>\n";
			for ( $i = 1; $i < min( count( $imgs ), 10 ); $i++ ) {
				$xml .= '<g:additional_image_link>' . esc_url( $imgs[$i] ) . "</g:additional_image_link>\n";
			}
		}

		$xml .= '<g:availability>' . esc_xml( $availability ) . "</g:availability>\n";
		$xml .= '<g:condition>' . esc_xml( $condition ) . "</g:condition>\n";

		// Google tax attribute is only relevant for US tax setup.
		$base_loc = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : [ 'country' => '', 'state' => '' ];
		$country  = isset( $base_loc['country'] ) ? strtoupper( (string) $base_loc['country'] ) : '';
		$region   = isset( $base_loc['state'] ) ? (string) $base_loc['state'] : '';
		if ( 'US' === $country ) {
			$tax_class = $product->get_tax_class();
			$rates_arr = class_exists( 'WC_Tax' ) ? WC_Tax::get_rates( $tax_class ) : [];
			$rate_val  = 0.0;
			if ( ! empty( $rates_arr ) && is_array( $rates_arr ) ) {
				$first = reset( $rates_arr );
				if ( isset( $first['rate'] ) ) {
					$rate_val = (float) $first['rate'];
				}
			}
			if ( $rate_val > 0 ) {
				$xml .= "<g:tax>\n";
				$xml .= '<g:rate>' . esc_xml( rtrim( rtrim( number_format( $rate_val, 2, '.', '' ), '0' ), '.' ) ) . '</g:rate>' . "\n";
				$xml .= '<g:country>' . esc_xml( $country ) . '</g:country>' . "\n";
				if ( $region ) {
					$xml .= '<g:region>' . esc_xml( $region ) . '</g:region>' . "\n";
				}
				$xml .= '<g:tax_ship>no</g:tax_ship>' . "\n";
				$xml .= "</g:tax>\n";
			}
		}

				$xml .= '<g:price>' . esc_xml( number_format( $feed_price_value, wc_get_price_decimals(), '.', '' ) . ' ' . $curr ) . "</g:price>\n";
				if ( $cogs !== '' ) {
					$xml .= '<g:cost_of_goods_sold>' . esc_xml( number_format( (float) $cogs, wc_get_price_decimals(), '.', '' ) . ' ' . $curr ) . "</g:cost_of_goods_sold>\n";
				}
				if ( $auto_min_price !== '' ) {
					$xml .= '<g:auto_pricing_min_price>' . esc_xml( number_format( (float) $auto_min_price, wc_get_price_decimals(), '.', '' ) . ' ' . $curr ) . "</g:auto_pricing_min_price>\n";
				}
				if ( $has_valid_sale ) {
				$xml .= '<g:sale_price>' . esc_xml( number_format( $sale_price_value, wc_get_price_decimals(), '.', '' ) . ' ' . $curr ) . "</g:sale_price>\n";
			if ( $effective ) {
				$xml .= '<g:sale_price_effective_date>' . esc_xml( $effective ) . "</g:sale_price_effective_date>\n";
			}
		}

		// Shipping
		$shipping_service_value = ! empty( $ship_service ) ? $ship_service : 'Standard';
		$xml .= '<g:shipping>' . "\n";
		$xml .= '<g:country>' . esc_xml( $ship_country ) . '</g:country>' . "\n";
		$xml .= '<g:service>' . esc_xml( $shipping_service_value ) . '</g:service>' . "\n";
		$xml .= '<g:price>' . esc_xml( number_format( $ship_price, wc_get_price_decimals(), '.', '' ) . ' ' . $curr ) . '</g:price>' . "\n";
		$xml .= "</g:shipping>\n";

		if ( $brand ) {
			$xml .= '<g:brand>' . esc_xml( $brand ) . "</g:brand>\n";
		}
		if ( $gtin ) {
			$xml .= '<g:gtin>' . esc_xml( $gtin ) . "</g:gtin>\n";
		}
		if ( $mpn ) {
			$xml .= '<g:mpn>' . esc_xml( $mpn ) . "</g:mpn>\n";
		}

		if ( $group_id ) {
			$xml .= '<g:item_group_id>' . esc_xml( $group_id ) . "</g:item_group_id>\n";
		}

			if ( $google_cat ) {
				$xml .= '<g:google_product_category>' . esc_xml( $google_cat ) . "</g:google_product_category>\n";
			}

			if ( class_exists( 'WGF_Promotions' ) ) {
				$promotion_ids = WGF_Promotions::get_product_promotion_ids( $product );
				foreach ( $promotion_ids as $promotion_id ) {
					$xml .= '<g:promotion_id>' . esc_xml( $promotion_id ) . "</g:promotion_id>\n";
				}
			}

		// Custom labels from meta: _gmc_label_0..4
		for ( $i = 0; $i <= 4; $i++ ) {
			$lab = get_post_meta( $product->get_id(), '_gmc_label_' . $i, true );
			if ( $lab ) {
				$xml .= '<g:custom_label_' . $i . '>' . esc_xml( $lab ) . '</g:custom_label_' . $i . ">\n";
			}
		}

		$xml .= $attrs_xml;

		$xml .= "</item>\n";
		return $xml;
	}
}
