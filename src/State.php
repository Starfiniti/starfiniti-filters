<?php
/**
 * Filter values read from the URL.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the clean values of every filter in use, keyed by filter slug.
 */
final class State {

	/**
	 * @param array<string, array> $values Clean values keyed by filter slug.
	 */
	private function __construct( private array $values ) {}

	/**
	 * Reads the state from request parameters such as `sf_brand=anker,xiaomi`.
	 *
	 * @param array<string, Filter> $filters Filters keyed by slug.
	 * @param array                 $request Unslashed request parameters.
	 */
	public static function from_request( array $filters, array $request ): self {
		$values = array();

		foreach ( $filters as $slug => $filter ) {
			if ( ! isset( $request[ $filter->param() ] ) ) {
				continue;
			}

			$parsed = $filter->parse( $request[ $filter->param() ] );

			if ( array() !== $parsed ) {
				$values[ $slug ] = $parsed;
			}
		}

		return new self( $values );
	}

	/**
	 * @return array<string, array>
	 */
	public function all(): array {
		return $this->values;
	}

	public function values( string $slug ): array {
		return $this->values[ $slug ] ?? array();
	}

	public function is_active(): bool {
		return array() !== $this->values;
	}

	/**
	 * Returns the state as if one filter were not in use.
	 *
	 * The choices of a filter are counted this way, so picking one brand still shows the others.
	 */
	public function without( string $slug ): self {
		$values = $this->values;

		unset( $values[ $slug ] );

		return new self( $values );
	}
}
