<?php
/**
 * Removes the plugin's own options. Products, terms and orders are never touched.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sffilters_settings' );
delete_option( 'sffilters_version' );
delete_site_transient( 'sffilters_release' );
