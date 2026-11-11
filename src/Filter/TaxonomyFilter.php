<?php
/**
 * Filter by terms of a product taxonomy.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Covers categories, tags, brands and product attributes.
 */
final class TaxonomyFilter extends Filter {

	public function __construct(
		string $slug,
		string $label,
		private string $taxonomy
	) {
		parent::__construct( $slug, $label );
	}

	public function parse( $raw ): array {
		$slugs = array_filter( array_map( 'sanitize_title', self::split( $raw ) ) );

		return array_values( array_unique( $slugs ) );
	}

	public function clauses( array $values ): array {
		return array(
			'tax_query' => array(
				'taxonomy' => $this->taxonomy,
				'field'    => 'slug',
				'terms'    => $values,
				'operator' => 'IN',
			),
		);
	}

	public function facet( string $ids_sql, array $values ): array {
		$hierarchical = is_taxonomy_hierarchical( $this->taxonomy );
		$root         = is_tax( $this->taxonomy ) ? (int) get_queried_object_id() : 0;

		// On the archive of a flat taxonomy, such as a brand page, there is nothing left to choose.
		if ( 0 !== $root && ! $hierarchical ) {
			return array();
		}

		$counts = $this->counts( $ids_sql, $hierarchical );
		$nodes  = array();

		foreach ( $this->terms( array_keys( $counts ), $values, $root ) as $term ) {
			$nodes[ $term->term_id ] = array(
				'value'    => urldecode( $term->slug ),
				'label'    => $term->name,
				'count'    => $counts[ $term->term_id ] ?? 0,
				'selected' => in_array( $term->slug, $values, true ),
				'parent'   => (int) $term->parent,
			);
		}

		if ( array() === $nodes ) {
			return array();
		}

		return array(
			'kind'    => 'options',
			'options' => self::tree( $nodes, 0 ),
		);
	}

	public function selected( array $values ): array {
		$names = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy,
				'slug'       => $values,
				'hide_empty' => false,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->slug ] = $term->name;
		}

		$chips = array();

		foreach ( $values as $value ) {
			$chips[] = array(
				'label'  => $names[ $value ] ?? urldecode( $value ),
				'values' => array_values( array_diff( $values, array( $value ) ) ),
			);
		}

		return $chips;
	}

	public function format( array $values ): string {
		return implode( ',', array_map( 'urldecode', $values ) );
	}

	/**
	 * Loads the terms that have products, plus selected terms that have none left.
	 *
	 * @param int[]    $term_ids Terms with at least one product.
	 * @param string[] $selected Selected term slugs.
	 * @param int      $root     When set, only descendants of this term are offered.
	 * @return \WP_Term[]
	 */
	private function terms( array $term_ids, array $selected, int $root ): array {
		$terms = array();

		if ( array() !== $term_ids ) {
			$args = array(
				'taxonomy'   => $this->taxonomy,
				'hide_empty' => false,
				'include'    => $term_ids,
			);

			if ( 0 !== $root ) {
				$args['child_of'] = $root;
			}

			$found = get_terms( $args );

			if ( ! is_wp_error( $found ) ) {
				foreach ( $found as $term ) {
					$terms[ $term->term_id ] = $term;
				}
			}
		}

		foreach ( $selected as $slug ) {
			$term = get_term_by( 'slug', $slug, $this->taxonomy );

			if ( $term instanceof \WP_Term && ! isset( $terms[ $term->term_id ] ) ) {
				$terms[ $term->term_id ] = $term;
			}
		}

		return $terms;
	}

	/**
	 * Counts products per term.
	 *
	 * In a hierarchical taxonomy a product in a child term also counts for every
	 * ancestor, once. That needs the product IDs, so those rows are not grouped in SQL.
	 *
	 * @return array<int, int>
	 */
	private function counts( string $ids_sql, bool $hierarchical ): array {
		global $wpdb;

		$select = $hierarchical ? 'tr.object_id AS object_id' : 'COUNT( DISTINCT tr.object_id ) AS total';
		$group  = $hierarchical ? '' : 'GROUP BY tt.term_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query, the taxonomy is escaped.
		$rows = $wpdb->get_results(
			"SELECT tt.term_id AS term_id, {$select}
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = '" . esc_sql( $this->taxonomy ) . "' AND tr.object_id IN ( {$ids_sql} )
			{$group}"
		);

		if ( ! $hierarchical ) {
			return array_map( 'intval', wp_list_pluck( (array) $rows, 'total', 'term_id' ) );
		}

		$lineage  = array();
		$products = array();

		foreach ( (array) $rows as $row ) {
			$term_id = (int) $row->term_id;

			if ( ! isset( $lineage[ $term_id ] ) ) {
				$lineage[ $term_id ] = array_merge( array( $term_id ), get_ancestors( $term_id, $this->taxonomy, 'taxonomy' ) );
			}

			foreach ( $lineage[ $term_id ] as $id ) {
				$products[ $id ][ (int) $row->object_id ] = true;
			}
		}

		return array_map( 'count', $products );
	}

	/**
	 * Nests options under their parents. An option whose parent is not listed becomes a top level option.
	 *
	 * @param array<int, array> $nodes  Options keyed by term ID.
	 * @param int               $parent Term ID to list the children of.
	 */
	private static function tree( array $nodes, int $parent ): array {
		$options = array();

		foreach ( $nodes as $term_id => $node ) {
			$node_parent = isset( $nodes[ $node['parent'] ] ) ? $node['parent'] : 0;

			if ( $node_parent !== $parent ) {
				continue;
			}

			unset( $node['parent'] );

			$node['children'] = self::tree( $nodes, $term_id );
			$options[]        = $node;
		}

		return $options;
	}
}
