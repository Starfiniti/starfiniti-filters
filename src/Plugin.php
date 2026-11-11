<?php
/**
 * Plugin bootstrap.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress once WooCommerce is available.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

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
}
