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
	 * Insights dashboard (admin only).
	 *
	 * @var Kraken_Semantics_Dashboard|null
	 */
	public $dashboard;

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
			$this->dashboard = new Kraken_Semantics_Dashboard( $this->scanner );
			$this->settings  = new Kraken_Semantics_Settings( $this->scanner );
			$this->admin     = new Kraken_Semantics_Admin( $this->scanner );

			// Keep the plugin's own screens clear of unrelated admin notices
			// that other plugins broadcast to every page.
			add_action( 'in_admin_header', array( $this, 'silence_foreign_admin_notices' ), PHP_INT_MAX );
		}

		// WP-CLI commands (class is only loaded in CLI context).
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'Kraken_Semantics_CLI' ) ) {
			WP_CLI::add_command( 'kraken-semantics', new Kraken_Semantics_CLI( $this->scanner ) );
		}
	}

	/**
	 * Strips other plugins' admin notices from Kraken Semantics screens.
	 *
	 * WordPress lets any plugin hook `admin_notices` and have its banner show
	 * on every admin page. On our own dashboard and settings screens we clear
	 * those hooks so the interface stays focused. The plugin registers no
	 * admin notices of its own, so nothing of ours is lost. Fires on
	 * `in_admin_header`, after every notice is registered but before the header
	 * renders them.
	 */
	public function silence_foreign_admin_notices() {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, Kraken_Semantics_Dashboard::MENU_SLUG ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}
}
