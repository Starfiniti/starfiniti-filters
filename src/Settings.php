<?php
/**
 * Plugin settings.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;
use Starfiniti\Filters\Filter\PriceFilter;
use Starfiniti\Filters\Filter\SaleFilter;
use Starfiniti\Filters\Filter\StockFilter;
use Starfiniti\Filters\Filter\TaxonomyFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the one settings option and builds the configured filters.
 *
 * A filter definition is an array: `slug` (used in the URL as `sf_<slug>`), `source`
 * (`taxonomy`, `price`, `stock` or `sale`), `taxonomy` for taxonomy filters and an optional `label`.
 */
final class Settings {

	public const OPTION = 'sffilters_settings';

	/**
	 * Returns the configured filters in panel order, keyed by slug.
	 *
	 * @return array<string, Filter>
	 */
	public static function filters(): array {
		$stored      = get_option( self::OPTION, array() );
		$definitions = is_array( $stored ) && ! empty( $stored['filters'] ) && is_array( $stored['filters'] )
			? $stored['filters']
			: self::default_definitions();

		/**
		 * Filters the filter definitions before the filters are built.
		 *
		 * @param array[] $definitions Filter definitions in panel order.
		 */
		$definitions = (array) apply_filters( 'sffilters_filter_definitions', $definitions );
		$filters     = array();

		foreach ( $definitions as $definition ) {
			$filter = is_array( $definition ) ? self::build( $definition ) : null;

			if ( null !== $filter && ! isset( $filters[ $filter->slug() ] ) ) {
				$filters[ $filter->slug() ] = $filter;
			}
		}

		return $filters;
	}

	/**
	 * Filters used until the store configures its own.
	 *
	 * @return array[]
	 */
	public static function default_definitions(): array {
		return array(
			array(
				'slug'     => 'cat',
				'source'   => 'taxonomy',
				'taxonomy' => 'product_cat',
			),
			array(
				'slug'     => 'brand',
				'source'   => 'taxonomy',
				'taxonomy' => 'product_brand',
			),
			array(
				'slug'   => 'price',
				'source' => 'price',
			),
			array(
				'slug'   => 'stock',
				'source' => 'stock',
			),
			array(
				'slug'   => 'sale',
				'source' => 'sale',
			),
		);
	}

	/**
	 * Builds one filter, or returns null when the definition cannot be used.
	 *
	 * @param array $definition Filter definition.
	 */
	private static function build( array $definition ): ?Filter {
		$slug  = sanitize_key( (string) ( $definition['slug'] ?? '' ) );
		$label = trim( wp_strip_all_tags( (string) ( $definition['label'] ?? '' ) ) );

		if ( '' === $slug ) {
			return null;
		}

		switch ( (string) ( $definition['source'] ?? '' ) ) {
			case 'taxonomy':
				$taxonomy = (string) ( $definition['taxonomy'] ?? '' );

				if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( 'product', $taxonomy ) ) {
					return null;
				}

				return new TaxonomyFilter( $slug, '' !== $label ? $label : self::taxonomy_label( $taxonomy ), $taxonomy );

			case 'price':
				return new PriceFilter( $slug, '' !== $label ? $label : __( 'Price', 'starfiniti-filters' ) );

			case 'stock':
				return new StockFilter( $slug, '' !== $label ? $label : __( 'Availability', 'starfiniti-filters' ) );

			case 'sale':
				return new SaleFilter( $slug, '' !== $label ? $label : __( 'On sale', 'starfiniti-filters' ) );
		}

		return null;
	}

	private static function taxonomy_label( string $taxonomy ): string {
		switch ( $taxonomy ) {
			case 'product_cat':
				return __( 'Categories', 'starfiniti-filters' );

			case 'product_tag':
				return __( 'Tags', 'starfiniti-filters' );

			case 'product_brand':
				return __( 'Brands', 'starfiniti-filters' );
		}

		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			return wc_attribute_label( $taxonomy );
		}

		$object = get_taxonomy( $taxonomy );

		return $object ? (string) $object->labels->name : $taxonomy;
	}
}
