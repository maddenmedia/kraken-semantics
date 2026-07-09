<?php
/**
 * Front-end output: the confidence badge.
 *
 * Three ways to surface a score to readers:
 *  1. Automatic placement before/after post content (settings-controlled).
 *  2. The [kraken_semantics_badge] shortcode.
 *  3. The kraken_semantics_badge() template tag (see functions.php).
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders score badges on the public site.
 */
class Kraken_Semantics_Frontend {

	/**
	 * Wires up content filtering, the shortcode, and styles.
	 */
	public function __construct() {
		// Priority 20 runs after wpautop/shortcodes so the badge is not
		// caught up in content texturization.
		add_filter( 'the_content', array( $this, 'maybe_add_badge' ), 20 );

		add_shortcode( 'kraken_semantics_badge', array( $this, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Loads the badge stylesheet on singular views of enabled post types.
	 *
	 * The stylesheet is tiny, but there is still no reason to ship it to
	 * archives or post types that can never show a badge.
	 */
	public function enqueue_styles() {
		if ( ! is_singular( kraken_semantics_get_post_types() ) ) {
			return;
		}

		wp_enqueue_style(
			'kraken-semantics',
			KRAKEN_SEMANTICS_URL . 'assets/css/kraken-semantics.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);
	}

	/**
	 * Appends or prepends the badge to post content when enabled.
	 *
	 * @param string $content Post content.
	 * @return string Content with the badge attached.
	 */
	public function maybe_add_badge( $content ) {
		$settings = kraken_semantics_get_settings();

		// Only decorate the real content of the main post on a singular
		// view — not excerpts, widgets, or secondary loops.
		if (
			! $settings['display_badge']
			|| ! is_singular( kraken_semantics_get_post_types() )
			|| ! in_the_loop()
			|| ! is_main_query()
		) {
			return $content;
		}

		$badge = self::badge_html( get_the_ID() );

		if ( '' === $badge ) {
			return $content; // Post has never been scored.
		}

		return 'before' === $settings['badge_position']
			? $badge . $content
			: $content . $badge;
	}

	/**
	 * Shortcode handler: [kraken_semantics_badge id="123"].
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string Badge HTML (empty when the post is unscored).
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => get_the_ID(), // Defaults to the current post.
			),
			$atts,
			'kraken_semantics_badge'
		);

		// The shortcode may render on pages where enqueue_styles() did not
		// run (e.g. a page builder area), so make sure the CSS is present.
		wp_enqueue_style(
			'kraken-semantics',
			KRAKEN_SEMANTICS_URL . 'assets/css/kraken-semantics.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);

		return self::badge_html( (int) $atts['id'] );
	}

	/**
	 * Builds the badge markup for a post.
	 *
	 * All dynamic values are escaped here, so callers can print the result
	 * directly.
	 *
	 * @param int $post_id Post ID.
	 * @return string Badge HTML, or empty string when the post is unscored.
	 */
	public static function badge_html( $post_id ) {
		$data = Kraken_Semantics_Scores::get( $post_id );

		if ( null === $data ) {
			return '';
		}

		$labels = array(
			'high'   => __( 'High', 'kraken-semantics' ),
			'medium' => __( 'Medium', 'kraken-semantics' ),
			'low'    => __( 'Low', 'kraken-semantics' ),
		);

		$label_text = isset( $labels[ $data['label'] ] ) ? $labels[ $data['label'] ] : $data['label'];

		// Show "87" rather than "87.0", but keep a real decimal like "87.5".
		$score_text = ( floor( $data['score'] ) === (float) $data['score'] )
			? number_format_i18n( $data['score'] )
			: number_format_i18n( $data['score'], 1 );

		$html = sprintf(
			'<div class="kraken-semantics-badge kraken-semantics-badge--%1$s" role="note" aria-label="%2$s">' .
				'<span class="kraken-semantics-badge__score">%3$s</span>' .
				'<span class="kraken-semantics-badge__text">%4$s</span>' .
			'</div>',
			esc_attr( $data['label'] ),
			/* translators: 1: score, 2: band label. */
			esc_attr( sprintf( __( 'Semantic confidence score: %1$s out of 100 (%2$s)', 'kraken-semantics' ), $data['score'], $label_text ) ),
			esc_html( $score_text ),
			/* translators: %s: band label (High/Medium/Low). */
			esc_html( sprintf( __( 'Semantic confidence: %s', 'kraken-semantics' ), $label_text ) )
		);

		/**
		 * Filters the badge markup before output.
		 *
		 * @param string              $html    Escaped badge HTML.
		 * @param int                 $post_id Post ID.
		 * @param array<string,mixed> $data    The score record.
		 */
		return apply_filters( 'kraken_semantics_badge_html', $html, $post_id, $data );
	}
}
