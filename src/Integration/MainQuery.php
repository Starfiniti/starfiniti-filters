<?php
/**
 * Filters the WooCommerce shop and product archive pages.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Integration;

use Starfiniti\Filters\Plugin;
use Starfiniti\Filters\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches the filters to the main product query that WooCommerce and the theme draw.
 */
final class MainQuery {

	private bool $results_open = false;

	public function register(): void {
		// Late, so conditions added by WooCommerce and other plugins are part of the unfiltered product list.
		add_action( 'woocommerce_product_query', array( $this, 'filter_products' ), 999 );

		add_action( 'woocommerce_before_shop_loop', array( $this, 'open_results' ), -1000 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'close_results' ), 1000 );
		add_action( 'woocommerce_no_products_found', array( $this, 'open_results' ), -1000 );
		add_action( 'woocommerce_no_products_found', array( $this, 'close_results' ), 1000 );

		add_filter( 'wp_robots', array( $this, 'robots' ) );
	}

	/**
	 * @param \WP_Query $query The main product query.
	 */
	public function filter_products( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		$plugin = Plugin::instance();
		$plugin->set_base_query_vars( $query->query_vars );

		$state = $plugin->state();

		if ( ! $state->is_active() ) {
			return;
		}

		$query_vars = Query::apply( $query->query_vars, $plugin->filters(), $state );

		foreach ( array( 'tax_query', Query::LOOKUP_VAR ) as $key ) {
			if ( isset( $query_vars[ $key ] ) ) {
				$query->set( $key, $query_vars[ $key ] );
			}
		}
	}

	/**
	 * Wraps the result count, sorting, products and pagination, so the script can swap them in one piece.
	 * The bar with the selected filters goes first.
	 */
	public function open_results(): void {
		if ( $this->results_open || null === Plugin::instance()->base_query_vars() || wc_get_loop_prop( 'is_shortcode' ) ) {
			return;
		}

		$this->results_open = true;

		// Page one of this list without its query string. The script sorts from here.
		$base = (string) strtok( get_pagenum_link( 1, false ), '?' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The bar is escaped in Panel::bar().
		echo '<div class="sffilters-results" data-sffilters-results data-sffilters-base="' . esc_url( $base ) . '">' . Plugin::instance()->panel()->bar();
	}

	public function close_results(): void {
		if ( ! $this->results_open ) {
			return;
		}

		$this->results_open = false;

		echo '</div>';
	}

	/**
	 * Keeps filtered pages out of search engines.
	 *
	 * @param array $robots Robots directives.
	 */
	public function robots( $robots ) {
		if ( is_array( $robots ) && did_action( 'wp' ) && Plugin::instance()->state()->is_active() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}
}
