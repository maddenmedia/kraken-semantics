<?php
/**
 * Public helper functions and template tags.
 *
 * Everything in this file is safe to call from a theme. Template tags follow
 * the WordPress convention of accepting a null post ID and falling back to
 * the current post in the loop.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin's default settings.
 *
 * Kept in one place so activation, the settings sanitizer, and the settings
 * getter can never disagree about what a "fresh install" looks like.
 *
 * @return array<string,mixed> Default settings.
 */
function kraken_semantics_default_settings() {
	return array(
		// Post types whose content can carry a confidence score.
		'post_types'     => array( 'post' ),

		// Slug of the scan provider to use (see Kraken_Semantics_Scanner::providers()).
		'provider'       => 'claude',

		// Anthropic API key. Prefer the KRAKEN_SEMANTICS_ANTHROPIC_API_KEY
		// constant in wp-config.php — the constant always wins over this value.
		'api_key'        => '',

		// Claude model used by the built-in scanner.
		'model'          => 'claude-opus-4-8',

		// Automatically queue a scan when a post is published or updated.
		'auto_scan'      => false,

		// Append the score badge to front-end content automatically.
		'display_badge'  => true,

		// Where the automatic badge is placed relative to the content.
		'badge_position' => 'after', // 'before' or 'after'.

		// Scores at or above this are labeled "high".
		'threshold_high' => 80,

		// Scores at or above this (but below threshold_high) are "medium";
		// anything lower is "low".
		'threshold_low'  => 50,
	);
}

/**
 * Returns the plugin settings merged over the defaults.
 *
 * Merging over defaults means new settings added in future versions get a
 * sane value even if the stored option predates them.
 *
 * @return array<string,mixed> Complete settings array.
 */
function kraken_semantics_get_settings() {
	$saved = get_option( 'kraken_semantics_settings', array() );

	return wp_parse_args( is_array( $saved ) ? $saved : array(), kraken_semantics_default_settings() );
}

/**
 * Returns the post types scores can be attached to.
 *
 * @return string[] Post type slugs.
 */
function kraken_semantics_get_post_types() {
	$settings = kraken_semantics_get_settings();

	/**
	 * Filters the post types Kraken Semantics operates on.
	 *
	 * @param string[] $post_types Post type slugs from the plugin settings.
	 */
	return apply_filters( 'kraken_semantics_post_types', (array) $settings['post_types'] );
}

/**
 * Template tag: returns the overall confidence score for a post.
 *
 * @param int|WP_Post|null $post Post ID or object. Defaults to the current post.
 * @return float|null Score in the 0–100 range, or null when the post has
 *                    never been scored.
 */
function kraken_semantics_get_score( $post = null ) {
	$data = kraken_semantics_get_score_data( $post );

	return $data ? $data['score'] : null;
}

/**
 * Template tag: returns the full score record for a post.
 *
 * @param int|WP_Post|null $post Post ID or object. Defaults to the current post.
 * @return array<string,mixed>|null {
 *     Score record, or null when the post has never been scored.
 *
 *     @type float                $score      Overall score, 0–100.
 *     @type string               $label      'high', 'medium', or 'low'.
 *     @type array<string,float>  $breakdown  Per-dimension scores, 0–100.
 *     @type string               $summary    One-line rationale from the scanner.
 *     @type string               $provider   Provider slug that produced the score.
 *     @type string               $model      Model identifier, when applicable.
 *     @type string               $scanned_at ISO 8601 timestamp (GMT).
 *     @type bool                 $reviewed   Whether a human has reviewed the score.
 * }
 */
function kraken_semantics_get_score_data( $post = null ) {
	$post = get_post( $post );

	return $post ? Kraken_Semantics_Scores::get( $post->ID ) : null;
}

/**
 * Template tag: outputs (or returns) the score badge for a post.
 *
 * Use this in a theme when the automatic badge placement is disabled:
 *
 *     <?php kraken_semantics_badge(); ?>
 *
 * @param int|WP_Post|null $post Post ID or object. Defaults to the current post.
 * @param bool             $echo Whether to echo the badge (default) or return it.
 * @return string|void Badge HTML when $echo is false; empty string if unscored.
 */
function kraken_semantics_badge( $post = null, $echo = true ) {
	$post = get_post( $post );
	$html = $post ? Kraken_Semantics_Frontend::badge_html( $post->ID ) : '';

	if ( ! $echo ) {
		return $html;
	}

	// badge_html() is built from escaped parts; see Frontend::badge_html().
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
