<?php
/**
 * Scan provider contract.
 *
 * A provider is anything that can look at a piece of content and produce a
 * semantic confidence score. The plugin ships with a Claude provider; add
 * your own by implementing this interface and registering the instance on
 * the `kraken_semantics_providers` filter:
 *
 *     add_filter( 'kraken_semantics_providers', function ( $providers ) {
 *         $providers['acme'] = new Acme_Confidence_Provider();
 *         return $providers;
 *     } );
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract every scan provider must fulfil.
 */
interface Kraken_Semantics_Provider {

	/**
	 * Returns the unique provider slug (lowercase, no spaces).
	 *
	 * This is the value stored in the `provider` setting and in each post's
	 * provider meta, so it must be stable across releases.
	 *
	 * @return string Provider slug, e.g. 'claude'.
	 */
	public function get_id();

	/**
	 * Returns the human-readable provider name shown in the settings UI.
	 *
	 * @return string Provider label, e.g. 'Claude (Anthropic API)'.
	 */
	public function get_label();

	/**
	 * Whether the provider has everything it needs to run (API keys, etc.).
	 *
	 * The scanner refuses to dispatch to an unconfigured provider, and the
	 * admin UI uses this to decide whether to show the "Scan now" button.
	 *
	 * @return bool True when the provider is ready to scan.
	 */
	public function is_configured();

	/**
	 * Analyzes content and returns a confidence score.
	 *
	 * Implementations should be side-effect free: the Scanner is responsible
	 * for persisting the result, firing hooks, and recording timestamps.
	 *
	 * @param string  $content Plain-text content to analyze (tags stripped).
	 * @param WP_Post $post    The post the content belongs to, for context.
	 * @return array<string,mixed>|WP_Error {
	 *     Scan result on success, WP_Error on failure.
	 *
	 *     @type float                $score     Overall confidence, 0–100.
	 *     @type array<string,float>  $breakdown Per-dimension scores, 0–100.
	 *     @type string               $summary   One-to-two sentence rationale.
	 *     @type string               $model     Model identifier that produced it.
	 * }
	 */
	public function scan( $content, WP_Post $post );
}
