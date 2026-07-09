<?php
/**
 * Core plugin class.
 *
 * A thin composition root: it instantiates each component exactly once and
 * exposes them as public properties, so integrators can do e.g.
 * `kraken_semantics()->scanner->scan( $post_id )`.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots and holds the plugin's components.
 */
final class Kraken_Semantics {

	/**
	 * The shared instance.
	 *
	 * @var Kraken_Semantics|null
	 */
	protected static $instance = null;

	/**
	 * Score storage component (registers meta).
	 *
	 * @var Kraken_Semantics_Scores
	 */
	public $scores;

	/**
	 * Scan orchestrator.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	public $scanner;

	/**
	 * REST API routes.
	 *
	 * @var Kraken_Semantics_Rest_Api
	 */
	public $rest_api;

	/**
	 * Front-end badge output.
	 *
	 * @var Kraken_Semantics_Frontend
	 */
	public $frontend;

	/**
	 * Settings screen (admin only).
	 *
	 * @var Kraken_Semantics_Settings|null
	 */
	public $settings;

	/**
	 * Editor meta box and list column (admin only).
	 *
	 * @var Kraken_Semantics_Admin|null
	 */
	public $admin;

	/**
	 * Returns the shared instance, creating it on first call.
	 *
	 * @return Kraken_Semantics
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Instantiates components. Private: use instance().
	 */
	private function __construct() {
		$this->scores   = new Kraken_Semantics_Scores();
		$this->scanner  = new Kraken_Semantics_Scanner();
		$this->rest_api = new Kraken_Semantics_Rest_Api( $this->scanner );
		$this->frontend = new Kraken_Semantics_Frontend();

		// Admin-only components stay unloaded on the front end.
		if ( is_admin() ) {
			$this->settings = new Kraken_Semantics_Settings( $this->scanner );
			$this->admin    = new Kraken_Semantics_Admin( $this->scanner );
		}

		// WP-CLI commands (class is only loaded in CLI context).
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'Kraken_Semantics_CLI' ) ) {
			WP_CLI::add_command( 'kraken-semantics', new Kraken_Semantics_CLI( $this->scanner ) );
		}
	}
}
