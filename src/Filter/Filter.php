<?php
/**
 * Base class for one filter in the panel.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A filter reads its value from the URL, narrows the product query and
 * lists the choices that exist in a given set of products.
 */
abstract class Filter {

	/**
	 * Upper bound on values read from one URL parameter.
	 */
	private const MAX_VALUES = 50;

	public function __construct(
		protected string $slug,
		protected string $label
	) {}

	public function slug(): string {
		return $this->slug;
	}

	public function label(): string {
		return $this->label;
	}

	/**
	 * Name of the URL parameter, for example `sf_brand`.
	 */
	public function param(): string {
		return 'sf_' . $this->slug;
	}

	/**
	 * Turns the raw URL value into clean values. An empty array means the filter is not in use.
	 *
	 * @param mixed $raw Unslashed request value, a string or an array.
	 */
	abstract public function parse( $raw ): array;

	/**
	 * Returns the conditions for the product query.
	 *
	 * `tax_query` holds one clause for WP_Tax_Query. `lookup` holds conditions on the
	 * WooCommerce product lookup table, see Query::lookup_clauses().
	 *
	 * @param array $values Clean values from parse().
	 * @return array{tax_query?: array, lookup?: array}
	 */
	abstract public function clauses( array $values ): array;

	/**
	 * Returns what the panel shows for this filter, or an empty array to hide it.
	 *
	 * @param string $ids_sql SQL that selects the IDs of the products this filter chooses from.
	 * @param array  $values  Clean values from parse().
	 */
	abstract public function facet( string $ids_sql, array $values ): array;

	/**
	 * Splits a request value such as `anker,xiaomi` or `array( 'anker', 'xiaomi' )` into a list.
	 *
	 * @param mixed $raw Unslashed request value.
	 * @return string[]
	 */
	protected static function split( $raw ): array {
		$parts = is_array( $raw ) ? array_filter( $raw, 'is_scalar' ) : array( is_scalar( $raw ) ? $raw : '' );
		$parts = explode( ',', implode( ',', array_map( 'strval', $parts ) ) );
		$parts = array_filter( array_map( 'trim', $parts ), 'strlen' );

		return array_slice( array_values( $parts ), 0, self::MAX_VALUES );
	}
}
