<?php
/**
 * Checks the plugin inside a real WordPress with WooCommerce and the demo catalog of playground/setup.php.
 *
 * `npm test` runs this in WordPress Playground. On any other install: `wp eval-file tests/checks.php`.
 * What a filter should return is worked out here from the products themselves, then compared with
 * what the plugin returns. The results also go to .cache/test-results.json, together with the totals
 * that tests/run.mjs expects on the shop pages.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$sffilters_results = array();

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$sffilters_results ): void {
	$sffilters_results[] = array(
		'name'   => $name,
		'pass'   => $pass,
		'detail' => $pass ? '' : ( is_string( $detail ) ? $detail : (string) wp_json_encode( $detail ) ),
	);
};

$saved_settings = get_option( 'sffilters_settings', null );

// A known set of filters, whatever the store had saved.
$saved = \Starfiniti\Filters\Settings::save(
	array(
		'pagination' => 'pages',
		'filters'    => array(
			array(
				'source' => 'product_cat',
				'slug'   => 'cat',
			),
			array(
				'source' => 'product_brand',
				'slug'   => 'brand',
			),
			array(
				'source' => 'pa_color',
				'slug'   => 'color',
			),
			array( 'source' => 'price' ),
			array( 'source' => 'stock' ),
			array( 'source' => 'sale' ),
		),
	)
);

$check( 'settings: a valid list of filters is saved', true === $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );

$filters  = \Starfiniti\Filters\Settings::filters();
$products = wc_get_products(
	array(
		'limit'  => -1,
		'status' => 'publish',
	)
);

$check( 'catalog: the demo catalog has 24 products', 24 === count( $products ), count( $products ) );

// --- What the products say.

$slugs = static function ( WC_Product $product, string $taxonomy ): array {
	$terms = wp_get_object_terms( $product->get_id(), $taxonomy, array( 'fields' => 'slugs' ) );

	return is_array( $terms ) ? $terms : array();
};

$in_category = static function ( WC_Product $product, string $slug ): bool {
	$term = get_term_by( 'slug', $slug, 'product_cat' );

	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$ids = array_merge( array( $term->term_id ), (array) get_term_children( $term->term_id, 'product_cat' ) );

	return array() !== array_intersect( $ids, $product->get_category_ids() );
};

$expect = static function ( callable $keep ) use ( $products ): array {
	$ids = array();

	foreach ( $products as $product ) {
		if ( $keep( $product ) ) {
			$ids[] = $product->get_id();
		}
	}

	sort( $ids );

	return $ids;
};

// --- What the plugin returns.

$base = array(
	'post_type'   => 'product',
	'post_status' => 'publish',
);

$state = static function ( array $request ) use ( $filters ): \Starfiniti\Filters\State {
	return \Starfiniti\Filters\State::from_request( $filters, $request );
};

$found = static function ( array $request ) use ( $filters, $state, $base ): array {
	$query = new WP_Query(
		array_merge(
			\Starfiniti\Filters\Query::apply( $base, $filters, $state( $request ) ),
			array(
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		)
	);

	$ids = array_map( 'intval', $query->posts );

	sort( $ids );

	return $ids;
};

$all  = $expect( '__return_true' );
$http = array();

$cases = array(
	'no filter returns every product'          => array(
		array(),
		'__return_true',
	),
	'category'                                 => array(
		array( 'sf_cat' => 'headphones' ),
		static fn( $p ) => $in_category( $p, 'headphones' ),
	),
	'parent category includes its children'    => array(
		array( 'sf_cat' => 'audio' ),
		static fn( $p ) => $in_category( $p, 'audio' ),
	),
	'two values of one filter widen the list'  => array(
		array( 'sf_brand' => 'anker,xiaomi' ),
		static fn( $p ) => array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) ),
	),
	'values given as a list'                   => array(
		array( 'sf_brand' => array( 'anker', 'xiaomi' ) ),
		static fn( $p ) => array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) ),
	),
	'two filters narrow the list'              => array(
		array(
			'sf_cat'   => 'audio',
			'sf_brand' => 'anker',
		),
		static fn( $p ) => $in_category( $p, 'audio' ) && in_array( 'anker', $slugs( $p, 'product_brand' ), true ),
	),
	'attribute'                                => array(
		array( 'sf_color' => 'black' ),
		static fn( $p ) => in_array( 'black', $slugs( $p, 'pa_color' ), true ),
	),
	'price range'                              => array(
		array( 'sf_price' => '50-200' ),
		static fn( $p ) => (float) $p->get_price() >= 50 && (float) $p->get_price() <= 200,
	),
	'price range written backwards'            => array(
		array( 'sf_price' => '200-50' ),
		static fn( $p ) => (float) $p->get_price() >= 50 && (float) $p->get_price() <= 200,
	),
	'price from'                               => array(
		array( 'sf_price' => '100-' ),
		static fn( $p ) => (float) $p->get_price() >= 100,
	),
	'price up to, with a decimal comma'        => array(
		array( 'sf_price' => '-100,5' ),
		static fn( $p ) => (float) $p->get_price() <= 100.5,
	),
	'in stock'                                 => array(
		array( 'sf_stock' => 'instock' ),
		static fn( $p ) => 'instock' === $p->get_stock_status(),
	),
	'on sale'                                  => array(
		array( 'sf_sale' => '1' ),
		static fn( $p ) => $p->is_on_sale(),
	),
	'every kind of filter at once'             => array(
		array(
			'sf_cat'   => 'audio',
			'sf_brand' => 'anker,xiaomi',
			'sf_price' => '50-250',
			'sf_stock' => 'instock',
		),
		static fn( $p ) => $in_category( $p, 'audio' )
			&& array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) )
			&& (float) $p->get_price() >= 50 && (float) $p->get_price() <= 250
			&& 'instock' === $p->get_stock_status(),
	),
	'a term that does not exist finds nothing' => array(
		array( 'sf_cat' => 'no-such-category' ),
		'__return_false',
	),
	'a stock value with SQL in it is ignored'  => array(
		array( 'sf_stock' => "instock' OR '1'='1" ),
		'__return_true',
	),
	'a price that is not a number is ignored'  => array(
		array( 'sf_price' => 'cheap' ),
		'__return_true',
	),
	'a sale value other than 1 is ignored'     => array(
		array( 'sf_sale' => 'yes' ),
		'__return_true',
	),
);

foreach ( $cases as $name => $case ) {
	$expected = $expect( $case[1] );
	$actual   = $found( $case[0] );

	$check(
		'query: ' . $name,
		$expected === $actual,
		array(
			'expected' => $expected,
			'found'    => $actual,
		)
	);

	// The same requests go to the shop page in tests/run.mjs.
	$http[] = array(
		'name'  => $name,
		'path'  => '/shop/',
		'query' => http_build_query( $case[0] ),
		'total' => count( $expected ),
	);
}

$check( 'query: the cases cover a real choice', count( $expect( $cases['every kind of filter at once'][1] ) ) > 0 && count( $expect( $cases['every kind of filter at once'][1] ) ) < count( $all ) );

$http[] = array(
	'name'  => 'a filter on a category page',
	'path'  => '/product-category/audio/',
	'query' => 'sf_brand=anker',
	'total' => count( $expect( static fn( $p ) => $in_category( $p, 'audio' ) && in_array( 'anker', $slugs( $p, 'product_brand' ), true ) ) ),
);

foreach ( array( 'price', 'price-desc', 'popularity', 'rating', 'date' ) as $orderby ) {
	$http[] = array(
		'name'  => 'sale and price filters sorted by ' . $orderby,
		'path'  => '/shop/',
		'query' => 'sf_sale=1&sf_price=50-&orderby=' . $orderby,
		'total' => count( $expect( static fn( $p ) => $p->is_on_sale() && (float) $p->get_price() >= 50 ) ),
	);
}

// --- Counts in the panel.

$facet = static function ( string $slug, array $request ) use ( $filters, $state, $base ): array {
	$current = $state( $request );
	$ids_sql = \Starfiniti\Filters\Query::ids_sql( \Starfiniti\Filters\Query::apply( $base, $filters, $current->without( $slug ) ) );

	return $filters[ $slug ]->facet( $ids_sql, $current->values( $slug ) );
};

$flatten = static function ( array $options ) use ( &$flatten ): array {
	$flat = array();

	foreach ( $options as $option ) {
		$flat[ $option['value'] ] = (int) $option['count'];
		$flat                    += $flatten( $option['children'] ?? array() );
	}

	return $flat;
};

$brands   = $flatten( $facet( 'brand', array( 'sf_cat' => 'audio' ) )['options'] ?? array() );
$expected = array();

foreach ( array( 'anker', 'dyson', 'xiaomi' ) as $brand ) {
	$expected[ $brand ] = count( $expect( static fn( $p ) => $in_category( $p, 'audio' ) && in_array( $brand, $slugs( $p, 'product_brand' ), true ) ) );
}

ksort( $brands );
$check(
	'counts: brands inside a chosen category',
	$expected === $brands,
	array(
		'expected' => $expected,
		'found'    => $brands,
	)
);

// A filter counts its own choices as if it were not in use, so picking one brand still shows the others.
$brands = $flatten( $facet( 'brand', array( 'sf_brand' => 'anker' ) )['options'] ?? array() );
$check( 'counts: the other brands stay countable once one is chosen', ( $brands['xiaomi'] ?? 0 ) === count( $expect( static fn( $p ) => in_array( 'xiaomi', $slugs( $p, 'product_brand' ), true ) ) ), $brands );

$categories = $flatten( $facet( 'cat', array( 'sf_stock' => 'instock' ) )['options'] ?? array() );
$expected   = array();

foreach ( array_keys( $categories ) as $category ) {
	$expected[ $category ] = count( $expect( static fn( $p ) => $in_category( $p, $category ) && 'instock' === $p->get_stock_status() ) );
}

$check(
	'counts: categories of products in stock, parents with their children',
	array() !== $categories && $expected === $categories && isset( $categories['audio'], $categories['headphones'] ),
	array(
		'expected' => $expected,
		'found'    => $categories,
	)
);

$prices = array_map( static fn( $id ) => (float) wc_get_product( $id )->get_price(), $expect( static fn( $p ) => in_array( 'anker', $slugs( $p, 'product_brand' ), true ) ) );
$range  = $facet( 'price', array( 'sf_brand' => 'anker' ) );
$check( 'counts: price range of a chosen brand', array() !== $prices && floor( min( $prices ) ) === (float) ( $range['min'] ?? -1 ) && ceil( max( $prices ) ) === (float) ( $range['max'] ?? -1 ), $range );

$stock = $flatten( $facet( 'stock', array( 'sf_sale' => '1' ) )['options'] ?? array() );
$check( 'counts: stock of products on sale', ( $stock['instock'] ?? 0 ) === count( $expect( static fn( $p ) => $p->is_on_sale() && 'instock' === $p->get_stock_status() ) ), $stock );

foreach ( array( 'wearables', 'home' ) as $category ) {
	$sale     = $facet( 'sale', array( 'sf_cat' => $category ) );
	$expected = count( $expect( static fn( $p ) => $p->is_on_sale() && $in_category( $p, $category ) ) );

	// A choice that would find nothing is not shown at all.
	$check( 'counts: products on sale in the category ' . $category, 0 === $expected ? array() === $sale : ( $sale['count'] ?? -1 ) === $expected, $sale );
}

// --- Settings.

$before = get_option( 'sffilters_settings' );

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'no_such_taxonomy' ) ) ) );
$check( 'settings: an unknown source is refused', is_wp_error( $result ) && 'sffilters_unknown_source' === $result->get_error_code() );

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'product_visibility' ) ) ) );
$check( 'settings: an internal taxonomy is refused', is_wp_error( $result ) );

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'price' ), array( 'source' => 'price' ) ) ) );
$check( 'settings: the same source twice is refused', is_wp_error( $result ) && 'sffilters_duplicate_filter' === $result->get_error_code() );

$result = \Starfiniti\Filters\Settings::save( array( 'pagination' => 'sideways' ) );
$check( 'settings: an unknown pagination mode is refused', is_wp_error( $result ) && 'sffilters_invalid_pagination' === $result->get_error_code() );

$check( 'settings: a refused save changes nothing', get_option( 'sffilters_settings' ) === $before );

\Starfiniti\Filters\Settings::save(
	array(
		'pagination' => 'infinite',
		'filters'    => array(
			array(
				'source'  => 'product_cat',
				'display' => 'links',
				'label'   => '<b>Shop by type</b>',
			),
			array(
				'source'  => 'price',
				'display' => 'links',
			),
		),
	)
);

$export = \Starfiniti\Filters\Settings::export();
$check( 'settings: pagination is saved', 'infinite' === $export['pagination'], $export['pagination'] );
$check( 'settings: only a taxonomy can be shown as links', 'links' === ( $export['filters'][0]['display'] ?? '' ) && '' === ( $export['filters'][1]['display'] ?? 'x' ), $export['filters'] );
$check( 'settings: a title is stored as plain text', 'Shop by type' === ( $export['filters'][0]['label'] ?? '' ), $export['filters'] );

$links = \Starfiniti\Filters\Settings::filters();
$first = reset( $links );
$check( 'settings: a filter shown as links reads nothing from the URL', false !== $first && array() === $first->parse( 'audio' ) );

\Starfiniti\Filters\Settings::save( array( 'filters' => array() ) );
$check( 'settings: an empty saved list means no filters', array() === \Starfiniti\Filters\Settings::filters() );

\Starfiniti\Filters\Settings::reset();
$check( 'settings: after a reset the default filters are back', count( \Starfiniti\Filters\Settings::default_definitions() ) === count( \Starfiniti\Filters\Settings::definitions() ) );

if ( null === $saved_settings ) {
	delete_option( 'sffilters_settings' );
} else {
	update_option( 'sffilters_settings', $saved_settings );
}

// --- Updates. GitHub is played by the list below, nothing leaves the machine.

$file     = plugin_basename( SFFILTERS_PLUGIN_FILE );
$headers  = get_plugin_data( SFFILTERS_PLUGIN_FILE, false, false );
$uri      = \Starfiniti\Filters\Updater::UPDATE_URI;
$api      = 'https://api.github.com/repos/Starfiniti/starfiniti-filters/releases/latest';
$zip_name = 'starfiniti-filters-9.9.9.zip';
$zip_url  = $uri . '/releases/download/v9.9.9/' . $zip_name;
$zip_body = 'not really a zip, only bytes to checksum';
$zip_hash = hash( 'sha256', $zip_body );
$github   = array();

$play_github = static function ( $pre, $args, $url ) use ( &$github ) {
	if ( ! is_string( $url ) || false === strpos( $url, 'github' ) ) {
		return $pre;
	}

	if ( ! isset( $github[ $url ] ) ) {
		return new WP_Error( 'sffilters_test_offline', 'Not played in this check: ' . $url );
	}

	if ( ! empty( $args['stream'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of a test.
		file_put_contents( $args['filename'], $github[ $url ] );
	}

	return array(
		'headers'  => array(),
		'body'     => empty( $args['stream'] ) ? $github[ $url ] : '',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => $args['filename'] ?? null,
	);
};

add_filter( 'pre_http_request', $play_github, 10, 3 );

$release = static function ( array $change = array(), array $asset = array() ) use ( $zip_name, $zip_url, $zip_hash ): string {
	return (string) wp_json_encode(
		array_merge(
			array(
				'tag_name'   => 'v9.9.9',
				'draft'      => false,
				'prerelease' => false,
				'body'       => "- Added one thing.\n- Fixed another.",
				'assets'     => array(
					array_merge(
						array(
							'name'                 => $zip_name,
							'size'                 => 1234,
							'browser_download_url' => $zip_url,
							'digest'               => 'sha256:' . $zip_hash,
						),
						$asset
					),
				),
			),
			$change
		)
	);
};

$ask = static function ( string $body ) use ( &$github, $api, $headers, $file ) {
	delete_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY );

	$github = array( $api => $body );

	return apply_filters( 'update_plugins_github.com', false, $headers, $file, array() );
};

$check( 'updates: the plugin header names the update address', $uri === ( $headers['UpdateURI'] ?? '' ) && SFFILTERS_VERSION === ( $headers['Version'] ?? '' ), $headers );

$update = $ask( $release() );
$check( 'updates: a newer release is offered', is_array( $update ) && '9.9.9' === $update['version'] && $zip_url === $update['package'] && $file === $update['plugin'], $update );
$check( 'updates: the offer repeats the requirements of the plugin header', is_array( $update ) && ( $headers['RequiresWP'] ?? '' ) === $update['requires'] && ( $headers['RequiresPHP'] ?? '' ) === $update['requires_php'], $update );

$check( 'updates: the installed version is not offered again', false === $ask( $release( array( 'tag_name' => 'v' . SFFILTERS_VERSION ), array( 'name' => 'starfiniti-filters-' . SFFILTERS_VERSION . '.zip', 'browser_download_url' => $uri . '/releases/download/v' . SFFILTERS_VERSION . '/starfiniti-filters-' . SFFILTERS_VERSION . '.zip' ) ) ) );
$check( 'updates: a pre-release is not offered', false === $ask( $release( array( 'prerelease' => true ) ) ) );
$check( 'updates: a draft is not offered', false === $ask( $release( array( 'draft' => true ) ) ) );
$check( 'updates: a tag that is not a version is not offered', false === $ask( $release( array( 'tag_name' => 'nightly' ) ) ) );
$check( 'updates: a package from another repository is not offered', false === $ask( $release( array(), array( 'browser_download_url' => 'https://github.com/someone-else/starfiniti-filters/releases/download/v9.9.9/' . $zip_name ) ) ) );
$check( 'updates: a release without a checksum is not offered', false === $ask( $release( array(), array( 'digest' => null ) ) ) );
$check( 'updates: an answer that is not a release is not offered', false === $ask( '{"message":"API rate limit exceeded"}' ) );

$other = array( 'version' => '1.2.3' );
$check( 'updates: other plugins are left alone', $other === apply_filters( 'update_plugins_github.com', $other, $headers, 'another-plugin/another-plugin.php', array() ) );

$ask( $release() );
$details = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => dirname( $file ) ) );
$check( 'updates: the details window shows the version and the release notes', is_object( $details ) && '9.9.9' === $details->version && false !== strpos( $details->sections['changelog'], '<li>Added one thing.</li>' ), $details );

$download = static function ( string $package ) {
	$reply = apply_filters( 'upgrader_pre_download', false, $package, null, array() );

	if ( is_string( $reply ) && is_file( $reply ) ) {
		wp_delete_file( $reply );

		return 'installed';
	}

	return is_wp_error( $reply ) ? $reply->get_error_code() : $reply;
};

$ask( $release() );
$github[ $zip_url ] = $zip_body;
$check( 'updates: a package that matches its checksum is accepted', 'installed' === $download( $zip_url ), $download( $zip_url ) );

$github[ $zip_url ] = $zip_body . ' tampered';
$check( 'updates: a package that does not match its checksum is refused', 'sffilters_update_mismatch' === $download( $zip_url ), $download( $zip_url ) );

$sum_url = $zip_url . '.sha256';
$ask(
	(string) wp_json_encode(
		array(
			'tag_name' => 'v9.9.9',
			'assets'   => array(
				array(
					'name'                 => $zip_name,
					'size'                 => 1234,
					'browser_download_url' => $zip_url,
				),
				array(
					'name'                 => $zip_name . '.sha256',
					'size'                 => 90,
					'browser_download_url' => $sum_url,
				),
			),
		)
	)
);
$github[ $zip_url ] = $zip_body;
$github[ $sum_url ] = $zip_hash . '  ' . $zip_name . "\n";
$check( 'updates: the checksum file is used when the release has no digest', 'installed' === $download( $zip_url ), $download( $zip_url ) );

unset( $github[ $sum_url ] );
$check( 'updates: without a readable checksum nothing is installed', 'sffilters_update_unverified' === $download( $zip_url ), $download( $zip_url ) );

$ask( '{"message":"Not Found"}' );
$github[ $zip_url ] = $zip_body;
$check( 'updates: without release data nothing is installed', 'sffilters_update_unverified' === $download( $zip_url ), $download( $zip_url ) );

$check( 'updates: packages of other plugins are left to WordPress', false === $download( 'https://downloads.wordpress.org/plugin/woocommerce.zip' ) );

$ask( $release() );
// Called directly: other plugins hooked to `upgrader_process_complete` expect a real upgrader.
( new \Starfiniti\Filters\Updater() )->forget(
	null,
	array(
		'type'    => 'plugin',
		'action'  => 'update',
		'plugins' => array( $file ),
	)
);
$check( 'updates: the remembered release is dropped after an update', false === get_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY ) );

remove_filter( 'pre_http_request', $play_github, 10 );
delete_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY );

// --- Report.

$failed = array_values( array_filter( $sffilters_results, static fn( array $result ): bool => ! $result['pass'] ) );

foreach ( $sffilters_results as $result ) {
	echo ( $result['pass'] ? 'ok      ' : 'FAILED  ' ) . $result['name'] . ( '' !== $result['detail'] ? "\n        " . $result['detail'] : '' ) . "\n";
}

echo "\n" . ( count( $sffilters_results ) - count( $failed ) ) . ' of ' . count( $sffilters_results ) . " checks passed.\n";

$report = getenv( 'SFFILTERS_TEST_RESULTS' );
$report = is_string( $report ) && '' !== $report ? $report : SFFILTERS_PLUGIN_DIR . '.cache/test-results.json';

if ( wp_mkdir_p( dirname( $report ) ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A report of a test run.
	file_put_contents(
		$report,
		(string) wp_json_encode(
			array(
				'checks' => $sffilters_results,
				'http'   => $http,
			)
		)
	);
}

if ( array() !== $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	exit( 1 );
}
