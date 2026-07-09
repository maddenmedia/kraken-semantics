<?php
/**
 * Admin UI: the editor meta box and the posts-list column.
 *
 * The meta box shows the current score, lets an editor run a scan without
 * leaving the editor (via the plugin's own REST route), and offers a manual
 * override for sites that score by hand.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up all wp-admin interface pieces.
 */
class Kraken_Semantics_Admin {

	/** Nonce action/name for the meta box manual-override form. */
	const NONCE = 'kraken_semantics_meta_box';

	/**
	 * Scanner, used to check provider configuration for the Scan button.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * Hooks the admin pieces.
	 *
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// List-table column for every enabled post type. Hooked on init via
		// closure because the filter names embed the post type slug.
		add_action( 'init', array( $this, 'register_columns' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'handle_column_sorting' ) );
	}

	/**
	 * Registers the meta box on every enabled post type.
	 */
	public function add_meta_box() {
		add_meta_box(
			'kraken-semantics',
			__( 'Semantic Confidence', 'kraken-semantics' ),
			array( $this, 'render_meta_box' ),
			kraken_semantics_get_post_types(),
			'side'
		);
	}

	/**
	 * Loads admin CSS/JS on edit screens of enabled post types.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// edit.php is the list table, where the score column renders.
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, kraken_semantics_get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'kraken-semantics-admin',
			KRAKEN_SEMANTICS_URL . 'assets/css/admin.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);

		wp_enqueue_script(
			'kraken-semantics-admin',
			KRAKEN_SEMANTICS_URL . 'assets/js/admin.js',
			array(),
			KRAKEN_SEMANTICS_VERSION,
			true
		);

		// Everything the scan button needs to call the REST route.
		wp_localize_script(
			'kraken-semantics-admin',
			'krakenSemantics',
			array(
				'restBase' => esc_url_raw( rest_url( Kraken_Semantics_Rest_Api::API_NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'scanning' => __( 'Scanning…', 'kraken-semantics' ),
					'scanned'  => __( 'Scan complete — reloading…', 'kraken-semantics' ),
					'failed'   => __( 'Scan failed:', 'kraken-semantics' ),
				),
			)
		);
	}

	/**
	 * Renders the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$data     = Kraken_Semantics_Scores::get( $post->ID );
		$provider = $this->scanner->active_provider();

		wp_nonce_field( self::NONCE, self::NONCE );

		if ( $data ) {
			$this->render_score_summary( $data );
		} else {
			echo '<p>' . esc_html__( 'This content has not been scored yet.', 'kraken-semantics' ) . '</p>';
		}

		// When parallel scoring has run, show every provider's score side by side.
		$this->render_provider_comparison( $post->ID );

		// Scan button — only when the active provider can actually run.
		if ( ! is_wp_error( $provider ) && $provider->is_configured() ) {
			printf(
				'<p><button type="button" class="button" id="kraken-semantics-scan" data-post="%1$d">%2$s</button>
				<span class="kraken-semantics-scan-status" aria-live="polite"></span></p>',
				(int) $post->ID,
				esc_html__( 'Scan now', 'kraken-semantics' )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Configure a provider under Kraken Semantics → Settings to enable scanning.', 'kraken-semantics' ) . '</p>';
		}

		// Manual override — for editorial teams that score by hand or need
		// to correct a machine score.
		printf(
			'<hr><p><label for="kraken-semantics-manual-score"><strong>%s</strong></label><br>
			<input type="number" id="kraken-semantics-manual-score" name="kraken_semantics_manual_score" min="0" max="100" step="0.1" value="" placeholder="%s" style="width:100%%;"></p>',
			esc_html__( 'Manual score override', 'kraken-semantics' ),
			esc_attr__( 'Leave blank to keep current score', 'kraken-semantics' )
		);
	}

	/**
	 * Renders the read-only score summary inside the meta box.
	 *
	 * @param array<string,mixed> $data Score record.
	 */
	protected function render_score_summary( array $data ) {
		echo '<div class="kraken-semantics-meta-head">';
		$this->render_gauge( (float) $data['score'], $data['label'] );

		echo '<div class="kraken-semantics-meta-head__side">';
		printf(
			'<span class="kraken-semantics-band kraken-semantics-band--%1$s"><i></i>%2$s</span>',
			esc_attr( $data['label'] ),
			esc_html( ucfirst( $data['label'] ) )
		);

		// The rewrite loop, surfaced: change since the previous scan.
		if ( null !== $data['delta'] && 0.0 !== (float) $data['delta'] ) {
			$up = $data['delta'] > 0;
			printf(
				'<span class="kraken-semantics-delta kraken-semantics-delta--%1$s">%2$s %3$s</span>',
				$up ? 'up' : 'down',
				$up ? '&#9650;' : '&#9660;',
				esc_html(
					sprintf(
						/* translators: %s: signed score change. */
						__( '%s vs last scan', 'kraken-semantics' ),
						( $up ? '+' : '−' ) . number_format_i18n( abs( $data['delta'] ), 1 )
					)
				)
			);
		}

		if ( count( $data['history'] ) >= 2 ) {
			$this->render_sparkline( $data['history'] );
		}
		echo '</div>';
		echo '</div>';

		// One bar per breakdown dimension.
		if ( ! empty( $data['breakdown'] ) ) {
			echo '<ul class="kraken-semantics-breakdown">';
			foreach ( $data['breakdown'] as $dimension => $value ) {
				printf(
					'<li>
						<span class="kraken-semantics-breakdown__name">%1$s</span>
						<span class="kraken-semantics-breakdown__bar"><span style="width:%2$d%%"></span></span>
						<span class="kraken-semantics-breakdown__value">%2$d</span>
					</li>',
					esc_html( ucwords( str_replace( '_', ' ', $dimension ) ) ),
					(int) $value
				);
			}
			echo '</ul>';
		}

		if ( $data['summary'] ) {
			echo '<p class="description kraken-semantics-summary">' . esc_html( $data['summary'] ) . '</p>';
		}

		// Provenance chips: who scored it, with what, and when.
		$meta_bits = array_filter(
			array(
				$data['provider'],
				$data['model'],
				$data['scanned_at'] ? mysql2date( get_option( 'date_format' ), $data['scanned_at'] ) : '',
			)
		);

		if ( $meta_bits ) {
			echo '<p class="kraken-semantics-provenance">';
			foreach ( $meta_bits as $bit ) {
				echo '<span class="kraken-semantics-provenance__chip">' . esc_html( $bit ) . '</span>';
			}
			echo '</p>';
		}
	}

	/**
	 * Renders the per-provider score comparison (parallel scoring).
	 *
	 * Only shown once at least two providers have scored the post. Reuses the
	 * band chip styling from the summary; layout is inline so it needs no extra
	 * stylesheet.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function render_provider_comparison( $post_id ) {
		$results = Kraken_Semantics_Scores::results( $post_id );

		// Nothing to compare until more than one provider has scored.
		if ( count( $results ) < 2 ) {
			return;
		}

		$providers = $this->scanner->providers();

		echo '<div class="kraken-semantics-compare" style="margin-top:12px;border-top:1px solid #e2e4e7;padding-top:10px;">';
		echo '<p style="margin:0 0 6px;font-weight:600;">' . esc_html__( 'Provider comparison', 'kraken-semantics' ) . '</p>';

		foreach ( $results as $slug => $entry ) {
			$label = isset( $providers[ $slug ] ) ? $providers[ $slug ]->get_label() : $slug;
			$score = isset( $entry['score'] ) ? (float) $entry['score'] : 0.0;
			$band  = isset( $entry['label'] ) ? (string) $entry['label'] : Kraken_Semantics_Scores::label_for( $score );

			$score_text = ( floor( $score ) === $score )
				? number_format_i18n( $score )
				: number_format_i18n( $score, 1 );

			printf(
				'<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin:4px 0;">
					<span>%1$s</span>
					<span style="display:flex;align-items:center;gap:6px;">
						<span class="kraken-semantics-band kraken-semantics-band--%2$s"><i></i>%3$s</span>
						<strong>%4$s</strong>
					</span>
				</div>',
				esc_html( $label ),
				esc_attr( $band ),
				esc_html( ucfirst( $band ) ),
				esc_html( $score_text )
			);
		}

		echo '</div>';
	}

	/**
	 * SVG ring gauge for the meta box headline score.
	 *
	 * @param float  $score Score, 0–100.
	 * @param string $band  Band slug ('high', 'medium', 'low').
	 */
	protected function render_gauge( $score, $band ) {
		$radius        = 30;
		$circumference = 2 * M_PI * $radius;
		$arc           = $circumference * min( 100, max( 0, $score ) ) / 100;

		// Show "87" rather than "87.0", but keep a real decimal like "87.5".
		$score_text = ( floor( $score ) === $score )
			? number_format_i18n( $score )
			: number_format_i18n( $score, 1 );

		printf(
			'<svg class="kraken-semantics-gauge kraken-semantics-gauge--%1$s" viewBox="0 0 76 76" role="img" aria-label="%2$s">
				<circle class="kraken-semantics-gauge__track" cx="38" cy="38" r="%3$d"/>
				<circle class="kraken-semantics-gauge__arc" cx="38" cy="38" r="%3$d"
					stroke-dasharray="%4$.2f %5$.2f" transform="rotate(-90 38 38)"/>
				<text class="kraken-semantics-gauge__value" x="38" y="40">%6$s</text>
			</svg>',
			esc_attr( $band ),
			/* translators: %s: score. */
			esc_attr( sprintf( __( 'Semantic confidence score: %s out of 100', 'kraken-semantics' ), $score_text ) ),
			(int) $radius,
			(float) $arc,
			(float) ( $circumference - $arc ),
			esc_html( $score_text )
		);
	}

	/**
	 * Tiny score-history sparkline: the post's improvement at a glance.
	 *
	 * @param array<int,array<string,mixed>> $history Score events, oldest first.
	 */
	protected function render_sparkline( array $history ) {
		$history = array_slice( $history, -12 );
		$n       = count( $history );
		$width   = 110;
		$height  = 30;
		$pad     = 4;

		$points = array();
		foreach ( $history as $i => $event ) {
			$x        = $pad + ( $width - 2 * $pad ) * ( $n > 1 ? $i / ( $n - 1 ) : 0.5 );
			$y        = $pad + ( $height - 2 * $pad ) * ( 1 - min( 100, max( 0, (float) $event['score'] ) ) / 100 );
			$points[] = sprintf( '%.1f,%.1f', $x, $y );
		}

		$last = explode( ',', end( $points ) );

		printf(
			'<svg class="kraken-semantics-spark" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">
				<polyline points="%4$s"/>
				<circle cx="%5$s" cy="%6$s" r="2.5"/>
			</svg>',
			(int) $width,
			(int) $height,
			/* translators: %d: number of scans. */
			esc_attr( sprintf( __( 'Score history across %d scans', 'kraken-semantics' ), $n ) ),
			esc_attr( implode( ' ', $points ) ),
			esc_attr( $last[0] ),
			esc_attr( $last[1] )
		);
	}

	/**
	 * Persists the meta box's manual-override fields.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save_meta_box( $post_id ) {
		// Standard save_post guards: nonce, autosave, capability.
		if (
			! isset( $_POST[ self::NONCE ] )
			|| ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$updates = array();

		// Manual score: only applied when the field was actually filled in.
		if ( isset( $_POST['kraken_semantics_manual_score'] ) && '' !== $_POST['kraken_semantics_manual_score'] ) {
			$updates['score']    = (float) $_POST['kraken_semantics_manual_score'];
			$updates['provider'] = 'manual';
			$updates['model']    = '';
		}

		if ( $updates ) {
			Kraken_Semantics_Scores::save( $post_id, $updates );
		}
	}

	/**
	 * Adds the score column to the list table of each enabled post type.
	 */
	public function register_columns() {
		foreach ( kraken_semantics_get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'make_column_sortable' ) );
		}
	}

	/**
	 * Declares the column.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string> Columns including ours.
	 */
	public function add_column( $columns ) {
		$columns['kraken_semantics'] = __( 'AI Confidence', 'kraken-semantics' );

		return $columns;
	}

	/**
	 * Renders a cell in the score column.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Row's post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'kraken_semantics' !== $column ) {
			return;
		}

		$data = Kraken_Semantics_Scores::get( $post_id );

		if ( null === $data ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		printf(
			'<span class="kraken-semantics-pill kraken-semantics-pill--%1$s" title="%3$s">%2$s</span>',
			esc_attr( $data['label'] ),
			esc_html( $data['score'] ),
			esc_attr( $data['summary'] )
		);

		// Direction of travel since the previous scan, when there is one.
		if ( null !== $data['delta'] && 0.0 !== (float) $data['delta'] ) {
			$up = $data['delta'] > 0;
			printf(
				'<span class="kraken-semantics-colDelta kraken-semantics-colDelta--%1$s" title="%3$s">%2$s</span>',
				$up ? 'up' : 'down',
				$up ? '&#9650;' : '&#9660;',
				esc_attr(
					sprintf(
						/* translators: %s: signed score change. */
						__( '%s since the previous scan', 'kraken-semantics' ),
						( $up ? '+' : '' ) . $data['delta']
					)
				)
			);
		}
	}

	/**
	 * Marks the column sortable.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function make_column_sortable( $columns ) {
		$columns['kraken_semantics'] = 'kraken_semantics_score';

		return $columns;
	}

	/**
	 * Translates the sortable column into a meta query.
	 *
	 * Note: sorting by a meta value hides posts without that meta — an
	 * accepted WordPress trade-off; unscored posts reappear when the sort
	 * is cleared.
	 *
	 * @param WP_Query $query Current admin query.
	 */
	public function handle_column_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'kraken_semantics_score' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Kraken_Semantics_Scores::META_SCORE );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}
}
