<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WGF_Metabox {

	public static function register() {
		add_meta_box(
			'wgf_gmc_overrides',
			__( 'GMC Feed Overrides', 'woo-gmc-feed' ),
			[ __CLASS__, 'render' ],
			'product',
			'side',
			'low'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( 'wgf_save_overrides', 'wgf_overrides_nonce' );
			$include = get_post_meta( $post->ID, '_gmc_include', true );
			$gpc     = get_post_meta( $post->ID, '_gmc_google_product_category', true );
			$promotion_ids = get_post_meta( $post->ID, '_gmc_promotion_ids', true );
			$auto_min_price = get_post_meta( $post->ID, '_gmc_auto_pricing_min_price', true );
			$labels  = [];
		for ( $i = 0; $i <= 4; $i++ ) {
			$labels[ $i ] = get_post_meta( $post->ID, '_gmc_label_' . $i, true );
		}
		?>
		<p><label><input type="checkbox" name="wgf_include" value="yes" <?php checked( $include !== 'no' ); ?>> <?php esc_html_e( 'Include in GMC feed', 'woo-gmc-feed' ); ?></label></p>

		<p><label for="wgf_gpc"><?php esc_html_e( 'Google product category ID (override)', 'woo-gmc-feed' ); ?></label><br/>
		<input type="text" id="wgf_gpc" name="wgf_gpc" class="widefat" value="<?php echo esc_attr( $gpc ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to use category map / default', 'woo-gmc-feed' ); ?>"></p>

			<p><label for="wgf_promotion_ids"><?php esc_html_e( 'Promotion IDs (optional)', 'woo-gmc-feed' ); ?></label><br/>
			<input type="text" id="wgf_promotion_ids" name="wgf_promotion_ids" class="widefat" value="<?php echo esc_attr( $promotion_ids ); ?>" placeholder="<?php esc_attr_e( 'Comma-separated, e.g. summer_sale,club_offer', 'woo-gmc-feed' ); ?>"></p>
			<p class="description"><?php esc_html_e( 'These IDs are added as g:promotion_id in the product feed. Automatic quantity promotions are added separately.', 'woo-gmc-feed' ); ?></p>

			<p><label for="wgf_auto_pricing_min_price"><?php esc_html_e( 'Manual auto pricing min price (optional)', 'woo-gmc-feed' ); ?></label><br/>
			<input type="text" id="wgf_auto_pricing_min_price" name="wgf_auto_pricing_min_price" class="widefat" value="<?php echo esc_attr( $auto_min_price ); ?>" placeholder="<?php esc_attr_e( 'Example: 12.90', 'woo-gmc-feed' ); ?>"></p>
			<p class="description"><?php esc_html_e( 'If empty, plugin applies automatic fallback (default: 5% below active price, while respecting COGS rules).', 'woo-gmc-feed' ); ?></p>

		<details>
			<summary><?php esc_html_e( 'Custom labels (0–4)', 'woo-gmc-feed' ); ?></summary>
			<?php for ( $i = 0; $i <= 4; $i++ ) : ?>
				<p><label for="wgf_label_<?php echo esc_attr( $i ); ?>">custom_label_<?php echo esc_html( $i ); ?></label><br/>
				<input type="text" id="wgf_label_<?php echo esc_attr( $i ); ?>" name="wgf_label_<?php echo esc_attr( $i ); ?>" class="widefat" value="<?php echo esc_attr( $labels[ $i ] ); ?>"></p>
			<?php endfor; ?>
		</details>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['wgf_overrides_nonce'] ) || ! wp_verify_nonce( $_POST['wgf_overrides_nonce'], 'wgf_save_overrides' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		$include = isset( $_POST['wgf_include'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_gmc_include', $include );

		$gpc = isset( $_POST['wgf_gpc'] ) ? sanitize_text_field( wp_unslash( $_POST['wgf_gpc'] ) ) : '';
		if ( $gpc === '' ) {
			delete_post_meta( $post_id, '_gmc_google_product_category' );
		} else {
			update_post_meta( $post_id, '_gmc_google_product_category', $gpc );
		}

			$promotion_ids = isset( $_POST['wgf_promotion_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['wgf_promotion_ids'] ) ) : '';
			if ( $promotion_ids === '' ) {
				delete_post_meta( $post_id, '_gmc_promotion_ids' );
			} else {
				update_post_meta( $post_id, '_gmc_promotion_ids', $promotion_ids );
			}

			$auto_min_price = isset( $_POST['wgf_auto_pricing_min_price'] ) ? wc_format_decimal( wp_unslash( $_POST['wgf_auto_pricing_min_price'] ), wc_get_price_decimals() ) : '';
			if ( $auto_min_price === '' || (float) $auto_min_price <= 0 ) {
				delete_post_meta( $post_id, '_gmc_auto_pricing_min_price' );
			} else {
				update_post_meta( $post_id, '_gmc_auto_pricing_min_price', $auto_min_price );
			}

		for ( $i = 0; $i <= 4; $i++ ) {
			$key = 'wgf_label_' . $i;
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( $val === '' ) {
				delete_post_meta( $post_id, '_gmc_label_' . $i );
			} else {
				update_post_meta( $post_id, '_gmc_label_' . $i, $val );
			}
		}

			// Invalidate cache on save.
			WGF_Feed::flush_cache();
			delete_transient( WGF_PROMO_TRANSIENT_KEY );
	}
}
