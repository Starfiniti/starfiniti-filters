<?php
/**
 * Builds a small, deterministic demo catalog for local development in WordPress Playground.
 *
 * 24 products across 4 categories (one with two children), 3 brands, 3 colours and 2 tags,
 * with mixed prices, sale prices and stock so every planned filter has something to filter.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_rewrite;

$wp_rewrite->set_permalink_structure( '/%postname%/' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_task_list_hidden', 'yes', false );
update_option(
	'woocommerce_onboarding_profile',
	array(
		'completed' => true,
		'skipped'   => true,
	),
	false
);
delete_transient( '_wc_activation_redirect' );

WC_Install::create_pages();

if ( 0 === wc_attribute_taxonomy_id_by_name( 'color' ) ) {
	wc_create_attribute(
		array(
			'name'         => 'Color',
			'slug'         => 'color',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		)
	);
}

if ( ! taxonomy_exists( 'pa_color' ) ) {
	register_taxonomy( 'pa_color', array( 'product' ), array( 'hierarchical' => false ) );
}

/**
 * Returns the term ID for a name, creating the term when it is missing.
 */
$sffilters_term = static function ( string $name, string $taxonomy, int $parent = 0 ): int {
	$existing = get_term_by( 'name', $name, $taxonomy );

	if ( $existing instanceof WP_Term ) {
		return (int) $existing->term_id;
	}

	$created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( esc_html( $created->get_error_message() ) );
	}

	return (int) $created['term_id'];
};

$audio      = $sffilters_term( 'Audio', 'product_cat' );
$categories = array(
	$sffilters_term( 'Headphones', 'product_cat', $audio ),
	$sffilters_term( 'Speakers', 'product_cat', $audio ),
	$sffilters_term( 'Home', 'product_cat' ),
	$sffilters_term( 'Wearables', 'product_cat' ),
);
$brands     = array(
	$sffilters_term( 'Anker', 'product_brand' ),
	$sffilters_term( 'Xiaomi', 'product_brand' ),
	$sffilters_term( 'Dyson', 'product_brand' ),
);
$colors     = array(
	$sffilters_term( 'Black', 'pa_color' ),
	$sffilters_term( 'White', 'pa_color' ),
	$sffilters_term( 'Blue', 'pa_color' ),
);
$tags       = array(
	$sffilters_term( 'New', 'product_tag' ),
	$sffilters_term( 'Gift', 'product_tag' ),
);

$category_names = array( 'Headphones', 'Speaker', 'Home', 'Wearable' );
$brand_names    = array( 'Anker', 'Xiaomi', 'Dyson' );
$color_names    = array( 'Black', 'White', 'Blue' );
$color_id       = wc_attribute_taxonomy_id_by_name( 'color' );

for ( $i = 1; $i <= 24; $i++ ) {
	$sku = sprintf( 'sffilters-demo-%02d', $i );

	if ( 0 !== wc_get_product_id_by_sku( $sku ) ) {
		continue;
	}

	$category = ( $i - 1 ) % 4;
	$brand    = ( $i - 1 ) % 3;
	$color    = intdiv( $i - 1, 2 ) % 3;
	$price    = 15 + ( $i * 12 );

	$attribute = new WC_Product_Attribute();
	$attribute->set_id( $color_id );
	$attribute->set_name( 'pa_color' );
	$attribute->set_options( array( $colors[ $color ] ) );
	$attribute->set_visible( true );

	$product = new WC_Product_Simple();
	$product->set_name( sprintf( '%s %s %s %02d', $brand_names[ $brand ], $color_names[ $color ], $category_names[ $category ], $i ) );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) $price );

	if ( 0 === $i % 4 ) {
		$product->set_sale_price( (string) ( $price - 10 ) );
	}

	$product->set_stock_status( 0 === $i % 5 ? 'outofstock' : 'instock' );
	$product->set_category_ids( array( $categories[ $category ] ) );
	$product->set_tag_ids( 0 === $i % 3 ? array( $tags[ $i % 2 ] ) : array() );
	$product->set_attributes( array( $attribute ) );
	$product->set_menu_order( $i );

	$product_id = $product->save();

	wp_set_object_terms( $product_id, array( $brands[ $brand ] ), 'product_brand' );
}

// Write the rewrite rules now so the first request to /shop/ resolves, and let
// WooCommerce rebuild them once more on the next request as a backstop.
flush_rewrite_rules( false );
update_option( 'woocommerce_queue_flush_rewrite_rules', 'yes' );
