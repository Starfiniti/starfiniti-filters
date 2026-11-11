<?php
/**
 * The settings page.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Admin;

use Starfiniti\Filters\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One page under WooCommerce: which filters the panel shows, in what order, and how the product list continues.
 */
final class SettingsPage {

	public const SLUG = 'sffilters';

	private const CAPABILITY = 'manage_woocommerce';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_sffilters_save', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SFFILTERS_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function menu(): void {
		$this->hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Starfiniti Filters', 'starfiniti-filters' ),
			__( 'Filters', 'starfiniti-filters' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'starfiniti-filters' ) ) );

		return $links;
	}

	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'sffilters-admin', SFFILTERS_PLUGIN_URL . 'assets/admin.css', array(), SFFILTERS_VERSION );
		wp_enqueue_script( 'sffilters-admin', SFFILTERS_PLUGIN_URL . 'assets/admin.js', array( 'wp-a11y' ), SFFILTERS_VERSION, array( 'in_footer' => true ) );
		wp_add_inline_script(
			'sffilters-admin',
			'window.sffiltersAdmin = ' . wp_json_encode(
				array(
					'added'   => __( 'Filter added. Save changes to apply.', 'starfiniti-filters' ),
					'removed' => __( 'Filter removed. Save changes to apply.', 'starfiniti-filters' ),
					'moved'   => __( 'Filter moved. Save changes to apply.', 'starfiniti-filters' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Saves the form and returns to the page.
	 */
	public function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'starfiniti-filters' ), 403 );
		}

		check_admin_referer( 'sffilters_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below and again in Settings::clean().
		$posted  = isset( $_POST['sffilters'] ) && is_array( $_POST['sffilters'] ) ? wp_unslash( $_POST['sffilters'] ) : array();
		$keys    = isset( $posted['key'] ) && is_array( $posted['key'] ) ? array_values( $posted['key'] ) : array();
		$slugs   = isset( $posted['slug'] ) && is_array( $posted['slug'] ) ? array_values( $posted['slug'] ) : array();
		$labels  = isset( $posted['label'] ) && is_array( $posted['label'] ) ? array_values( $posted['label'] ) : array();
		$filters = array();

		// Rows arrive in the order they are shown.
		foreach ( $keys as $index => $key ) {
			$filters[] = array(
				'source' => is_string( $key ) ? sanitize_text_field( $key ) : '',
				'slug'   => isset( $slugs[ $index ] ) && is_string( $slugs[ $index ] ) ? sanitize_key( $slugs[ $index ] ) : '',
				'label'  => isset( $labels[ $index ] ) && is_string( $labels[ $index ] ) ? sanitize_text_field( $labels[ $index ] ) : '',
			);
		}

		// Without the script, the filter picked in the dropdown is added on save.
		if ( isset( $posted['add'] ) && is_string( $posted['add'] ) && '' !== $posted['add'] ) {
			$filters[] = array( 'source' => sanitize_text_field( $posted['add'] ) );
		}

		$pagination = isset( $posted['pagination'] ) && is_string( $posted['pagination'] ) ? sanitize_key( $posted['pagination'] ) : '';

		Settings::save(
			array(
				'filters'    => Settings::clean( $filters ),
				'pagination' => in_array( $pagination, Settings::PAGINATION, true ) ? $pagination : 'pages',
			)
		);

		wp_safe_redirect( add_query_arg( 'updated', '1', self::url() ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$sources    = Settings::sources();
		$pagination = Settings::stored_pagination();
		$rows       = '';
		$options    = '';
		$used       = array();

		foreach ( Settings::definitions() as $definition ) {
			$key          = Settings::key( $definition );
			$used[ $key ] = true;
			$rows        .= $this->row( $key, $definition['slug'], $definition['label'] ?? '', $sources[ $key ]['label'] ?? $key, ! empty( $sources[ $key ]['attribute'] ) );
		}

		foreach ( $sources as $key => $source ) {
			$options .= sprintf(
				'<option value="%1$s" data-name="%2$s" data-slug="%3$s"%4$s%5$s>%6$s</option>',
				esc_attr( $key ),
				esc_attr( $source['label'] ),
				esc_attr( $source['slug'] ),
				$source['attribute'] ? ' data-attribute' : '',
				disabled( isset( $used[ $key ] ), true, false ),
				/* translators: %s: attribute name, for example Color. */
				esc_html( $source['attribute'] ? sprintf( __( '%s (attribute)', 'starfiniti-filters' ), $source['label'] ) : $source['label'] )
			);
		}

		$modes = array(
			'pages'    => __( 'Page links', 'starfiniti-filters' ),
			'more'     => __( '"Load more" button', 'starfiniti-filters' ),
			'infinite' => __( 'Infinite scroll', 'starfiniti-filters' ),
		);
		?>
		<div class="wrap sffilters-admin">
			<h1><?php esc_html_e( 'Starfiniti Filters', 'starfiniti-filters' ); ?></h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a notice after the redirect. ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php esc_html_e( 'Settings saved.', 'starfiniti-filters' ); ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'View shop', 'starfiniti-filters' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sffilters_save">
				<?php wp_nonce_field( 'sffilters_save' ); ?>

				<h2><?php esc_html_e( 'Filters', 'starfiniti-filters' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: the [sffilters] shortcode. */
						esc_html__( 'The panel shows these filters in this order. Place the panel with the %s shortcode, for example in a sidebar widget or in the shop template of your page builder.', 'starfiniti-filters' ),
						'<code>[sffilters]</code>'
					);
					?>
				</p>

				<table class="widefat striped sffilters-admin__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Filter', 'starfiniti-filters' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Title in the panel', 'starfiniti-filters' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL parameter', 'starfiniti-filters' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Order', 'starfiniti-filters' ); ?></th>
						</tr>
					</thead>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in row(). ?>
					<tbody class="sffilters-admin__rows" data-sffilters-rows><?php echo $rows; ?></tbody>
					<tbody class="sffilters-admin__empty">
						<tr><td colspan="4"><?php esc_html_e( 'No filters yet. Add one below.', 'starfiniti-filters' ); ?></td></tr>
					</tbody>
				</table>

				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in row(). ?>
				<template data-sffilters-template><?php echo $this->row( '', '', '', '%s', false ); ?></template>

				<p class="sffilters-admin__add">
					<label for="sffilters-add"><?php esc_html_e( 'Add a filter', 'starfiniti-filters' ); ?></label>
					<select id="sffilters-add" name="sffilters[add]" data-sffilters-source>
						<option value=""><?php esc_html_e( 'Choose what to filter by', 'starfiniti-filters' ); ?></option>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
						<?php echo $options; ?>
					</select>
					<button type="button" class="button" data-sffilters-add><?php esc_html_e( 'Add', 'starfiniti-filters' ); ?></button>
				</p>

				<h2><?php esc_html_e( 'Product list', 'starfiniti-filters' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'How more products load', 'starfiniti-filters' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'How more products load', 'starfiniti-filters' ); ?></span></legend>
								<?php foreach ( $modes as $mode => $label ) : ?>
									<label>
										<input type="radio" name="sffilters[pagination]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $pagination, $mode ); ?>>
										<?php echo esc_html( $label ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns one row of the filters table. The script fills the template row, which has no key, when a filter is added.
	 *
	 * @param string $key       Short source name, see Settings::key().
	 * @param string $slug      Filter slug, empty for a new filter.
	 * @param string $label     Title set by the store, empty for the default.
	 * @param string $name      Default title of the source.
	 * @param bool   $attribute Whether the source is a product attribute.
	 */
	private function row( string $key, string $slug, string $label, string $name, bool $attribute ): string {
		return sprintf(
			'<tr data-sffilters-row>'
			. '<td><strong data-sffilters-name>%1$s</strong> <span class="description" data-sffilters-note%11$s>%10$s</span>'
			. '<input type="hidden" name="sffilters[key][]" value="%2$s" data-sffilters-key><input type="hidden" name="sffilters[slug][]" value="%3$s"></td>'
			. '<td><input type="text" class="regular-text" name="sffilters[label][]" value="%4$s" placeholder="%1$s" aria-label="%5$s" data-sffilters-label></td>'
			. '<td><code data-sffilters-param>%6$s</code></td>'
			. '<td class="sffilters-admin__actions">'
			. '<button type="button" class="button button-small" data-sffilters-move="-1" aria-label="%7$s">&uarr;</button> '
			. '<button type="button" class="button button-small" data-sffilters-move="1" aria-label="%8$s">&darr;</button> '
			. '<button type="button" class="button-link button-link-delete" data-sffilters-remove aria-label="%9$s">%12$s</button>'
			. '</td></tr>',
			esc_html( $name ),
			esc_attr( $key ),
			esc_attr( $slug ),
			esc_attr( $label ),
			/* translators: %s: filter name, for example Categories. */
			esc_attr( sprintf( __( 'Title of the %s filter', 'starfiniti-filters' ), $name ) ),
			esc_html( '' !== $slug ? 'sf_' . $slug : '' ),
			/* translators: %s: filter name, for example Categories. */
			esc_attr( sprintf( __( 'Move %s up', 'starfiniti-filters' ), $name ) ),
			/* translators: %s: filter name, for example Categories. */
			esc_attr( sprintf( __( 'Move %s down', 'starfiniti-filters' ), $name ) ),
			/* translators: %s: filter name, for example Categories. */
			esc_attr( sprintf( __( 'Remove %s', 'starfiniti-filters' ), $name ) ),
			esc_html__( 'Attribute', 'starfiniti-filters' ),
			$attribute ? '' : ' hidden',
			esc_html__( 'Remove', 'starfiniti-filters' )
		);
	}
}
