<?php
/**
 * Admin dashboard: the plugin's insight surface.
 *
 * One screen that answers, at a glance: how confidently can AI trust this
 * site's content, where is it weakest, and is it getting better? Charts are
 * server-rendered SVG — no charting library, no build step.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and renders the dashboard page.
 */
class Kraken_Semantics_Dashboard {

	/** Top-level menu slug (the settings screen hangs off this as a submenu). */
	const MENU_SLUG = 'kraken-semantics';

	/** Transient key for the aggregated stats. */
	const STATS_CACHE = 'kraken_semantics_stats';

	/**
	 * Scanner, used to surface provider configuration state.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * Hooks the menu, assets, and cache invalidation.
	 *
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;

		// Priority 9 so the Settings screen can attach its submenu at 10.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// New scores make the cached aggregates stale.
		add_action( 'kraken_semantics_post_scanned', array( $this, 'flush_stats_cache' ) );
		add_action( 'save_post', array( $this, 'flush_stats_cache' ) );
	}

	/**
	 * Adds the top-level menu with the dashboard as its landing page.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Kraken Semantics', 'kraken-semantics' ),
			__( 'Kraken Semantics', 'kraken-semantics' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			KRAKEN_SEMANTICS_URL . 'assets/images/dashicon.svg',
			81 // Matches the other Kraken plugins' shared position, directly below ACF.
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'kraken-semantics' ),
			__( 'Dashboard', 'kraken-semantics' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads dashboard styles/scripts on the dashboard screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'kraken-semantics-dashboard',
			KRAKEN_SEMANTICS_URL . 'assets/css/dashboard.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);

		wp_enqueue_script(
			'kraken-semantics-dashboard',
			KRAKEN_SEMANTICS_URL . 'assets/js/dashboard.js',
			array(),
			KRAKEN_SEMANTICS_VERSION,
			true
		);
	}

	/**
	 * Drops the cached aggregates so the next dashboard view recomputes.
	 */
	public function flush_stats_cache() {
		delete_transient( self::STATS_CACHE );
	}

	/* ---------------------------------------------------------------------
	 * Data layer
	 * ------------------------------------------------------------------ */

	/**
	 * Aggregates every number the dashboard needs, with a short cache.
	 *
	 * One pass over the scored posts: band counts, histogram buckets,
	 * dimension averages, weekly trend, biggest improvements, and the
	 * lowest-scoring posts.
	 *
	 * @return array<string,mixed> Aggregated stats.
	 */
	public function get_stats() {
		$cached = get_transient( self::STATS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$post_types = kraken_semantics_get_post_types();
		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		}

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Total published posts of the enabled types (scored or not).
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type IN ($type_placeholders) AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_types
			)
		);

		// Every scored post with the meta the aggregates need, in one query.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
				        score.meta_value     AS score,
				        scanned.meta_value   AS scanned_at,
				        reviewed.meta_value  AS reviewed,
				        breakdown.meta_value AS breakdown,
				        history.meta_value   AS history
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} score
				         ON score.post_id = p.ID AND score.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} scanned
				        ON scanned.post_id = p.ID AND scanned.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} reviewed
				        ON reviewed.post_id = p.ID AND reviewed.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} breakdown
				        ON breakdown.post_id = p.ID AND breakdown.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} history
				        ON history.post_id = p.ID AND history.meta_key = %s
				 WHERE p.post_type IN ($type_placeholders) AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge(
					array(
						Kraken_Semantics_Scores::META_SCORE,
						Kraken_Semantics_Scores::META_SCANNED_AT,
						Kraken_Semantics_Scores::META_REVIEWED,
						Kraken_Semantics_Scores::META_BREAKDOWN,
						Kraken_Semantics_Scores::META_HISTORY,
					),
					$post_types
				)
			)
		);

		$stats = array(
			'total_posts'    => $total,
			'scored'         => 0,
			'average'        => null,
			'bands'          => array( 'high' => 0, 'medium' => 0, 'low' => 0 ),
			'reviewed'       => 0,
			'histogram'      => array_fill( 0, 10, 0 ),
			'dimensions'     => array(), // slug => [sum, count]
			'trend'          => array(), // week start (Y-m-d) => [sum, count]
			'net_change_30d' => null,
			'needs_attention' => array(),
			'improvements'   => array(),
		);

		$sum         = 0.0;
		$deltas_30d  = array();
		$monday      = strtotime( 'monday this week', time() );
		$trend_start = $monday - 11 * WEEK_IN_SECONDS; // 12 weeks incl. current.
		$posts       = array();

		foreach ( $rows as $row ) {
			$score = (float) $row->score;
			$label = Kraken_Semantics_Scores::label_for( $score );

			$stats['scored']++;
			$sum += $score;
			$stats['bands'][ $label ]++;
			$stats['histogram'][ min( 9, (int) floor( $score / 10 ) ) ]++;

			if ( $row->reviewed ) {
				$stats['reviewed']++;
			}

			$breakdown = maybe_unserialize( (string) $row->breakdown );
			if ( is_array( $breakdown ) ) {
				foreach ( $breakdown as $dimension => $value ) {
					if ( ! isset( $stats['dimensions'][ $dimension ] ) ) {
						$stats['dimensions'][ $dimension ] = array( 0.0, 0 );
					}
					$stats['dimensions'][ $dimension ][0] += (float) $value;
					$stats['dimensions'][ $dimension ][1]++;
				}
			}

			// History feeds the trend line and the improvement deltas.
			$history = maybe_unserialize( (string) $row->history );
			$history = is_array( $history ) ? array_values( $history ) : array();
			$delta   = null;

			foreach ( $history as $event ) {
				$time = isset( $event['scanned_at'] ) ? strtotime( (string) $event['scanned_at'] ) : false;
				if ( ! $time || $time < $trend_start ) {
					continue;
				}
				$week = gmdate( 'Y-m-d', strtotime( 'monday this week', $time ) );
				if ( ! isset( $stats['trend'][ $week ] ) ) {
					$stats['trend'][ $week ] = array( 0.0, 0 );
				}
				$stats['trend'][ $week ][0] += (float) $event['score'];
				$stats['trend'][ $week ][1]++;
			}

			$events = count( $history );
			if ( $events >= 2 ) {
				$delta = round( $score - (float) $history[ $events - 2 ]['score'], 1 );

				$last_time = strtotime( (string) $history[ $events - 1 ]['scanned_at'] );
				if ( $last_time && $last_time >= time() - 30 * DAY_IN_SECONDS ) {
					$deltas_30d[] = $delta;
				}
			}

			$posts[] = array(
				'id'         => (int) $row->ID,
				'title'      => $row->post_title,
				'score'      => $score,
				'label'      => $label,
				'delta'      => $delta,
				'scanned_at' => (string) $row->scanned_at,
			);
		}

		if ( $stats['scored'] > 0 ) {
			$stats['average'] = round( $sum / $stats['scored'], 1 );
		}

		if ( $deltas_30d ) {
			$stats['net_change_30d'] = round( array_sum( $deltas_30d ) / count( $deltas_30d ), 1 );
		}

		// Finalize per-dimension averages.
		foreach ( $stats['dimensions'] as $dimension => $acc ) {
			$stats['dimensions'][ $dimension ] = $acc[1] ? round( $acc[0] / $acc[1], 1 ) : 0;
		}

		// Lowest scores first: these are the rewrite candidates.
		usort(
			$posts,
			function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		$stats['needs_attention'] = array_slice( $posts, 0, 6 );

		// Biggest positive deltas: the rewrite loop paying off.
		$improved = array_filter(
			$posts,
			function ( $post ) {
				return null !== $post['delta'] && $post['delta'] > 0;
			}
		);
		usort(
			$improved,
			function ( $a, $b ) {
				return $b['delta'] <=> $a['delta'];
			}
		);
		$stats['improvements'] = array_slice( $improved, 0, 6 );

		ksort( $stats['trend'] );

		set_transient( self::STATS_CACHE, $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the dashboard.
	 */
	public function render_page() {
		$stats    = $this->get_stats();
		$settings = kraken_semantics_get_settings();

		echo '<div class="wrap kraken-dash">';

		$this->render_header( $stats );

		if ( 0 === $stats['scored'] ) {
			$this->render_empty_state();
			echo '</div>';
			return;
		}

		$this->render_stat_tiles( $stats );

		echo '<div class="kraken-dash__grid">';

		echo '<div class="kraken-card kraken-card--span2">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Score distribution', 'kraken-semantics' ) . '</h2>';
		echo '<p class="kraken-card__sub">' . esc_html__( 'Where your published content lands on the 0–100 confidence scale.', 'kraken-semantics' ) . '</p>';
		$this->render_histogram( $stats['histogram'], (int) $settings['threshold_low'], (int) $settings['threshold_high'] );
		echo '</div>';

		echo '<div class="kraken-card kraken-card--span2">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Average score over time', 'kraken-semantics' ) . '</h2>';
		echo '<p class="kraken-card__sub">' . esc_html__( 'Weekly average of every scan in the last 12 weeks — rescan after rewriting to see the line move.', 'kraken-semantics' ) . '</p>';
		$this->render_trend( $stats['trend'] );
		echo '</div>';

		echo '<div class="kraken-card kraken-card--span2">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Confidence by dimension', 'kraken-semantics' ) . '</h2>';
		echo '<p class="kraken-card__sub">' . esc_html__( 'Site-wide averages. The lowest bar is where rewrites will move the needle most.', 'kraken-semantics' ) . '</p>';
		$this->render_dimensions( $stats['dimensions'] );
		echo '</div>';

		echo '<div class="kraken-card">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Needs attention', 'kraken-semantics' ) . '</h2>';
		echo '<p class="kraken-card__sub">' . esc_html__( 'Your lowest-scoring content — the best candidates for a rewrite.', 'kraken-semantics' ) . '</p>';
		$this->render_post_list( $stats['needs_attention'], 'attention' );
		echo '</div>';

		echo '<div class="kraken-card">';
		echo '<h2 class="kraken-card__title">' . esc_html__( 'Biggest improvements', 'kraken-semantics' ) . '</h2>';
		echo '<p class="kraken-card__sub">' . esc_html__( 'Rewrites that raised the score since the previous scan.', 'kraken-semantics' ) . '</p>';
		$this->render_post_list( $stats['improvements'], 'improvements' );
		echo '</div>';

		echo '</div>'; // .kraken-dash__grid

		echo '<div class="kraken-dash__tooltip" role="tooltip" aria-hidden="true"></div>';
		echo '</div>'; // .wrap
	}

	/**
	 * The header band: wordmark, framing line, and quick links.
	 *
	 * @param array<string,mixed> $stats Aggregated stats.
	 */
	protected function render_header( $stats ) {
		$provider       = $this->scanner->active_provider();
		$provider_ready = ! is_wp_error( $provider ) && $provider->is_configured();

		echo '<div class="kraken-hero">';
		echo '<div class="kraken-hero__text">';
		echo '<h1>' . esc_html__( 'Kraken Semantics', 'kraken-semantics' ) . '</h1>';
		echo '<p>' . esc_html__( 'How confidently can AI trust your content? Score it, rewrite it, watch it improve.', 'kraken-semantics' ) . '</p>';
		echo '</div>';

		echo '<div class="kraken-hero__actions">';
		if ( ! $provider_ready ) {
			echo '<span class="kraken-hero__notice">' . esc_html__( 'No scan provider configured — scoring via MCP still works.', 'kraken-semantics' ) . '</span>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			printf(
				'<a class="kraken-hero__button" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Kraken_Semantics_Settings::PAGE ) ),
				esc_html__( 'Settings', 'kraken-semantics' )
			);
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Friendly first-run state: nothing has been scored yet.
	 */
	protected function render_empty_state() {
		echo '<div class="kraken-card kraken-empty">';
		echo '<h2>' . esc_html__( 'No content has been scored yet', 'kraken-semantics' ) . '</h2>';
		echo '<p>' . esc_html__( 'Three ways to get your first scores:', 'kraken-semantics' ) . '</p>';
		echo '<ol>';
		echo '<li>' . wp_kses_post( __( 'Open any post and press <strong>Scan now</strong> in the Semantic Confidence box (needs an API key in Settings).', 'kraken-semantics' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Score locally with Claude Code — run <code>/score-posts</code> with the bundled MCP server. No API key on the server needed; see <code>mcp/README.md</code>.', 'kraken-semantics' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Bulk scan from the command line: <code>wp kraken-semantics scan --all</code>.', 'kraken-semantics' ) ) . '</li>';
		echo '</ol>';
		echo '</div>';
	}

	/**
	 * The KPI row.
	 *
	 * @param array<string,mixed> $stats Aggregated stats.
	 */
	protected function render_stat_tiles( $stats ) {
		echo '<div class="kraken-tiles">';

		// Tile 1: average score as a ring meter.
		$average = $stats['average'];
		$band    = Kraken_Semantics_Scores::label_for( $average );
		echo '<div class="kraken-tile kraken-tile--ring">';
		$this->render_ring( $average, $band );
		echo '<div class="kraken-tile__ringside">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Average score', 'kraken-semantics' ) . '</span>';
		printf(
			'<span class="kraken-band kraken-band--%1$s"><i></i>%2$s</span>',
			esc_attr( $band ),
			esc_html( ucfirst( $band ) )
		);
		echo '</div>';
		echo '</div>';

		// Tile 2: coverage.
		$pct = $stats['total_posts'] ? round( 100 * $stats['scored'] / $stats['total_posts'] ) : 0;
		echo '<div class="kraken-tile">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Coverage', 'kraken-semantics' ) . '</span>';
		printf(
			'<span class="kraken-tile__value">%s<small>%%</small></span>',
			esc_html( number_format_i18n( $pct ) )
		);
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
		printf( '<span class="kraken-meter"><span style="width:%d%%"></span></span>', (int) $pct );
		echo '</div>';

		// Tile 3: band split.
		$scored = max( 1, $stats['scored'] );
		echo '<div class="kraken-tile">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Confidence bands', 'kraken-semantics' ) . '</span>';
		echo '<span class="kraken-splitbar">';
		foreach ( array( 'high', 'medium', 'low' ) as $band_key ) {
			$width = 100 * $stats['bands'][ $band_key ] / $scored;
			if ( $width > 0 ) {
				printf(
					'<span class="kraken-splitbar__seg kraken-splitbar__seg--%1$s" style="width:%2$.1f%%"></span>',
					esc_attr( $band_key ),
					(float) $width
				);
			}
		}
		echo '</span>';
		echo '<span class="kraken-tile__bands">';
		$band_names = array(
			'high'   => __( 'High', 'kraken-semantics' ),
			'medium' => __( 'Medium', 'kraken-semantics' ),
			'low'    => __( 'Low', 'kraken-semantics' ),
		);
		foreach ( $band_names as $band_key => $name ) {
			printf(
				'<span class="kraken-band kraken-band--%1$s"><i></i>%2$s %3$s</span>',
				esc_attr( $band_key ),
				esc_html( $name ),
				esc_html( number_format_i18n( $stats['bands'][ $band_key ] ) )
			);
		}
		echo '</span>';
		echo '</div>';

		// Tile 4: human review.
		$reviewed_pct = $stats['scored'] ? round( 100 * $stats['reviewed'] / $stats['scored'] ) : 0;
		echo '<div class="kraken-tile">';
		echo '<span class="kraken-tile__label">' . esc_html__( 'Human-reviewed', 'kraken-semantics' ) . '</span>';
		printf(
			'<span class="kraken-tile__value">%s<small>%%</small></span>',
			esc_html( number_format_i18n( $reviewed_pct ) )
		);
		printf(
			'<span class="kraken-tile__hint">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: reviewed count. */
					__( '%s scores approved by an editor', 'kraken-semantics' ),
					number_format_i18n( $stats['reviewed'] )
				)
			)
		);
		echo '</div>';

		// Tile 5: 30-day change from rescans.
		echo '<div class="kraken-tile">';
		echo '<span class="kraken-tile__label">' . esc_html__( '30-day change', 'kraken-semantics' ) . '</span>';
		if ( null === $stats['net_change_30d'] ) {
			echo '<span class="kraken-tile__value kraken-tile__value--muted">&mdash;</span>';
			echo '<span class="kraken-tile__hint">' . esc_html__( 'Rescan posts after editing to track change.', 'kraken-semantics' ) . '</span>';
		} else {
			$change = $stats['net_change_30d'];
			$class  = $change >= 0 ? 'up' : 'down';
			printf(
				'<span class="kraken-tile__value kraken-delta kraken-delta--%1$s">%2$s%3$s</span>',
				esc_attr( $class ),
				$change >= 0 ? '&#9650; +' : '&#9660; ',
				esc_html( number_format_i18n( abs( $change ), 1 ) )
			);
			echo '<span class="kraken-tile__hint">' . esc_html__( 'Average delta across rescanned posts.', 'kraken-semantics' ) . '</span>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * SVG ring meter for the average score.
	 *
	 * Public so the optional Kraken Hub widget can reuse it without
	 * duplicating the SVG (see Kraken_Semantics_Hub_Integration).
	 *
	 * @param float  $score Score, 0–100.
	 * @param string $band  Band slug for the ring color.
	 */
	public function render_ring( $score, $band ) {
		$radius        = 44;
		$circumference = 2 * M_PI * $radius;
		$arc           = $circumference * min( 100, max( 0, (float) $score ) ) / 100;

		// The number wears ink, not the band color; the ring carries the band.
		printf(
			'<svg class="kraken-ring kraken-ring--%1$s" viewBox="0 0 110 110" role="img" aria-label="%2$s">
				<circle class="kraken-ring__track" cx="55" cy="55" r="%3$d"/>
				<circle class="kraken-ring__arc" cx="55" cy="55" r="%3$d"
					stroke-dasharray="%4$.2f %5$.2f" transform="rotate(-90 55 55)"/>
				<text class="kraken-ring__value" x="55" y="55">%6$s</text>
				<text class="kraken-ring__cap" x="55" y="72">/ 100</text>
			</svg>',
			esc_attr( $band ),
			/* translators: %s: score. */
			esc_attr( sprintf( __( 'Average semantic confidence: %s out of 100', 'kraken-semantics' ), $score ) ),
			(int) $radius,
			(float) $arc,
			(float) ( $circumference - $arc ),
			esc_html( number_format_i18n( $score, 1 ) )
		);
	}

	/**
	 * Histogram of scores in ten-point buckets, with the band ranges marked
	 * under the axis.
	 *
	 * @param int[] $buckets Counts per bucket (index 0 = 0–9 … 9 = 90–100).
	 * @param int   $low     Low/medium threshold.
	 * @param int   $high    Medium/high threshold.
	 */
	protected function render_histogram( $buckets, $low, $high ) {
		$width      = 640;
		$height     = 200;
		$pad_x      = 10;
		$pad_top    = 26;  // Room for count labels on the caps.
		$axis_y     = $height - 42; // Baseline; below it: bucket labels + band strip.
		$plot_h     = $axis_y - $pad_top;
		$slot_w     = ( $width - 2 * $pad_x ) / 10;
		$bar_w      = 24; // Mark spec: columns capped at 24px.
		$max        = max( 1, max( $buckets ) );
		$total      = max( 1, array_sum( $buckets ) );

		$svg = sprintf(
			'<svg class="kraken-histogram" viewBox="0 0 %d %d" role="img" aria-label="%s">',
			$width,
			$height,
			esc_attr__( 'Distribution of confidence scores in ten-point buckets', 'kraken-semantics' )
		);

		// Baseline.
		$svg .= sprintf(
			'<line class="kraken-chart__baseline" x1="%1$d" y1="%3$d" x2="%2$d" y2="%3$d"/>',
			$pad_x,
			$width - $pad_x,
			$axis_y
		);

		foreach ( $buckets as $i => $count ) {
			$x     = $pad_x + $i * $slot_w + ( $slot_w - $bar_w ) / 2;
			$h     = $plot_h * $count / $max;
			$y     = $axis_y - $h;
			$from  = $i * 10;
			$to    = 9 === $i ? 100 : $from + 9;
			$tip   = sprintf(
				/* translators: 1: range, 2: count, 3: percentage. */
				__( 'Score %1$s: %2$s posts (%3$s%%)', 'kraken-semantics' ),
				"{$from}–{$to}",
				number_format_i18n( $count ),
				number_format_i18n( round( 100 * $count / $total ) )
			);

			// Invisible full-slot hit target so hover works on short bars.
			$svg .= sprintf(
				'<rect class="kraken-chart__hit" data-ks-tip="%s" x="%.1f" y="%d" width="%.1f" height="%d"/>',
				esc_attr( $tip ),
				$pad_x + $i * $slot_w,
				$pad_top,
				$slot_w,
				$axis_y - $pad_top
			);

			if ( $count > 0 ) {
				// Rounded at the data end (top), square at the baseline.
				$r    = min( 4, $h );
				$svg .= sprintf(
					'<path class="kraken-chart__bar" d="M%1$.1f %2$.1f L%1$.1f %3$.1f Q%1$.1f %4$.1f %5$.1f %4$.1f L%6$.1f %4$.1f Q%7$.1f %4$.1f %7$.1f %3$.1f L%7$.1f %2$.1f Z"/>',
					$x,
					$axis_y,
					$y + $r,
					$y,
					$x + $r,
					$x + $bar_w - $r,
					$x + $bar_w
				);

				// Count on the cap — every value labeled, so no y-axis needed.
				$svg .= sprintf(
					'<text class="kraken-chart__cap" x="%.1f" y="%.1f">%s</text>',
					$x + $bar_w / 2,
					$y - 6,
					esc_html( number_format_i18n( $count ) )
				);
			}

			// Boundary label at the slot's left edge (0, 10, 20 … 90).
			$svg .= sprintf(
				'<text class="kraken-chart__tick" x="%.1f" y="%d">%s</text>',
				$pad_x + $i * $slot_w,
				$axis_y + 14,
				esc_html( (string) $from )
			);
		}
		$svg .= sprintf(
			'<text class="kraken-chart__tick" x="%d" y="%d">100</text>',
			$width - $pad_x,
			$axis_y + 14
		);

		// Band strip: the low/medium/high ranges from settings, labeled.
		$strip_y = $axis_y + 22;
		$scale   = ( $width - 2 * $pad_x ) / 100;
		$bands   = array(
			array( 0, $low, 'low', __( 'Low', 'kraken-semantics' ) ),
			array( $low, $high, 'medium', __( 'Medium', 'kraken-semantics' ) ),
			array( $high, 100, 'high', __( 'High', 'kraken-semantics' ) ),
		);
		foreach ( $bands as $band ) {
			list( $from, $to, $slug, $name ) = $band;
			if ( $to <= $from ) {
				continue;
			}
			$svg .= sprintf(
				'<rect class="kraken-chart__bandstrip kraken-chart__bandstrip--%s" x="%.1f" y="%d" width="%.1f" height="5" rx="2.5"/>',
				esc_attr( $slug ),
				$pad_x + $from * $scale + 1,
				$strip_y,
				max( 0, ( $to - $from ) * $scale - 2 )
			);
			$svg .= sprintf(
				'<text class="kraken-chart__bandlabel" x="%.1f" y="%d">%s</text>',
				$pad_x + ( $from + $to ) / 2 * $scale,
				$strip_y + 16,
				esc_html( $name )
			);
		}

		$svg .= '</svg>';

		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * Weekly average trend line for the last 12 weeks.
	 *
	 * @param array<string,array{0:float,1:int}> $trend Week start => [sum, count].
	 */
	protected function render_trend( $trend ) {
		if ( count( $trend ) < 2 ) {
			echo '<p class="kraken-card__placeholder">' .
				esc_html__( 'Not enough scan history yet — scores from at least two different weeks are needed to draw a trend.', 'kraken-semantics' ) .
				'</p>';
			return;
		}

		$points = array();
		foreach ( $trend as $week => $acc ) {
			$points[] = array(
				'week'  => $week,
				'value' => $acc[1] ? $acc[0] / $acc[1] : 0,
				'count' => $acc[1],
			);
		}

		$width  = 640;
		$height = 190;
		$pad_l  = 34;
		$pad_r  = 48; // Room for the end label.
		$pad_t  = 14;
		$pad_b  = 26;

		$values = wp_list_pluck( $points, 'value' );
		$lo     = max( 0, floor( ( min( $values ) - 5 ) / 10 ) * 10 );
		$hi     = min( 100, ceil( ( max( $values ) + 5 ) / 10 ) * 10 );
		if ( $hi - $lo < 10 ) {
			$hi = min( 100, $lo + 10 );
		}

		$plot_w = $width - $pad_l - $pad_r;
		$plot_h = $height - $pad_t - $pad_b;
		$n      = count( $points );

		$coord = function ( $i, $value ) use ( $pad_l, $pad_t, $plot_w, $plot_h, $n, $lo, $hi ) {
			$x = $pad_l + ( $n > 1 ? $plot_w * $i / ( $n - 1 ) : $plot_w / 2 );
			$y = $pad_t + $plot_h * ( 1 - ( $value - $lo ) / ( $hi - $lo ) );
			return array( $x, $y );
		};

		$svg = sprintf(
			'<svg class="kraken-trendline" viewBox="0 0 %d %d" role="img" aria-label="%s">',
			$width,
			$height,
			esc_attr__( 'Average confidence score by week', 'kraken-semantics' )
		);

		// Three clean gridlines with tick labels: lo, mid, hi.
		foreach ( array( $lo, ( $lo + $hi ) / 2, $hi ) as $tick ) {
			list( , $y ) = $coord( 0, $tick );
			$svg .= sprintf(
				'<line class="kraken-chart__grid" x1="%d" y1="%.1f" x2="%d" y2="%.1f"/>',
				$pad_l,
				$y,
				$width - $pad_r,
				$y
			);
			$svg .= sprintf(
				'<text class="kraken-chart__tick kraken-chart__tick--y" x="%d" y="%.1f">%s</text>',
				$pad_l - 8,
				$y + 3,
				esc_html( number_format_i18n( $tick ) )
			);
		}

		// Area wash under the line, then the line, then markers.
		$line = '';
		$area = '';
		foreach ( $points as $i => $point ) {
			list( $x, $y ) = $coord( $i, $point['value'] );
			$line .= ( 0 === $i ? 'M' : ' L' ) . sprintf( '%.1f %.1f', $x, $y );
			if ( 0 === $i ) {
				$area = sprintf( 'M%.1f %.1f', $x, $pad_t + $plot_h );
			}
			$area .= sprintf( ' L%.1f %.1f', $x, $y );
		}
		list( $last_x, $last_y ) = $coord( $n - 1, $points[ $n - 1 ]['value'] );
		$area .= sprintf( ' L%.1f %.1f Z', $last_x, $pad_t + $plot_h );

		$svg .= sprintf( '<path class="kraken-chart__area" d="%s"/>', $area );
		$svg .= sprintf( '<path class="kraken-chart__line" d="%s"/>', $line );

		foreach ( $points as $i => $point ) {
			list( $x, $y ) = $coord( $i, $point['value'] );
			$tip = sprintf(
				/* translators: 1: week, 2: average, 3: scan count. */
				__( 'Week of %1$s: average %2$s across %3$s scans', 'kraken-semantics' ),
				date_i18n( get_option( 'date_format' ), strtotime( $point['week'] ) ),
				number_format_i18n( $point['value'], 1 ),
				number_format_i18n( $point['count'] )
			);
			// Marker with a surface ring; oversized invisible hit circle.
			$svg .= sprintf(
				'<circle class="kraken-chart__dot" cx="%.1f" cy="%.1f" r="4"/>' .
				'<circle class="kraken-chart__hit" data-ks-tip="%s" cx="%.1f" cy="%.1f" r="12"/>',
				$x,
				$y,
				esc_attr( $tip ),
				$x,
				$y
			);

			// First/last x labels only; interior weeks live in the tooltip.
			if ( 0 === $i || $n - 1 === $i ) {
				$svg .= sprintf(
					'<text class="kraken-chart__tick" x="%.1f" y="%d">%s</text>',
					$x,
					$height - 8,
					esc_html( date_i18n( 'M j', strtotime( $point['week'] ) ) )
				);
			}
		}

		// Direct end label: the latest weekly average.
		$svg .= sprintf(
			'<text class="kraken-chart__endlabel" x="%.1f" y="%.1f">%s</text>',
			$last_x + 10,
			$last_y + 4,
			esc_html( number_format_i18n( $points[ $n - 1 ]['value'], 1 ) )
		);

		$svg .= '</svg>';

		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	/**
	 * Per-dimension average meters.
	 *
	 * @param array<string,float> $dimensions Dimension slug => average.
	 */
	protected function render_dimensions( $dimensions ) {
		if ( empty( $dimensions ) ) {
			echo '<p class="kraken-card__placeholder">' .
				esc_html__( 'No per-dimension data yet — scores pushed without a breakdown do not appear here.', 'kraken-semantics' ) .
				'</p>';
			return;
		}

		asort( $dimensions );

		echo '<div class="kraken-dims">';
		foreach ( $dimensions as $dimension => $average ) {
			printf(
				'<div class="kraken-dims__row">
					<span class="kraken-dims__name">%1$s</span>
					<span class="kraken-dims__track"><span class="kraken-dims__fill" style="width:%2$.1f%%"></span></span>
					<span class="kraken-dims__value">%3$s</span>
				</div>',
				esc_html( ucwords( str_replace( '_', ' ', $dimension ) ) ),
				(float) min( 100, max( 0, $average ) ),
				esc_html( number_format_i18n( $average, 1 ) )
			);
		}
		echo '</div>';
	}

	/**
	 * A compact post list: title, score pill, delta, and when it was scanned.
	 *
	 * @param array<int,array<string,mixed>> $posts   Post summaries.
	 * @param string                         $context 'attention' or 'improvements'.
	 */
	protected function render_post_list( $posts, $context ) {
		if ( empty( $posts ) ) {
			echo '<p class="kraken-card__placeholder">' .
				( 'improvements' === $context
					? esc_html__( 'No rescans have raised a score yet. Rewrite a low-scoring post, rescan it, and it will show up here.', 'kraken-semantics' )
					: esc_html__( 'Nothing to show yet.', 'kraken-semantics' ) ) .
				'</p>';
			return;
		}

		echo '<ul class="kraken-postlist">';
		foreach ( $posts as $post ) {
			echo '<li class="kraken-postlist__row">';

			printf(
				'<a class="kraken-postlist__title" href="%s">%s</a>',
				esc_url( get_edit_post_link( $post['id'] ) ),
				esc_html( $post['title'] ? $post['title'] : __( '(no title)', 'kraken-semantics' ) )
			);

			echo '<span class="kraken-postlist__meta">';

			if ( 'improvements' === $context && null !== $post['delta'] ) {
				printf(
					'<span class="kraken-delta kraken-delta--up">&#9650; +%s</span>',
					esc_html( number_format_i18n( $post['delta'], 1 ) )
				);
			}

			printf(
				'<span class="kraken-semantics-pill kraken-semantics-pill--%1$s">%2$s</span>',
				esc_attr( $post['label'] ),
				esc_html( number_format_i18n( $post['score'], 1 ) )
			);

			if ( $post['scanned_at'] ) {
				printf(
					'<span class="kraken-postlist__when">%s</span>',
					esc_html(
						sprintf(
							/* translators: %s: human time diff. */
							__( '%s ago', 'kraken-semantics' ),
							human_time_diff( strtotime( $post['scanned_at'] ) )
						)
					)
				);
			}

			echo '</span>';
			echo '</li>';
		}
		echo '</ul>';
	}
}
