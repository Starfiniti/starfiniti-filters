<?php
/**
 * Turns filter state into product query conditions.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every integration filters products through apply(), so they all filter the same way.
 */
final class Query {

	/**
	 * Query var with conditions on the WooCommerce product lookup table.
	 */
	public const LOOKUP_VAR = 'sffilters_lookup';

	public function register(): void {
		add_filter( 'posts_clauses', array( $this, 'lookup_clauses' ), 10, 2 );
	}

	/**
	 * Adds the conditions of every filter in use to a set of WP_Query vars.
	 *
	 * @param array                 $query_vars WP_Query vars.
	 * @param array<string, Filter> $filters    Filters keyed by slug.
	 * @param State                 $state      Filter values.
	 */
	public static function apply( array $query_vars, array $filters, State $state ): array {
		$tax_clauses = array();
		$lookup      = array();

		foreach ( $state->all() as $slug => $values ) {
			if ( ! isset( $filters[ $slug ] ) ) {
				continue;
			}

			$clauses = $filters[ $slug ]->clauses( $values );

			if ( ! empty( $clauses['tax_query'] ) ) {
				$tax_clauses[] = $clauses['tax_query'];
			}

			if ( ! empty( $clauses['lookup'] ) ) {
				$lookup = array_merge( $lookup, $clauses['lookup'] );
			}
		}

		if ( array() !== $tax_clauses ) {
			$existing = isset( $query_vars['tax_query'] ) && is_array( $query_vars['tax_query'] ) ? $query_vars['tax_query'] : array();

			$query_vars['tax_query'] = array() === $existing
				? $tax_clauses
				: array_merge(
					array(
						'relation' => 'AND',
						$existing,
					),
					$tax_clauses
				);
		}

		if ( array() !== $lookup ) {
			$existing = isset( $query_vars[ self::LOOKUP_VAR ] ) && is_array( $query_vars[ self::LOOKUP_VAR ] ) ? $query_vars[ self::LOOKUP_VAR ] : array();

			$query_vars[ self::LOOKUP_VAR ] = array_merge( $existing, $lookup );
		}

		return $query_vars;
	}

	/**
	 * Returns SQL that selects the IDs of all products matching the query vars.
	 *
	 * WP_Query builds the SQL and is stopped before it runs it, so the result can be
	 * used as a subquery for counting.
	 *
	 * @param array $query_vars WP_Query vars.
	 */
	public static function ids_sql( array $query_vars ): string {
		unset( $query_vars['paged'], $query_vars['offset'] );

		$query_vars = array_merge(
			$query_vars,
			array(
				'fields'                 => 'ids',
				'nopaging'               => true,
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'orderby'                => 'none',
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		$stop = static function (): array {
			return array();
		};

		add_filter( 'posts_pre_query', $stop, PHP_INT_MAX );
		$query = new \WP_Query( $query_vars );
		remove_filter( 'posts_pre_query', $stop, PHP_INT_MAX );

		return trim( (string) $query->request );
	}

	/**
	 * Applies price, stock and sale conditions through the product lookup table.
	 *
	 * @param array     $clauses SQL clauses of the query.
	 * @param \WP_Query $query   The query being built.
	 */
	public function lookup_clauses( $clauses, $query ) {
		$lookup = $query instanceof \WP_Query ? $query->get( self::LOOKUP_VAR ) : null;

		if ( empty( $lookup ) || ! is_array( $lookup ) || ! is_array( $clauses ) ) {
			return $clauses;
		}

		global $wpdb;

		// WooCommerce joins this table under the same alias for sorting. Join it once.
		if ( false === strpos( $clauses['join'], 'wc_product_meta_lookup' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} wc_product_meta_lookup ON {$wpdb->posts}.ID = wc_product_meta_lookup.product_id ";
		}

		if ( isset( $lookup['min_price'] ) ) {
			$clauses['where'] .= $wpdb->prepare( ' AND wc_product_meta_lookup.max_price >= %f', (float) $lookup['min_price'] );
		}

		if ( isset( $lookup['max_price'] ) ) {
			$clauses['where'] .= $wpdb->prepare( ' AND wc_product_meta_lookup.min_price <= %f', (float) $lookup['max_price'] );
		}

		if ( ! empty( $lookup['stock_status'] ) && is_array( $lookup['stock_status'] ) ) {
			$statuses = array_map( 'esc_sql', array_map( 'strval', $lookup['stock_status'] ) );

			$clauses['where'] .= " AND wc_product_meta_lookup.stock_status IN ( '" . implode( "', '", $statuses ) . "' )";
		}

		if ( ! empty( $lookup['onsale'] ) ) {
			$clauses['where'] .= ' AND wc_product_meta_lookup.onsale = 1';
		}

		return $clauses;
	}
}
