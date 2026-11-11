<?php
/**
 * Filter by price range.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `sf_price=50-200`. Either end may be left out: `50-` or `-200`.
 */
final class PriceFilter extends Filter {

	public function parse( $raw ): array {
		if ( is_array( $raw ) ) {
			$min = $raw['min'] ?? '';
			$max = $raw['max'] ?? '';
		} else {
			$parts = explode( '-', is_scalar( $raw ) ? (string) $raw : '', 2 );
			$min   = $parts[0];
			$max   = $parts[1] ?? '';
		}

		$min = self::amount( $min );
		$max = self::amount( $max );

		if ( null === $min && null === $max ) {
			return array();
		}

		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	public function clauses( array $values ): array {
		$lookup = array();

		if ( isset( $values['min'] ) ) {
			$lookup['min_price'] = (float) $values['min'];
		}

		if ( isset( $values['max'] ) ) {
			$lookup['max_price'] = (float) $values['max'];
		}

		return array( 'lookup' => $lookup );
	}

	public function facet( string $ids_sql, array $values ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query.
		$row = $wpdb->get_row(
			"SELECT MIN( min_price ) AS low, MAX( max_price ) AS high
			FROM {$wpdb->wc_product_meta_lookup}
			WHERE product_id IN ( {$ids_sql} )"
		);

		if ( ! $row || null === $row->low || null === $row->high ) {
			return array();
		}

		$low  = floor( (float) $row->low );
		$high = ceil( (float) $row->high );

		if ( $low >= $high && array() === $values ) {
			return array();
		}

		return array(
			'kind' => 'range',
			'min'  => $low,
			'max'  => $high,
			'from' => $values['min'] ?? null,
			'to'   => $values['max'] ?? null,
		);
	}

	/**
	 * Reads one amount. Accepts a decimal comma.
	 *
	 * @param mixed $value Raw amount.
	 */
	private static function amount( $value ): ?float {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = str_replace( ',', '.', trim( (string) $value ) );

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0.0, (float) $value );
	}
}
