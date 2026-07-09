<?php
/**
 * Optional integration with the Kraken Hub, the shared admin landing page
 * provided by the Kraken Core plugin (github.com/maddenmedia/kraken-core).
 *
 * Kraken Semantics has no hard dependency on Kraken Core and works fully
 * standalone. Every hook registered here targets a filter/action that Kraken
 * Core's Hub page fires while rendering itself — on a site without Kraken
 * Core, that page never renders, so these callbacks simply never run. There
 * is deliberately no is_plugin_active() check: the hooks are the guard.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contributes a quick-link card and a stats widget to the Kraken Hub.
 */
class Kraken_Semantics_Hub_Integration {

	/** Kraken Hub's top-level menu slug (from Kraken Core), for asset targeting. */
	const HUB_MENU_HOOK_SUFFIX = 'toplevel_page_kraken-dashboard';

	/**
	 * Dashboard, source of the aggregated stats shown in the widget.
	 *
	 * @var Kraken_Semantics_Dashboard
	 */
	protected $dashboard;

	/**
	 * Hooks the Kraken Hub extension points.
	 *
	 * @param Kraken_Semantics_Dashboard $dashboard Shared dashboard instance.
	 */
	public function __construct( Kraken_Semantics_Dashboard $dashboard ) {
		$this->dashboard = $dashboard;

		add_filter( 'kraken-core/hub/quick_links', array( $this, 'add_quick_link' ) );
		add_action( 'kraken-core/hub/dashboard_widgets', array( $this, 'render_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the Kraken Semantics card to the Hub's quick-links grid.
	 *
	 * @param array<int,array<string,mixed>> $links Existing cards.
	 * @return array<int,array<string,mixed>> Cards including ours.
	 */
	public function add_quick_link( $links ) {
		$links   = is_array( $links ) ? $links : array();
		$links[] = array(
			'label'       => __( 'Kraken Semantics', 'kraken-semantics' ),
			'description' => __( 'Score how confidently AI can trust your content, then rewrite and watch it improve.', 'kraken-semantics' ),
			'buttons'     => array(
				array(
					'label' => __( 'Open Dashboard', 'kraken-semantics' ),
					'url'   => admin_url( 'admin.php?page=' . Kraken_Semantics_Dashboard::MENU_SLUG ),
					'style' => 'primary',
				),
				array(
					'label' => __( 'Open Settings', 'kraken-semantics' ),
					'url'   => admin_url( 'admin.php?page=' . Kraken_Semantics_Settings::PAGE ),
					'style' => 'secondary',
				),
			),
		);

		return $links;
	}

	/**
	 * Renders the stats widget above the Hub's quick-link cards.
	 *
	 * Skipped entirely until at least one post has been scored — an empty
	 * ring/meter would just be noise on a landing page shared with other
	 * Kraken plugins.
	 */
	public function render_widget() {
		$stats = $this->dashboard->get_stats();

		if ( 0 === $stats['scored'] ) {
			return;
		}

		$average = $stats['average'];
		$band    = Kraken_Semantics_Scores::label_for( $average );
		$pct     = $stats['total_posts'] ? round( 100 * $stats['scored'] / $stats['total_posts'] ) : 0;

		echo '<div class="kraken-dash kraken-hubwidget">';
		echo '<div class="kraken-card">';

		echo '<div class="kraken-hubwidget__head">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Kraken Semantics', 'kraken-semantics' ) . '</h2>';
		printf(
			'<a class="kraken-hubwidget__link" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Kraken_Semantics_Dashboard::MENU_SLUG ) ),
			esc_html__( 'View full dashboard →', 'kraken-semantics' )
		);
		echo '</div>';

		echo '<div class="kraken-hubwidget__row">';

		echo '<div class="kraken-tile kraken-tile--ring kraken-hubwidget__tile">';
		$this->dashboard->render_ring( $average, $band );
		echo '<div class="kraken-tile__ringside">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Average score', 'kraken-semantics' ) . '</span>';
		printf(
			'<span class="kraken-band kraken-band--%1$s"><i></i>%2$s</span>',
			esc_attr( $band ),
			esc_html( ucfirst( $band ) )
		);
		echo '</div>';
		echo '</div>';

		echo '<div class="kraken-hubwidget__tile">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Coverage', 'kraken-semantics' ) . '</span>';
		printf( '<span class="kraken-tile__value">%s<small>%%</small></span>', esc_html( number_format_i18n( $pct ) ) );
		printf( '<span class="kraken-meter"><span style="width:%d%%"></span></span>', (int) $pct );
		printf(
			'<span class="kraken-tile__hint">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: scored count, 2: total count. */
					__( '%1$s of %2$s posts scored', 'kraken-semantics' ),
					number_format_i18n( $stats['scored'] ),
					number_format_i18n( $stats['total_posts'] )
				)
			)
		);
		echo '</div>';

		echo '</div>'; // .kraken-hubwidget__row
		echo '</div>'; // .kraken-card
		echo '</div>'; // .kraken-dash
	}

	/**
	 * Loads the dashboard stylesheet on the Kraken Hub screen too, since the
	 * widget reuses its tile/ring/band/meter styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( self::HUB_MENU_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'kraken-semantics-dashboard',
			KRAKEN_SEMANTICS_URL . 'assets/css/dashboard.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);
	}
}
