<?php
/**
 * Plugin Name:       Starfiniti Filters for WooCommerce
 * Plugin URI:        https://starfiniti.com/
 * Description:       Product filters for the WooCommerce shop your theme already renders.
 * Version:           0.3.0
 * Update URI:        https://github.com/Starfiniti/starfiniti-filters
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Starfiniti
 * Author URI:        https://starfiniti.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       starfiniti-filters
 * Domain Path:       /languages
 * WC requires at least: 9.6
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SFFILTERS_VERSION', '0.3.0' );
define( 'SFFILTERS_PLUGIN_FILE', __FILE__ );
define( 'SFFILTERS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFFILTERS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Starfiniti\\Filters\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = SFFILTERS_PLUGIN_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		\Starfiniti\Filters\Plugin::instance()->boot();
	}
);
