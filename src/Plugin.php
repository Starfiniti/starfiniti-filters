<?php
/**
 * Plugin bootstrap.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;
use Starfiniti\Filters\Integration\MainQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress once WooCommerce is available.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	/**
	 * @var array<string, Filter>|null
	 */
	private ?array $filters = null;

	private ?State $state = null;

	private ?array $base_query_vars = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new Query() )->register();
		( new MainQuery() )->register();
		( new Panel() )->register();

		/**
		 * Fires once Starfiniti Filters has loaded with WooCommerce active.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'sffilters_loaded', $this );
	}

	public function is_booted(): bool {
		return $this->booted;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'starfiniti-filters',
			false,
			dirname( plugin_basename( SFFILTERS_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Returns the configured filters, keyed by slug. Call after `init`.
	 *
	 * @return array<string, Filter>
	 */
	public function filters(): array {
		if ( null === $this->filters ) {
			$this->filters = Settings::filters();
		}

		return $this->filters;
	}

	/**
	 * Returns the filter values of the current request.
	 */
	public function state(): State {
		if ( null === $this->state ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filters are public, read-only URL parameters. Each filter sanitizes its own value.
			$this->state = State::from_request( $this->filters(), wp_unslash( $_GET ) );
		}

		return $this->state;
	}

	/**
	 * Stores the query vars of the product list on this page, before any filter is applied.
	 *
	 * The panel counts choices against these.
	 *
	 * @param array $query_vars WP_Query vars.
	 */
	public function set_base_query_vars( array $query_vars ): void {
		$this->base_query_vars = $query_vars;
	}

	public function base_query_vars(): ?array {
		return $this->base_query_vars;
	}
}
