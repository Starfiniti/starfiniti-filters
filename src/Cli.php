<?php
/**
 * WP-CLI commands.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the product filters of the shop.
 *
 * ## EXAMPLES
 *
 *     # Show the filters in panel order.
 *     $ wp sffilters list
 *
 *     # Add a filter for the Color attribute as the second filter.
 *     $ wp sffilters add pa_color --position=2
 *
 *     # Continue the product list with a "Load more" button.
 *     $ wp sffilters pagination more
 */
final class Cli {

	/**
	 * Lists the filters in panel order.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( Settings::export()['filters'] as $index => $filter ) {
			$rows[] = array(
				'position' => $index + 1,
				'slug'     => $filter['slug'],
				'source'   => $filter['source'],
				'label'    => '' !== $filter['label'] ? $filter['label'] : $filter['default_label'],
				'param'    => $filter['param'],
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'position', 'slug', 'source', 'label', 'param' ) );
	}

	/**
	 * Lists what a filter can be built from.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function sources( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( Settings::export()['available_sources'] as $source ) {
			$rows[] = array(
				'source' => $source['source'],
				'label'  => $source['label'],
				'in_use' => $source['in_use'] ? 'yes' : 'no',
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'source', 'label', 'in_use' ) );
	}

	/**
	 * Adds a filter.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : A product taxonomy such as product_tag or pa_color, or price, stock or sale. See `wp sffilters sources`.
	 *
	 * [--label=<label>]
	 * : Title in the panel. Defaults to the name of the source.
	 *
	 * [--slug=<slug>]
	 * : Used in the URL as sf_<slug>. Defaults to a short form of the source.
	 *
	 * [--position=<position>]
	 * : Place in the panel, starting at 1. Defaults to the end.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp sffilters add pa_color --label="Colour" --position=2
	 *     Success: Added the filter "color".
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function add( array $args, array $assoc_args ): void {
		$filters = Settings::definitions();
		$new     = array(
			'source' => $args[0],
			'label'  => (string) ( $assoc_args['label'] ?? '' ),
			'slug'   => (string) ( $assoc_args['slug'] ?? '' ),
		);
		$place   = isset( $assoc_args['position'] ) ? max( 1, (int) $assoc_args['position'] ) : count( $filters ) + 1;

		array_splice( $filters, $place - 1, 0, array( $new ) );

		$saved = $this->save( array( 'filters' => $filters ) );

		foreach ( $saved as $filter ) {
			if ( Settings::key( $filter ) === $args[0] ) {
				WP_CLI::success( sprintf( 'Added the filter "%s".', $filter['slug'] ) );
			}
		}
	}

	/**
	 * Removes a filter.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Slug of the filter, see `wp sffilters list`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function remove( array $args ): void {
		$filters = Settings::definitions();

		unset( $filters[ $this->find( $filters, $args[0] ) ] );

		$this->save( array( 'filters' => array_values( $filters ) ) );
		WP_CLI::success( sprintf( 'Removed the filter "%s".', $args[0] ) );
	}

	/**
	 * Moves a filter to another place in the panel.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Slug of the filter, see `wp sffilters list`.
	 *
	 * <position>
	 * : New place in the panel, starting at 1.
	 *
	 * @param array $args Positional arguments.
	 */
	public function move( array $args ): void {
		$filters = Settings::definitions();
		$moved   = array_splice( $filters, $this->find( $filters, $args[0] ), 1 );
		$place   = min( count( $filters ) + 1, max( 1, (int) $args[1] ) );

		array_splice( $filters, $place - 1, 0, $moved );

		$this->save( array( 'filters' => $filters ) );
		WP_CLI::success( sprintf( 'Moved the filter "%s" to position %d.', $args[0], $place ) );
	}

	/**
	 * Sets the title of a filter in the panel.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Slug of the filter, see `wp sffilters list`.
	 *
	 * [<label>]
	 * : New title. Leave out to return to the default.
	 *
	 * @param array $args Positional arguments.
	 */
	public function label( array $args ): void {
		$filters = Settings::definitions();
		$index   = $this->find( $filters, $args[0] );

		$filters[ $index ]['label'] = (string) ( $args[1] ?? '' );

		$this->save( array( 'filters' => $filters ) );
		WP_CLI::success( sprintf( 'Updated the title of the filter "%s".', $args[0] ) );
	}

	/**
	 * Shows or sets how the product list continues.
	 *
	 * ## OPTIONS
	 *
	 * [<mode>]
	 * : Leave out to show the current mode.
	 * ---
	 * options:
	 *   - pages
	 *   - more
	 *   - infinite
	 * ---
	 *
	 * @param array $args Positional arguments.
	 */
	public function pagination( array $args ): void {
		if ( ! isset( $args[0] ) ) {
			WP_CLI::line( Settings::stored_pagination() );
			return;
		}

		$this->save( array( 'pagination' => $args[0] ) );
		WP_CLI::success( sprintf( 'The product list now continues with: %s.', $args[0] ) );
	}

	/**
	 * Removes the saved settings, so the default filters and page links apply again.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function reset( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Remove the saved filters and return to the defaults?', $assoc_args );

		Settings::reset();
		WP_CLI::success( 'Settings are back to the defaults.' );
	}

	/**
	 * Returns the position of the filter with this slug, or stops with an error.
	 *
	 * @param array[] $filters Filter definitions.
	 * @param string  $slug    Filter slug.
	 */
	private function find( array $filters, string $slug ): int {
		foreach ( $filters as $index => $filter ) {
			if ( $filter['slug'] === $slug ) {
				return $index;
			}
		}

		WP_CLI::error( sprintf( 'There is no filter with the slug "%s". See `wp sffilters list`.', $slug ) );
	}

	/**
	 * Saves settings, or stops with the reason they cannot be saved. Returns the saved filters.
	 *
	 * @param array $settings See Settings::save().
	 * @return array[]
	 */
	private function save( array $settings ): array {
		$saved = Settings::save( $settings );

		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved->get_error_message() );
		}

		return Settings::definitions();
	}
}
