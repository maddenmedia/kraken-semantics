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
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
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

		// Scan button — only when the active provider can actually run.
		if ( ! is_wp_error( $provider ) && $provider->is_configured() ) {
			printf(
				'<p><button type="button" class="button" id="kraken-semantics-scan" data-post="%1$d">%2$s</button>
				<span class="kraken-semantics-scan-status" aria-live="polite"></span></p>',
				(int) $post->ID,
				esc_html__( 'Scan now', 'kraken-semantics' )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Configure a provider under Settings → Kraken Semantics to enable scanning.', 'kraken-semantics' ) . '</p>';
		}

		// Manual override — for editorial teams that score by hand or need
		// to correct a machine score.
		printf(
			'<hr><p><label for="kraken-semantics-manual-score"><strong>%s</strong></label><br>
			<input type="number" id="kraken-semantics-manual-score" name="kraken_semantics_manual_score" min="0" max="100" step="0.1" value="" placeholder="%s" style="width:100%%;"></p>',
			esc_html__( 'Manual score override', 'kraken-semantics' ),
			esc_attr__( 'Leave blank to keep current score', 'kraken-semantics' )
		);

		printf(
			'<p><label><input type="checkbox" name="kraken_semantics_reviewed" value="1" %1$s> %2$s</label></p>',
			checked( $data ? $data['reviewed'] : false, true, false ),
			esc_html__( 'A human has reviewed this score', 'kraken-semantics' )
		);
	}

	/**
	 * Renders the read-only score summary inside the meta box.
	 *
	 * @param array<string,mixed> $data Score record.
	 */
	protected function render_score_summary( array $data ) {
		printf(
			'<div class="kraken-semantics-meta-score kraken-semantics-meta-score--%1$s">
				<span class="kraken-semantics-meta-score__value">%2$s</span>
				<span class="kraken-semantics-meta-score__label">%3$s</span>
			</div>',
			esc_attr( $data['label'] ),
			esc_html( $data['score'] ),
			esc_html( ucfirst( $data['label'] ) )
		);

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
			echo '<p class="description">' . esc_html( $data['summary'] ) . '</p>';
		}

		// Provenance line: who scored it, with what, and when.
		$meta_bits = array_filter(
			array(
				$data['provider'],
				$data['model'],
				$data['scanned_at'] ? mysql2date( get_option( 'date_format' ), $data['scanned_at'] ) : '',
			)
		);

		if ( $meta_bits ) {
			echo '<p class="description kraken-semantics-provenance">' . esc_html( implode( ' · ', $meta_bits ) ) . '</p>';
		}
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
			// A manually entered score is by definition human-reviewed.
			$updates['reviewed'] = true;
		}

		// The reviewed checkbox is always present in the form, so its absence
		// in $_POST is a genuine "unchecked" — but only meaningful when the
		// post already has a score.
		if ( empty( $updates ) && null !== Kraken_Semantics_Scores::get( $post_id ) ) {
			$updates['reviewed'] = ! empty( $_POST['kraken_semantics_reviewed'] );
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
