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
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the one settings option and builds the configured filters.
 *
 * The settings page, WP-CLI and the abilities all go through this class.
 *
 * A stored filter definition is an array: `slug` (used in the URL as `sf_<slug>`), `source`
 * (`taxonomy`, `price`, `stock` or `sale`), `taxonomy` for taxonomy filters and an optional `label`.
 * When reading input, `source` may also name the taxonomy directly, for example `pa_color`.
 */
final class Settings {

	public const OPTION = 'sffilters_settings';

	public const PAGINATION = array( 'pages', 'more', 'infinite' );

	private const SOURCES = array( 'price', 'stock', 'sale' );

	/**
	 * Taxonomies WooCommerce uses internally. They are not offered as filters.
	 */
	private const HIDDEN = array( 'product_type', 'product_visibility', 'product_shipping_class' );

	/**
	 * Returns the configured filters in panel order, keyed by slug.
	 *
	 * @return array<string, Filter>
	 */
	public static function filters(): array {
		/**
		 * Filters the filter definitions before the filters are built.
		 *
		 * @param array[] $definitions Filter definitions in panel order.
		 */
		$definitions = (array) apply_filters( 'sffilters_filter_definitions', self::definitions() );
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
	 * Returns how the product list continues: `pages` for page links, `more` for a
	 * "Load more" button, `infinite` to load the next page as the shopper scrolls.
	 */
	public static function pagination(): string {
		/**
		 * Filters how the product list continues.
		 *
		 * @param string $mode `pages`, `more` or `infinite`.
		 */
		$mode = (string) apply_filters( 'sffilters_pagination', self::stored_pagination() );

		return in_array( $mode, self::PAGINATION, true ) ? $mode : 'pages';
	}

	/**
	 * Returns the saved pagination mode, before the `sffilters_pagination` filter.
	 */
	public static function stored_pagination(): string {
		$stored = get_option( self::OPTION, array() );
		$mode   = is_array( $stored ) && isset( $stored['pagination'] ) ? (string) $stored['pagination'] : '';

		return in_array( $mode, self::PAGINATION, true ) ? $mode : 'pages';
	}

	/**
	 * Returns the saved filter definitions in panel order, or the defaults until the store saves its own.
	 *
	 * Definitions that can no longer be used, such as a deleted attribute, are left out. Call after `init`.
	 *
	 * @return array[]
	 */
	public static function definitions(): array {
		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && isset( $stored['filters'] ) && is_array( $stored['filters'] ) ) {
			return self::clean( $stored['filters'] );
		}

		return self::clean( self::default_definitions() );
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
	 * Saves settings. A key that is left out keeps its saved value.
	 *
	 * @param array $settings `pagination` (one of PAGINATION) and/or `filters` (definitions in panel order).
	 * @return true|WP_Error
	 */
	public static function save( array $settings ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( array_key_exists( 'pagination', $settings ) ) {
			if ( ! in_array( $settings['pagination'], self::PAGINATION, true ) ) {
				return new WP_Error( 'sffilters_invalid_pagination', __( 'Pagination must be pages, more or infinite.', 'starfiniti-filters' ), array( 'status' => 400 ) );
			}

			$stored['pagination'] = $settings['pagination'];
		}

		if ( array_key_exists( 'filters', $settings ) ) {
			$filters = is_array( $settings['filters'] )
				? self::clean( $settings['filters'], true )
				: new WP_Error( 'sffilters_invalid_filters', __( 'Filters must be a list.', 'starfiniti-filters' ), array( 'status' => 400 ) );

			if ( is_wp_error( $filters ) ) {
				return $filters;
			}

			$stored['filters'] = $filters;
		}

		update_option( self::OPTION, $stored, true );

		return true;
	}

	/**
	 * Removes the saved settings, so the defaults apply again.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Returns the settings the way the abilities and WP-CLI show them.
	 *
	 * @return array{pagination: string, filters: array[], available_sources: array[]}
	 */
	public static function export(): array {
		$filters = array();
		$used    = array();

		foreach ( self::definitions() as $definition ) {
			$key          = self::key( $definition );
			$used[ $key ] = true;
			$filters[]    = array(
				'slug'          => $definition['slug'],
				'source'        => $key,
				'label'         => $definition['label'] ?? '',
				'default_label' => self::default_label( $definition['source'], $definition['taxonomy'] ?? '' ),
				'param'         => 'sf_' . $definition['slug'],
			);
		}

		$sources = array();

		foreach ( self::sources() as $key => $source ) {
			$sources[] = array(
				'source' => $key,
				'label'  => $source['label'],
				'in_use' => isset( $used[ $key ] ),
			);
		}

		return array(
			'pagination'        => self::stored_pagination(),
			'filters'           => $filters,
			'available_sources' => $sources,
		);
	}

	/**
	 * Lists what a filter can be built from: the product taxonomies, then price, availability and sale.
	 *
	 * Keyed by the short source name, which is the taxonomy name or `price`, `stock` or `sale`. Call after `init`.
	 *
	 * @return array<string, array{source: string, taxonomy: string, label: string, slug: string, attribute: bool}>
	 */
	public static function sources(): array {
		$first = array();
		$rest  = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy => $object ) {
			// Taxonomies without a screen of their own are internal, for example product visibility.
			if ( in_array( $taxonomy, self::HIDDEN, true ) || ! $object->show_ui ) {
				continue;
			}

			$source = array(
				'source'    => 'taxonomy',
				'taxonomy'  => $taxonomy,
				'label'     => self::default_label( 'taxonomy', $taxonomy ),
				'slug'      => self::default_slug( 'taxonomy', $taxonomy ),
				'attribute' => str_starts_with( $taxonomy, 'pa_' ),
			);

			if ( in_array( $taxonomy, array( 'product_cat', 'product_brand', 'product_tag' ), true ) ) {
				$first[ $taxonomy ] = $source;
			} else {
				$rest[ $taxonomy ] = $source;
			}
		}

		uasort( $rest, static fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] ) );

		$sources = array_merge( array_intersect_key( array_flip( array( 'product_cat', 'product_brand', 'product_tag' ) ), $first ), $first ) + $rest;

		foreach ( self::SOURCES as $source ) {
			$sources[ $source ] = array(
				'source'    => $source,
				'taxonomy'  => '',
				'label'     => self::default_label( $source, '' ),
				'slug'      => $source,
				'attribute' => false,
			);
		}

		return $sources;
	}

	/**
	 * Returns the short source name of a definition: the taxonomy name, or `price`, `stock` or `sale`.
	 *
	 * @param array $definition Stored filter definition.
	 */
	public static function key( array $definition ): string {
		return 'taxonomy' === ( $definition['source'] ?? '' ) ? (string) ( $definition['taxonomy'] ?? '' ) : (string) ( $definition['source'] ?? '' );
	}

	/**
	 * Returns a list of definitions in their stored shape, each source once and each slug once.
	 *
	 * @param array $definitions Raw definitions.
	 * @param bool  $strict      Return an error for a definition that cannot be used. Otherwise it is left out.
	 * @return array[]|WP_Error
	 */
	public static function clean( array $definitions, bool $strict = false ) {
		$clean = array();
		$seen  = array();

		foreach ( $definitions as $definition ) {
			$item = is_array( $definition )
				? self::normalize( $definition )
				: new WP_Error( 'sffilters_invalid_filter', __( 'Each filter must be an object with a source.', 'starfiniti-filters' ), array( 'status' => 400 ) );

			if ( ! is_wp_error( $item ) && isset( $seen[ self::key( $item ) ] ) ) {
				/* translators: %s: filter source, for example product_cat. */
				$item = new WP_Error( 'sffilters_duplicate_filter', sprintf( __( 'The filter "%s" is listed twice.', 'starfiniti-filters' ), self::key( $item ) ), array( 'status' => 400 ) );
			}

			if ( is_wp_error( $item ) ) {
				if ( $strict ) {
					return $item;
				}

				continue;
			}

			$slug   = $item['slug'];
			$number = 2;

			while ( isset( $clean[ $slug ] ) ) {
				$slug = $item['slug'] . '-' . $number++;
			}

			$item['slug']               = $slug;
			$clean[ $slug ]             = $item;
			$seen[ self::key( $item ) ] = true;
		}

		return array_values( $clean );
	}

	/**
	 * Returns one definition in its stored shape, or an error when it cannot be used.
	 *
	 * @param array $definition Raw definition.
	 * @return array|WP_Error
	 */
	private static function normalize( array $definition ) {
		$source   = is_scalar( $definition['source'] ?? '' ) ? (string) $definition['source'] : '';
		$taxonomy = is_scalar( $definition['taxonomy'] ?? '' ) ? (string) $definition['taxonomy'] : '';

		// A taxonomy may be named directly, for example `pa_color`.
		if ( 'taxonomy' !== $source && ! in_array( $source, self::SOURCES, true ) ) {
			$taxonomy = $source;
			$source   = 'taxonomy';
		}

		if ( 'taxonomy' === $source && ( in_array( $taxonomy, self::HIDDEN, true ) || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( 'product', $taxonomy ) ) ) {
			/* translators: %s: what was given as the filter source. */
			return new WP_Error( 'sffilters_unknown_source', sprintf( __( '"%s" is not a filter source.', 'starfiniti-filters' ), $taxonomy ), array( 'status' => 400 ) );
		}

		$slug  = sanitize_key( is_scalar( $definition['slug'] ?? '' ) ? (string) $definition['slug'] : '' );
		$label = trim( wp_strip_all_tags( is_scalar( $definition['label'] ?? '' ) ? (string) $definition['label'] : '' ) );
		$clean = array(
			'slug'   => '' !== $slug ? $slug : self::default_slug( $source, $taxonomy ),
			'source' => $source,
		);

		if ( 'taxonomy' === $source ) {
			$clean['taxonomy'] = $taxonomy;
		}

		if ( '' !== $label ) {
			$clean['label'] = $label;
		}

		return $clean;
	}

	/**
	 * Returns the title a filter gets when the store sets none.
	 *
	 * @param string $source   `taxonomy`, `price`, `stock` or `sale`.
	 * @param string $taxonomy Taxonomy name for taxonomy filters.
	 */
	public static function default_label( string $source, string $taxonomy ): string {
		switch ( 'taxonomy' === $source ? $taxonomy : $source ) {
			case 'price':
				return __( 'Price', 'starfiniti-filters' );

			case 'stock':
				return __( 'Availability', 'starfiniti-filters' );

			case 'sale':
				return __( 'On sale', 'starfiniti-filters' );

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

	/**
	 * Returns the slug a filter gets when none is given, for example `color` for `pa_color`.
	 */
	private static function default_slug( string $source, string $taxonomy ): string {
		if ( 'taxonomy' !== $source ) {
			return $source;
		}

		$short = array(
			'product_cat'   => 'cat',
			'product_brand' => 'brand',
			'product_tag'   => 'tag',
		);

		if ( isset( $short[ $taxonomy ] ) ) {
			return $short[ $taxonomy ];
		}

		$slug = sanitize_key( sanitize_title( str_starts_with( $taxonomy, 'pa_' ) ? substr( $taxonomy, 3 ) : $taxonomy ) );

		return '' !== $slug ? $slug : 'filter';
	}

	/**
	 * Builds one filter, or returns null when the definition cannot be used.
	 *
	 * @param array $definition Filter definition.
	 */
	private static function build( array $definition ): ?Filter {
		$definition = self::normalize( $definition );

		if ( is_wp_error( $definition ) ) {
			return null;
		}

		$slug     = $definition['slug'];
		$taxonomy = $definition['taxonomy'] ?? '';
		$label    = $definition['label'] ?? self::default_label( $definition['source'], $taxonomy );

		switch ( $definition['source'] ) {
			case 'taxonomy':
				return new TaxonomyFilter( $slug, $label, $taxonomy );

			case 'price':
				return new PriceFilter( $slug, $label );

			case 'stock':
				return new StockFilter( $slug, $label );
		}

		return new SaleFilter( $slug, $label );
	}
}
