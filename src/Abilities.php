<?php
/**
 * Abilities for AI agents.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings with the WordPress Abilities API (WordPress 6.9+), so an agent
 * connected through the MCP Adapter can read and change them. Older versions skip this.
 */
final class Abilities {

	private const CATEGORY = 'starfiniti-filters';

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'abilities' ) );
	}

	public function category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Starfiniti Filters', 'starfiniti-filters' ),
				'description' => __( 'Product filters of the WooCommerce shop.', 'starfiniti-filters' ),
			)
		);
	}

	public function abilities(): void {
		$filter = array(
			'type'       => 'object',
			'properties' => array(
				'source'  => array(
					'type'        => 'string',
					'description' => 'What the filter is built from: a product taxonomy such as product_cat, product_brand, product_tag or pa_color, or price, stock or sale. get-settings lists them in available_sources.',
				),
				'label'   => array(
					'type'        => 'string',
					'description' => 'Title in the panel. Empty for the default title.',
				),
				'display' => array(
					'type'        => 'string',
					'enum'        => array( 'checkboxes', 'links', '' ),
					'description' => 'For taxonomies. checkboxes filters the current list. links makes each term a link to its own page, for example the category page.',
				),
				'slug'    => array(
					'type'        => 'string',
					'description' => 'Used in the URL as sf_<slug>. Keep the slug of an existing filter. Empty for the default.',
				),
			),
			'required'   => array( 'source' ),
		);

		wp_register_ability(
			'starfiniti-filters/get-settings',
			array(
				'label'               => __( 'Get filter settings', 'starfiniti-filters' ),
				'description'         => 'Returns the product filters of the shop in panel order, how the product list continues (pages, more or infinite) and every source a filter can be built from.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static fn() => Settings::export(),
				'permission_callback' => array( $this, 'allowed' ),
				'meta'                => $this->meta( true ),
			)
		);

		wp_register_ability(
			'starfiniti-filters/update-settings',
			array(
				'label'               => __( 'Update filter settings', 'starfiniti-filters' ),
				'description'         => 'Changes the product filters of the shop. "filters" replaces the whole list and sets the panel order, so send every filter that should stay. A key that is left out keeps its saved value. Returns the settings after the change.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'filters'    => array(
							'type'        => 'array',
							'description' => 'The complete list of filters in panel order.',
							'items'       => $filter,
						),
						'pagination' => array(
							'type'        => 'string',
							'enum'        => Settings::PAGINATION,
							'description' => 'How the product list continues: pages for page links, more for a "Load more" button, infinite for infinite scroll.',
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'allowed' ),
				'meta'                => $this->meta( false ),
			)
		);
	}

	public function allowed(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Saves the settings an agent sends.
	 *
	 * @param mixed $input Validated against the input schema.
	 * @return array|\WP_Error
	 */
	public function update( $input ) {
		$saved = Settings::save( array_intersect_key( (array) $input, array_flip( array( 'filters', 'pagination' ) ) ) );

		return is_wp_error( $saved ) ? $saved : Settings::export();
	}

	/**
	 * Makes an ability available over REST and to the MCP Adapter.
	 */
	private function meta( bool $readonly ): array {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}
}
