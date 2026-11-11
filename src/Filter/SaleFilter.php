<?php
/**
 * Filter that keeps only products on sale.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `sf_sale=1`.
 */
final class SaleFilter extends Filter {

	public function parse( $raw ): array {
		return in_array( '1', self::split( $raw ), true ) ? array( '1' ) : array();
	}

	public function clauses( array $values ): array {
		return array(
			'lookup' => array( 'onsale' => true ),
		);
	}

	public function facet( string $ids_sql, array $values ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query.
		$count    = (int) $wpdb->get_var(
			"SELECT COUNT( * )
			FROM {$wpdb->wc_product_meta_lookup}
			WHERE onsale = 1 AND product_id IN ( {$ids_sql} )"
		);
		$selected = array() !== $values;

		if ( 0 === $count && ! $selected ) {
			return array();
		}

		return array(
			'kind'     => 'toggle',
			'count'    => $count,
			'selected' => $selected,
		);
	}
}
