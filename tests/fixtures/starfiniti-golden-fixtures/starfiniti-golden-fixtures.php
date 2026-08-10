<?php
/**
 * Plugin Name: Starfiniti Golden Fixtures
 * Description: Deterministic WooCommerce products and pages used only by the upstream golden-baseline harness.
 * Version: 0.2.0
 * License: GPL-3.0-only
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create one idempotent simple product.
 *
 * @param array<string, mixed> $definition Fixture definition.
 * @return int Product ID.
 */
function starfiniti_fixture_simple_product( array $definition ): int {
	$existing_id = wc_get_product_id_by_sku( $definition['sku'] );
	if ( $existing_id ) {
		return $existing_id;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $definition['name'] );
	$product->set_slug( $definition['slug'] );
	$product->set_sku( $definition['sku'] );
	$product->set_regular_price( $definition['price'] );
	$product->set_description( $definition['description'] );
	$product->set_short_description( $definition['short_description'] );
	$product->set_status( $definition['status'] ?? 'publish' );
	$product->set_catalog_visibility( $definition['visibility'] ?? 'visible' );
	$product->set_stock_status( $definition['stock_status'] ?? 'instock' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 'instock' === ( $definition['stock_status'] ?? 'instock' ) ? 25 : 0 );
	$product->set_category_ids( $definition['category_ids'] );
	$product_id = $product->save();

	if ( ! empty( $definition['password'] ) ) {
		wp_update_post(
			[
				'ID'            => $product_id,
				'post_password' => $definition['password'],
			]
		);
	}

	return $product_id;
}

/**
 * Seed deterministic data after WooCommerce is active.
 */
function starfiniti_seed_golden_fixtures(): void {
	if ( '2' === get_option( 'starfiniti_golden_fixtures_version' ) ) {
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		throw new RuntimeException( 'WooCommerce must be active before Starfiniti Golden Fixtures.' );
	}

	$category_ids = [];
	foreach ( [ 'Apparel', 'Accessories', 'Čaj & Kava' ] as $category_name ) {
		$existing = term_exists( $category_name, 'product_cat' );
		if ( ! $existing ) {
			$existing = wp_insert_term( $category_name, 'product_cat' );
		}
		if ( is_wp_error( $existing ) ) {
			throw new RuntimeException( $existing->get_error_message() );
		}
		$category_ids[ $category_name ] = (int) $existing['term_id'];
	}

	$definitions = [
		[
			'name'              => 'Blue Alpine Shirt',
			'slug'              => 'blue-alpine-shirt',
			'sku'               => 'EXACT-001',
			'price'             => '19.99',
			'description'       => 'A visible blue cotton shirt for exact-name and category queries.',
			'short_description' => 'Blue cotton shirt.',
			'category_ids'      => [ $category_ids['Apparel'] ],
		],
		[
			'name'              => 'Red Trail Shirt',
			'slug'              => 'red-trail-shirt',
			'sku'               => 'TRAIL-RED-002',
			'price'             => '24.50',
			'description'       => 'An out-of-stock red shirt used for stock visibility facets.',
			'short_description' => 'Out-of-stock red shirt.',
			'category_ids'      => [ $category_ids['Apparel'] ],
			'stock_status'      => 'outofstock',
		],
		[
			'name'              => 'Črna Kava 500 g',
			'slug'              => 'crna-kava-500g',
			'sku'               => 'KAVA-Č-500',
			'price'             => '12.30',
			'description'       => 'Diacritic fixture: črna kava, crème, café, Straße, smörgås.',
			'short_description' => 'Diacritic and Unicode fixture.',
			'category_ids'      => [ $category_ids['Čaj & Kava'] ],
		],
		[
			'name'              => 'Canvas Weekender Bag',
			'slug'              => 'canvas-weekender-bag',
			'sku'               => 'BAG-101',
			'price'             => '49.00',
			'description'       => 'Accessory fixture with common-token overlap.',
			'short_description' => 'Canvas travel accessory.',
			'category_ids'      => [ $category_ids['Accessories'] ],
		],
		[
			'name'              => 'Hidden Wholesale Belt',
			'slug'              => 'hidden-wholesale-belt',
			'sku'               => 'HIDDEN-404',
			'price'             => '8.00',
			'description'       => 'Must never appear in public catalog discovery.',
			'short_description' => 'Hidden visibility control fixture.',
			'category_ids'      => [ $category_ids['Accessories'] ],
			'visibility'        => 'hidden',
		],
		[
			'name'              => 'Password Protected Jacket',
			'slug'              => 'password-protected-jacket',
			'sku'               => 'SECRET-403',
			'price'             => '88.00',
			'description'       => 'Must not appear in unauthenticated search results.',
			'short_description' => 'Password visibility control fixture.',
			'category_ids'      => [ $category_ids['Apparel'] ],
			'password'          => 'golden-secret',
		],
		[
			'name'              => 'Private Operations Sample',
			'slug'              => 'private-operations-sample',
			'sku'               => 'PRIVATE-401',
			'price'             => '1.00',
			'description'       => 'Private status fixture.',
			'short_description' => 'Private visibility control fixture.',
			'category_ids'      => [ $category_ids['Accessories'] ],
			'status'            => 'private',
		],
	];

	foreach ( $definitions as $definition ) {
		starfiniti_fixture_simple_product( $definition );
	}

	$discovery_page = get_page_by_path( 'golden-discovery' );
	if ( ! $discovery_page ) {
		wp_insert_post(
			[
				'post_title'   => 'Golden Discovery',
				'post_name'    => 'golden-discovery',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[fibofilters all_screens="1"]<!-- /wp:shortcode --><!-- wp:shortcode -->[products limit="12" columns="3"]<!-- /wp:shortcode -->',
			]
		);
	}

	$search_page = get_page_by_path( 'golden-search' );
	if ( ! $search_page ) {
		wp_insert_post(
			[
				'post_title'   => 'Golden Search',
				'post_name'    => 'golden-search',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[fibosearch]<!-- /wp:shortcode --><!-- wp:shortcode -->[products limit="12" columns="3"]<!-- /wp:shortcode -->',
			]
		);
	}

	$starfiniti_page = get_page_by_path( 'starfiniti-search-qualification' );
	if ( ! $starfiniti_page ) {
		wp_insert_post(
			[
				'post_title'   => 'Starfiniti Search Qualification',
				'post_name'    => 'starfiniti-search-qualification',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[starfiniti_search]<!-- /wp:shortcode --><!-- wp:shortcode -->[starfiniti_discovery]<!-- /wp:shortcode -->',
			]
		);
	}

	update_option( 'starfiniti_golden_fixtures_version', '2', false );
	update_option( 'woocommerce_currency', 'EUR', false );
	update_option( 'woocommerce_price_decimal_sep', '.', false );

	$administrator = get_user_by( 'login', 'admin' );
	if ( $administrator instanceof WP_User ) {
		wp_set_password( 'password', $administrator->ID );
	}

	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'starfiniti_seed_golden_fixtures' );
