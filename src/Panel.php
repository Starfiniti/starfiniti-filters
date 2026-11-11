<?php
/**
 * The filter panel.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the panel as a plain GET form, so it works without JavaScript.
 *
 * Place it with the `[sffilters]` shortcode.
 */
final class Panel {

	/**
	 * @var array<string, string>
	 */
	private array $ids_sql = array();

	public function register(): void {
		add_shortcode( 'sffilters', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_register_style( 'sffilters', SFFILTERS_PLUGIN_URL . 'assets/filters.css', array(), SFFILTERS_VERSION );
		wp_register_script(
			'sffilters',
			SFFILTERS_PLUGIN_URL . 'assets/filters.js',
			array(),
			SFFILTERS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		/**
		 * Filters the selectors the script uses to find the product list and its pagination links.
		 *
		 * @param array $config Script settings.
		 */
		$config = apply_filters(
			'sffilters_script_config',
			array(
				'results'    => '[data-sffilters-results]',
				'pagination' => '.woocommerce-pagination a',
			)
		);

		wp_add_inline_script( 'sffilters', 'window.sffiltersConfig = ' . wp_json_encode( $config ) . ';', 'before' );

		// The panel usually sits in a sidebar or template. Load the styles in the head when a product list is on the page.
		if ( null !== Plugin::instance()->base_query_vars() ) {
			wp_enqueue_style( 'sffilters' );
		}
	}

	/**
	 * Returns the panel, or an empty string when the page has no product list to filter.
	 */
	public function render(): string {
		$plugin = Plugin::instance();
		$base   = $plugin->base_query_vars();

		if ( null === $base ) {
			return '';
		}

		$filters = $plugin->filters();
		$state   = $plugin->state();
		$groups  = '';

		foreach ( $filters as $slug => $filter ) {
			$scope = $state->without( $slug );
			$key   = md5( (string) wp_json_encode( $scope->all() ) );

			// Filters that are not in use all choose from the same products, so the SQL is built once.
			if ( ! isset( $this->ids_sql[ $key ] ) ) {
				$this->ids_sql[ $key ] = Query::ids_sql( Query::apply( $base, $filters, $scope ) );
			}

			if ( '' !== $this->ids_sql[ $key ] ) {
				$groups .= $this->group( $filter, $filter->facet( $this->ids_sql[ $key ], $state->values( $slug ) ) );
			}
		}

		if ( '' === $groups ) {
			return '';
		}

		wp_enqueue_style( 'sffilters' );
		wp_enqueue_script( 'sffilters' );

		$url    = $this->base_url( $filters );
		$parts  = explode( '?', $url, 2 );
		$hidden = '';

		if ( isset( $parts[1] ) ) {
			wp_parse_str( $parts[1], $kept );
			$hidden = wc_query_string_form_fields( $kept, array(), '', true );
		}

		$clear = $state->is_active()
			? sprintf( '<a class="sffilters-clear" href="%s" rel="nofollow" data-sffilters-clear>%s</a>', esc_url( $url ), esc_html__( 'Clear all', 'starfiniti-filters' ) )
			: '';

		return sprintf(
			'<form class="sffilters-panel" method="get" action="%1$s" aria-label="%2$s" data-sffilters-panel>%3$s%4$s<noscript><button type="submit" class="sffilters-apply button">%5$s</button></noscript>%6$s</form>',
			esc_url( $parts[0] ),
			esc_attr__( 'Product filters', 'starfiniti-filters' ),
			$hidden, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wc_query_string_form_fields().
			$groups, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
			esc_html__( 'Apply filters', 'starfiniti-filters' ),
			$clear // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
	}

	/**
	 * Returns the address of this product list on page one with no filters.
	 *
	 * @param array<string, Filter> $filters Filters keyed by slug.
	 */
	private function base_url( array $filters ): string {
		$params = array();

		foreach ( $filters as $filter ) {
			$params[] = $filter->param();
		}

		return remove_query_arg( $params, get_pagenum_link( 1, false ) );
	}

	/**
	 * Renders one filter. An empty facet renders nothing.
	 */
	private function group( Filter $filter, array $facet ): string {
		$param = $filter->param();
		$kind  = $facet['kind'] ?? '';

		if ( 'toggle' === $kind ) {
			return sprintf(
				'<div class="sffilters-filter sffilters-filter--toggle" data-sffilters-param="%s"><div class="sffilters-option">%s</div></div>',
				esc_attr( $param ),
				$this->checkbox( $param, '1', $filter->label(), (int) $facet['count'], (bool) $facet['selected'] )
			);
		}

		if ( 'options' === $kind ) {
			$body = $this->options( $param . '[]', $facet['options'] );
		} elseif ( 'range' === $kind ) {
			$body = $this->range( $param, $facet );
		} else {
			return '';
		}

		return sprintf(
			'<fieldset class="sffilters-filter sffilters-filter--%1$s" data-sffilters-param="%2$s"%3$s><legend class="sffilters-filter__title">%4$s</legend>%5$s</fieldset>',
			esc_attr( $kind ),
			esc_attr( $param ),
			'range' === $kind ? ' data-sffilters-range' : '',
			esc_html( $filter->label() ),
			$body
		);
	}

	/**
	 * Renders a list of checkboxes, nested for child options.
	 */
	private function options( string $name, array $options ): string {
		$items = '';

		foreach ( $options as $option ) {
			$items .= sprintf(
				'<li class="sffilters-option">%s%s</li>',
				$this->checkbox( $name, (string) $option['value'], (string) $option['label'], (int) $option['count'], (bool) $option['selected'] ),
				empty( $option['children'] ) ? '' : $this->options( $name, $option['children'] )
			);
		}

		return '<ul class="sffilters-options">' . $items . '</ul>';
	}

	private function checkbox( string $name, string $value, string $label, int $count, bool $selected ): string {
		return sprintf(
			'<label class="sffilters-option__control"><input type="checkbox" name="%1$s" value="%2$s"%3$s> <span class="sffilters-option__label">%4$s</span> <span class="sffilters-option__count">%5$s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $selected, true, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Renders the two price inputs. The placeholders show the lowest and highest price on offer.
	 */
	private function range( string $param, array $facet ): string {
		$inputs = '';
		$ends   = array(
			'min' => array( __( 'Lowest price', 'starfiniti-filters' ), $facet['from'], $facet['min'] ),
			'max' => array( __( 'Highest price', 'starfiniti-filters' ), $facet['to'], $facet['max'] ),
		);

		foreach ( $ends as $end => list( $label, $value, $limit ) ) {
			$inputs .= sprintf(
				'<label class="sffilters-range__end"><span class="screen-reader-text">%1$s</span><input type="number" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" min="0" step="any" inputmode="decimal" data-sffilters-%3$s></label>',
				esc_html( $label ),
				esc_attr( $param ),
				esc_attr( $end ),
				esc_attr( null === $value ? '' : (string) (float) $value ),
				esc_attr( (string) (float) $limit )
			);
		}

		return sprintf(
			'<div class="sffilters-range">%s<span class="sffilters-range__unit">%s</span></div>',
			$inputs,
			esc_html( get_woocommerce_currency_symbol() )
		);
	}
}
