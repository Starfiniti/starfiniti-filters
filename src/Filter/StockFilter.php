<?php
/**
 * Filter by stock status.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `sf_stock=instock,onbackorder`.
 */
final class StockFilter extends Filter {

	public function parse( $raw ): array {
		$statuses = array_keys( wc_get_product_stock_status_options() );

		return array_values( array_intersect( $statuses, self::split( $raw ) ) );
	}

	public function clauses( array $values ): array {
		return array(
			'lookup' => array( 'stock_status' => $values ),
		);
	}

	public function facet( string $ids_sql, array $values ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query.
		$rows = $wpdb->get_results(
			"SELECT stock_status, COUNT( * ) AS total
			FROM {$wpdb->wc_product_meta_lookup}
			WHERE product_id IN ( {$ids_sql} )
			GROUP BY stock_status"
		);

		$counts  = array_map( 'intval', wp_list_pluck( (array) $rows, 'total', 'stock_status' ) );
		$options = array();

		foreach ( wc_get_product_stock_status_options() as $status => $label ) {
			$selected = in_array( $status, $values, true );
			$count    = $counts[ $status ] ?? 0;

			if ( 0 === $count && ! $selected ) {
				continue;
			}

			$options[] = array(
				'value'    => $status,
				'label'    => $label,
				'count'    => $count,
				'selected' => $selected,
				'children' => array(),
			);
		}

		if ( array() === $options ) {
			return array();
		}

		return array(
			'kind'    => 'options',
			'options' => $options,
		);
	}

	public function selected( array $values ): array {
		$labels = wc_get_product_stock_status_options();
		$chips  = array();

		foreach ( $values as $value ) {
			$chips[] = array(
				'label'  => $labels[ $value ] ?? $value,
				'values' => array_values( array_diff( $values, array( $value ) ) ),
			);
		}

		return $chips;
	}
}
