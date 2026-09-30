<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Settings {

	public static function get_defaults() {
		return [
			'feed_slug'              => 'gmc-feed-xml',
			'include_out_of_stock'   => 'no',
			'include_hidden'         => 'no',
			'only_bundle_tile'       => 'no', // requires meta key below
			'bundle_meta_key'        => '_bundle_tile_enabled',

			// Identity mapping
			'brand_taxonomy'         => 'pa_brand',
				'gtin_meta_keys'         => '_ean,_gtin,_wpm_gtin_code',
				'mpn_meta_keys'          => '_mpn,_manufacturer_part_number',
				'purchase_price_meta_keys' => '_purchase_price,_alg_wc_cog_cost',
				'auto_pricing_min_price_meta_keys' => '_gmc_auto_pricing_min_price,auto_pricing_min_price',
				'auto_min_price_default_discount_percent' => 5,

			// Google category
			'google_category'        => '1868', // Coffee
			'category_map'           => "", // newline: product_cat_slug_or_id: GPC_ID

			// Output
			'currency'               => '',
			'use_short_description'  => 'yes',
			'merchant_ready_only'    => 'no',
			'cache_hours'            => 6,
			'max_items'              => 0, // 0 = unlimited
			'variations_mode'        => 'each', // each|parent_only
			'enable_feed_translation'=> 'no',
			'translation_provider'   => 'none',
			'feed_source_language'   => 'de',
			'azure_translator_endpoint' => 'https://api.cognitive.microsofttranslator.com',
			'azure_translator_region'   => '',
			'azure_translator_key'      => '',
			'translation_cache_hours'   => 720,

			// Shipping
			'shipping_country'       => 'CH',
			'shipping_service'       => '',
			'shipping_price'         => '7.90',
			'free_shipping_threshold'=> '69.00',

			// Cron
			'cron_interval_minutes'  => 240, // 4h

			// Promotions feed
			'promotions_feed_slug'      => 'gmc-promotions-xml',
			'enable_qty_promotions'     => 'yes',
			'promotion_effective_days'  => 180,
			'promotion_destinations'    => 'Shopping_ads,Free_listings',
			'promotion_redemption_channel' => 'online',
		];
	}

	public static function get_settings() {
		$defaults = self::get_defaults();
		$current  = get_option( WGF_OPTION_KEY, [] );
		return wp_parse_args( $current, $defaults );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'GMC Feed', 'woo-gmc-feed' ),
			__( 'GMC Feed', 'woo-gmc-feed' ),
			'manage_woocommerce',
			'wgf-settings',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting( 'wgf_settings_group', WGF_OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
	}

	public static function sanitize( $input ) {
		$defaults = self::get_defaults();
		$out = [];
		$out['feed_slug']             = sanitize_title_with_dashes( $input['feed_slug'] ?? $defaults['feed_slug'] );
		$out['include_out_of_stock']  = ! empty( $input['include_out_of_stock'] ) ? 'yes' : 'no';
		$out['include_hidden']        = ! empty( $input['include_hidden'] ) ? 'yes' : 'no';
		$out['only_bundle_tile']      = ! empty( $input['only_bundle_tile'] ) ? 'yes' : 'no';
		$out['bundle_meta_key']       = sanitize_key( $input['bundle_meta_key'] ?? $defaults['bundle_meta_key'] );

		$out['brand_taxonomy']        = sanitize_text_field( $input['brand_taxonomy'] ?? $defaults['brand_taxonomy'] );
			$out['gtin_meta_keys']        = sanitize_text_field( $input['gtin_meta_keys'] ?? $defaults['gtin_meta_keys'] );
			$out['mpn_meta_keys']         = sanitize_text_field( $input['mpn_meta_keys'] ?? $defaults['mpn_meta_keys'] );
			$out['purchase_price_meta_keys'] = sanitize_text_field( $input['purchase_price_meta_keys'] ?? $defaults['purchase_price_meta_keys'] );
			$out['auto_pricing_min_price_meta_keys'] = sanitize_text_field( $input['auto_pricing_min_price_meta_keys'] ?? $defaults['auto_pricing_min_price_meta_keys'] );
			$out['auto_min_price_default_discount_percent'] = max( 0, min( 95, (float) ( $input['auto_min_price_default_discount_percent'] ?? $defaults['auto_min_price_default_discount_percent'] ) ) );

		$out['google_category']       = sanitize_text_field( $input['google_category'] ?? $defaults['google_category'] );
		$out['category_map']          = trim( (string) ( $input['category_map'] ?? "" ) );

		$out['currency']              = sanitize_text_field( $input['currency'] ?? '' );
		$out['use_short_description'] = ! empty( $input['use_short_description'] ) ? 'yes' : 'no';
		$out['merchant_ready_only']   = ! empty( $input['merchant_ready_only'] ) ? 'yes' : 'no';
		$out['cache_hours']           = max( 0, intval( $input['cache_hours'] ?? $defaults['cache_hours'] ) );
		$out['max_items']             = max( 0, intval( $input['max_items'] ?? 0 ) );
		$out['variations_mode']       = in_array( $input['variations_mode'] ?? 'each', [ 'each', 'parent_only' ], true ) ? ( $input['variations_mode'] ?? 'each' ) : 'each';
		$out['enable_feed_translation'] = ! empty( $input['enable_feed_translation'] ) ? 'yes' : 'no';
		$out['translation_provider']  = in_array( $input['translation_provider'] ?? 'none', [ 'none', 'azure' ], true ) ? ( $input['translation_provider'] ?? 'none' ) : 'none';
		$out['feed_source_language']  = WGF_Translator::normalize_language( $input['feed_source_language'] ?? 'de' );
		if ( ! in_array( $out['feed_source_language'], WGF_Translator::get_supported_languages(), true ) ) {
			$out['feed_source_language'] = 'de';
		}
		$out['azure_translator_endpoint'] = esc_url_raw( trim( (string) ( $input['azure_translator_endpoint'] ?? $defaults['azure_translator_endpoint'] ) ) );
		$out['azure_translator_region']   = sanitize_text_field( $input['azure_translator_region'] ?? '' );
		$out['azure_translator_key']      = sanitize_text_field( $input['azure_translator_key'] ?? '' );
		$out['translation_cache_hours']   = max( 1, min( 2160, intval( $input['translation_cache_hours'] ?? $defaults['translation_cache_hours'] ) ) );

		$out['shipping_country']      = strtoupper( sanitize_text_field( $input['shipping_country'] ?? 'CH' ) );
		$out['shipping_service']      = sanitize_text_field( $input['shipping_service'] ?? '' );
		$out['shipping_price']        = wc_format_decimal( $input['shipping_price'] ?? '0', 2 );
		$out['free_shipping_threshold']= wc_format_decimal( $input['free_shipping_threshold'] ?? '0', 2 );

		$out['cron_interval_minutes'] = max( 5, intval( $input['cron_interval_minutes'] ?? 240 ) );

		$out['promotions_feed_slug']  = sanitize_title_with_dashes( $input['promotions_feed_slug'] ?? $defaults['promotions_feed_slug'] );
		$out['enable_qty_promotions'] = ! empty( $input['enable_qty_promotions'] ) ? 'yes' : 'no';
		$out['promotion_effective_days'] = max( 1, min( 183, intval( $input['promotion_effective_days'] ?? 180 ) ) );
		$out['promotion_destinations'] = sanitize_text_field( $input['promotion_destinations'] ?? $defaults['promotion_destinations'] );
		$channel = sanitize_text_field( $input['promotion_redemption_channel'] ?? $defaults['promotion_redemption_channel'] );
		if ( $channel === 'in_store,online' ) {
			$channel = 'in_store, online';
		}
		$out['promotion_redemption_channel'] = in_array( $channel, [ 'online', 'in_store', 'in_store, online' ], true ) ? $channel : 'online';
		return $out;
	}

	public static function render_page() {
		$opts     = self::get_settings();
		$feed_url       = WGF_Feed::get_feed_url();
		$promotions_url = WGF_Promotions::get_feed_url();
		$translated_feed_urls = [];
		$prefixed_feed_urls   = [];
		if ( class_exists( 'WGF_Translator' ) ) {
			$feed_slug = trim( (string) $opts['feed_slug'], '/' );
			foreach ( WGF_Translator::get_supported_languages() as $language ) {
				$translated_feed_urls[ $language ] = add_query_arg( 'wgf_lang', $language, $feed_url );
				$prefixed_feed_urls[ $language ]   = home_url( '/' . $language . '/' . $feed_slug . '/' );
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google Merchant Center Feed', 'woo-gmc-feed' ); ?></h1>

				<p><?php esc_html_e( 'Your feed is available here:', 'woo-gmc-feed' ); ?>
					<a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $feed_url ); ?></a>
				</p>
				<p><?php esc_html_e( 'Your promotions feed is available here:', 'woo-gmc-feed' ); ?>
					<a href="<?php echo esc_url( $promotions_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $promotions_url ); ?></a>
				</p>
				<?php if ( ! empty( $translated_feed_urls ) ) : ?>
					<p><strong><?php esc_html_e( 'Translated product feed URLs (recommended for Merchant Center):', 'woo-gmc-feed' ); ?></strong></p>
					<ul>
						<?php foreach ( $translated_feed_urls as $language => $url ) : ?>
							<li><code><?php echo esc_html( strtoupper( $language ) ); ?></code>:
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="description"><?php esc_html_e( 'Use the ?wgf_lang=.. links above in GMC data sources. They are stable even if front-end language proxies rewrite URL paths.', 'woo-gmc-feed' ); ?></p>
					<p><strong><?php esc_html_e( 'Language path variants (optional):', 'woo-gmc-feed' ); ?></strong></p>
					<ul>
						<?php foreach ( $prefixed_feed_urls as $language => $url ) : ?>
							<li><code><?php echo esc_html( strtoupper( $language ) ); ?></code>:
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wgf_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="feed_slug"><?php esc_html_e( 'Feed slug', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[feed_slug]" id="feed_slug" type="text" class="regular-text" value="<?php echo esc_attr( $opts['feed_slug'] ); ?>"> <code><?php echo esc_html( home_url( '/' ) ); ?><span style="opacity:.75"><?php echo esc_html( $opts['feed_slug'] ); ?></span></code></td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Inclusion filters', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include out of stock', 'woo-gmc-feed' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[include_out_of_stock]" <?php checked( $opts['include_out_of_stock'], 'yes' ); ?>> <?php esc_html_e( 'Yes', 'woo-gmc-feed' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include hidden (catalog visibility) products', 'woo-gmc-feed' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[include_hidden]" <?php checked( $opts['include_hidden'], 'yes' ); ?>> <?php esc_html_e( 'Yes', 'woo-gmc-feed' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Only products with "Bundle Tile" enabled', 'woo-gmc-feed' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[only_bundle_tile]" <?php checked( $opts['only_bundle_tile'], 'yes' ); ?>> <?php esc_html_e( 'Enable', 'woo-gmc-feed' ); ?></label>
							<p class="description"><?php esc_html_e( 'Requires a meta key set below to be truthy on the product to include it in the feed.', 'woo-gmc-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bundle_meta_key"><?php esc_html_e( 'Bundle tile meta key', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[bundle_meta_key]" id="bundle_meta_key" type="text" class="regular-text" value="<?php echo esc_attr( $opts['bundle_meta_key'] ); ?>"> <span class="description"><?php esc_html_e( 'Example: _bundle_tile_enabled (truthy to include)', 'woo-gmc-feed' ); ?></span></td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Identity & attributes', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="brand_taxonomy"><?php esc_html_e( 'Brand taxonomy', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[brand_taxonomy]" id="brand_taxonomy" type="text" class="regular-text" value="<?php echo esc_attr( $opts['brand_taxonomy'] ); ?>"> <span class="description"><?php esc_html_e( 'Taxonomy that contains the brand (e.g., pa_brand).', 'woo-gmc-feed' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="gtin_meta_keys"><?php esc_html_e( 'GTIN meta keys (comma-separated)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[gtin_meta_keys]" id="gtin_meta_keys" type="text" class="regular-text" value="<?php echo esc_attr( $opts['gtin_meta_keys'] ); ?>"></td>
					</tr>
						<tr>
							<th scope="row"><label for="mpn_meta_keys"><?php esc_html_e( 'MPN meta keys (comma-separated)', 'woo-gmc-feed' ); ?></label></th>
							<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[mpn_meta_keys]" id="mpn_meta_keys" type="text" class="regular-text" value="<?php echo esc_attr( $opts['mpn_meta_keys'] ); ?>"></td>
						</tr>
							<tr>
								<th scope="row"><label for="purchase_price_meta_keys"><?php esc_html_e( 'COGS meta keys (comma-separated)', 'woo-gmc-feed' ); ?></label></th>
								<td>
									<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[purchase_price_meta_keys]" id="purchase_price_meta_keys" type="text" class="regular-text" value="<?php echo esc_attr( $opts['purchase_price_meta_keys'] ); ?>">
									<p class="description"><?php esc_html_e( 'First non-empty key is used for g:cost_of_goods_sold (e.g. _purchase_price).', 'woo-gmc-feed' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="auto_pricing_min_price_meta_keys"><?php esc_html_e( 'Manual min-price meta keys (comma-separated)', 'woo-gmc-feed' ); ?></label></th>
								<td>
									<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[auto_pricing_min_price_meta_keys]" id="auto_pricing_min_price_meta_keys" type="text" class="regular-text" value="<?php echo esc_attr( $opts['auto_pricing_min_price_meta_keys'] ); ?>">
									<p class="description"><?php esc_html_e( 'First non-empty key is used for g:auto_pricing_min_price before fallback is applied.', 'woo-gmc-feed' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="auto_min_price_default_discount_percent"><?php esc_html_e( 'Fallback minimum price (% below current price)', 'woo-gmc-feed' ); ?></label></th>
								<td>
									<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[auto_min_price_default_discount_percent]" id="auto_min_price_default_discount_percent" type="number" min="0" max="95" step="0.1" class="small-text" value="<?php echo esc_attr( $opts['auto_min_price_default_discount_percent'] ); ?>"> %
									<p class="description"><?php esc_html_e( 'Used when no manual minimum price exists. Example: 5 = set min price to 95% of active price.', 'woo-gmc-feed' ); ?></p>
								</td>
							</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Google product category', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="google_category"><?php esc_html_e( 'Default GPC ID (fallback)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[google_category]" id="google_category" type="text" class="regular-text" value="<?php echo esc_attr( $opts['google_category'] ); ?>"> <span class="description"><?php esc_html_e( 'Used if no per-product override or category map match exists.', 'woo-gmc-feed' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="category_map"><?php esc_html_e( 'Woo category → GPC mapping', 'woo-gmc-feed' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[category_map]" id="category_map" class="large-text code" rows="6" placeholder="espresso-machines: 7424&#10;coffee: 1868"><?php echo esc_textarea( $opts['category_map'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Left side: Woo product_cat slug or term ID. Right side: Google product category ID. Ancestors are checked automatically.', 'woo-gmc-feed' ); ?></p>
						</td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Output', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="currency"><?php esc_html_e( 'Currency override (optional)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[currency]" id="currency" type="text" class="small-text" value="<?php echo esc_attr( $opts['currency'] ); ?>"> <span class="description"><?php esc_html_e( 'Leave blank to use store currency.', 'woo-gmc-feed' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Use short description instead of full', 'woo-gmc-feed' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[use_short_description]" <?php checked( $opts['use_short_description'], 'yes' ); ?>> <?php esc_html_e( 'Yes', 'woo-gmc-feed' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Export only Merchant-ready products', 'woo-gmc-feed' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[merchant_ready_only]" <?php checked( $opts['merchant_ready_only'], 'yes' ); ?>> <?php esc_html_e( 'Require title, description, image, price, brand and a real GTIN or MPN.', 'woo-gmc-feed' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="cache_hours"><?php esc_html_e( 'Cache duration (hours)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[cache_hours]" id="cache_hours" type="number" class="small-text" value="<?php echo esc_attr( $opts['cache_hours'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="max_items"><?php esc_html_e( 'Max items (0 = unlimited)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[max_items]" id="max_items" type="number" class="small-text" value="<?php echo esc_attr( $opts['max_items'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Variations handling', 'woo-gmc-feed' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[variations_mode]">
								<option value="each" <?php selected( $opts['variations_mode'], 'each' ); ?>><?php esc_html_e( 'Export each variation as an item', 'woo-gmc-feed' ); ?></option>
								<option value="parent_only" <?php selected( $opts['variations_mode'], 'parent_only' ); ?>><?php esc_html_e( 'Export only parent product', 'woo-gmc-feed' ); ?></option>
							</select>
						</td>
					</tr>
					<tr><th colspan="2"><h2><?php esc_html_e( 'Feed translation', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable translation for language-prefixed feed URLs', 'woo-gmc-feed' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[enable_feed_translation]" <?php checked( $opts['enable_feed_translation'], 'yes' ); ?>> <?php esc_html_e( 'Yes (translate title + description)', 'woo-gmc-feed' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="translation_provider"><?php esc_html_e( 'Translation provider', 'woo-gmc-feed' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[translation_provider]" id="translation_provider">
								<option value="none" <?php selected( $opts['translation_provider'], 'none' ); ?>>none</option>
								<option value="azure" <?php selected( $opts['translation_provider'], 'azure' ); ?>>Azure AI Translator</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="feed_source_language"><?php esc_html_e( 'Source language', 'woo-gmc-feed' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[feed_source_language]" id="feed_source_language">
								<option value="de" <?php selected( $opts['feed_source_language'], 'de' ); ?>>de</option>
								<option value="fr" <?php selected( $opts['feed_source_language'], 'fr' ); ?>>fr</option>
								<option value="it" <?php selected( $opts['feed_source_language'], 'it' ); ?>>it</option>
								<option value="en" <?php selected( $opts['feed_source_language'], 'en' ); ?>>en</option>
							</select>
							<p class="description"><?php esc_html_e( 'Language of WooCommerce product data before translation.', 'woo-gmc-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="azure_translator_endpoint"><?php esc_html_e( 'Azure endpoint', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[azure_translator_endpoint]" id="azure_translator_endpoint" type="text" class="regular-text" value="<?php echo esc_attr( $opts['azure_translator_endpoint'] ); ?>" placeholder="https://api.cognitive.microsofttranslator.com"></td>
					</tr>
					<tr>
						<th scope="row"><label for="azure_translator_region"><?php esc_html_e( 'Azure region', 'woo-gmc-feed' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[azure_translator_region]" id="azure_translator_region" type="text" class="regular-text" value="<?php echo esc_attr( $opts['azure_translator_region'] ); ?>" placeholder="switzerlandnorth">
							<p class="description"><?php esc_html_e( 'Required for most regional resources.', 'woo-gmc-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="azure_translator_key"><?php esc_html_e( 'Azure API key', 'woo-gmc-feed' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[azure_translator_key]" id="azure_translator_key" type="password" class="regular-text" value="<?php echo esc_attr( $opts['azure_translator_key'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Alternative: define WGF_AZURE_TRANSLATOR_KEY in wp-config.php.', 'woo-gmc-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="translation_cache_hours"><?php esc_html_e( 'Translation cache (hours)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[translation_cache_hours]" id="translation_cache_hours" type="number" min="1" max="2160" class="small-text" value="<?php echo esc_attr( $opts['translation_cache_hours'] ); ?>"></td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'Shipping (central)', 'woo-gmc-feed' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="shipping_country"><?php esc_html_e( 'Shipping country', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[shipping_country]" id="shipping_country" type="text" class="small-text" value="<?php echo esc_attr( $opts['shipping_country'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="shipping_service"><?php esc_html_e( 'Shipping service (optional)', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[shipping_service]" id="shipping_service" type="text" class="regular-text" value="<?php echo esc_attr( $opts['shipping_service'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="shipping_price"><?php esc_html_e( 'Flat shipping price below threshold', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[shipping_price]" id="shipping_price" type="text" class="small-text" value="<?php echo esc_attr( $opts['shipping_price'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="free_shipping_threshold"><?php esc_html_e( 'Free shipping threshold', 'woo-gmc-feed' ); ?></label></th>
						<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[free_shipping_threshold]" id="free_shipping_threshold" type="text" class="small-text" value="<?php echo esc_attr( $opts['free_shipping_threshold'] ); ?>"></td>
					</tr>

						<tr><th colspan="2"><h2><?php esc_html_e( 'Cron cache warmer', 'woo-gmc-feed' ); ?></h2></th></tr>
						<tr>
							<th scope="row"><label for="cron_interval_minutes"><?php esc_html_e( 'Interval (minutes)', 'woo-gmc-feed' ); ?></label></th>
							<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[cron_interval_minutes]" id="cron_interval_minutes" type="number" min="5" class="small-text" value="<?php echo esc_attr( $opts['cron_interval_minutes'] ); ?>"> <span class="description"><?php esc_html_e( 'A scheduled task will pre-build the feed cache.', 'woo-gmc-feed' ); ?></span></td>
						</tr>

						<tr><th colspan="2"><h2><?php esc_html_e( 'Promotions (quantity discounts)', 'woo-gmc-feed' ); ?></h2></th></tr>
						<tr>
							<th scope="row"><label for="promotions_feed_slug"><?php esc_html_e( 'Promotions feed slug', 'woo-gmc-feed' ); ?></label></th>
							<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[promotions_feed_slug]" id="promotions_feed_slug" type="text" class="regular-text" value="<?php echo esc_attr( $opts['promotions_feed_slug'] ); ?>"> <code><?php echo esc_html( home_url( '/' ) ); ?><span style="opacity:.75"><?php echo esc_html( $opts['promotions_feed_slug'] ); ?></span></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable automatic quantity promotions', 'woo-gmc-feed' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[enable_qty_promotions]" <?php checked( $opts['enable_qty_promotions'], 'yes' ); ?>> <?php esc_html_e( 'Generate promotion entries from WooWin Bulk Pricing tiers', 'woo-gmc-feed' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="promotion_effective_days"><?php esc_html_e( 'Promotion duration (days)', 'woo-gmc-feed' ); ?></label></th>
							<td><input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[promotion_effective_days]" id="promotion_effective_days" type="number" min="1" max="183" class="small-text" value="<?php echo esc_attr( $opts['promotion_effective_days'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="promotion_redemption_channel"><?php esc_html_e( 'Redemption channel', 'woo-gmc-feed' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[promotion_redemption_channel]" id="promotion_redemption_channel">
									<option value="online" <?php selected( $opts['promotion_redemption_channel'], 'online' ); ?>>online</option>
									<option value="in_store" <?php selected( $opts['promotion_redemption_channel'], 'in_store' ); ?>>in_store</option>
									<option value="in_store, online" <?php selected( $opts['promotion_redemption_channel'], 'in_store, online' ); ?>>in_store, online</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="promotion_destinations"><?php esc_html_e( 'Promotion destinations', 'woo-gmc-feed' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( WGF_OPTION_KEY ); ?>[promotion_destinations]" id="promotion_destinations" type="text" class="regular-text" value="<?php echo esc_attr( $opts['promotion_destinations'] ); ?>">
								<p class="description"><?php esc_html_e( 'Comma-separated: Shopping_ads, Free_listings, YouTube_affiliate, Local_inventory_ads', 'woo-gmc-feed' ); ?></p>
							</td>
						</tr>
					</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wgf_flush_feed' ); ?>
				<input type="hidden" name="action" value="wgf_flush_feed" />
				<?php submit_button( __( 'Flush feed cache now', 'woo-gmc-feed' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_flush_feed() {
		check_admin_referer( 'wgf_flush_feed' );
		WGF_Feed::flush_cache();
		delete_transient( WGF_PROMO_TRANSIENT_KEY );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wgf-settings' ) );
		exit;
	}

	public static function handle_save_settings() {
		// Legacy handler if needed; currently using options.php.
	}

	public static function maybe_reschedule_cron() {
		$opts = self::get_settings();
		$mins = max( 5, intval( $opts['cron_interval_minutes'] ?? 240 ) );
		$key  = 'wgf_every_' . $mins . '_minutes';
		// Unschedule old.
		$timestamp = wp_next_scheduled( WGF_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WGF_CRON_HOOK );
		}
		// Schedule new.
		if ( ! wp_next_scheduled( WGF_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, $key, WGF_CRON_HOOK );
		}
	}

	/**
	 * Parse category map textarea into [slug_or_id => gpc_id] array.
	 */
	public static function parse_category_map( $raw ) {
		$map = [];
		$lines = preg_split( "/\r\n|\n|\r/", (string) $raw );
		foreach ( $lines as $ln ) {
			$ln = trim( $ln );
			if ( $ln === '' ) { continue; }
			if ( strpos( $ln, ':' ) === false ) { continue; }
			list( $left, $right ) = array_map( 'trim', explode( ':', $ln, 2 ) );
			if ( $left === '' || $right === '' ) { continue; }
			$map[ $left ] = $right;
		}
		return $map;
	}
}
