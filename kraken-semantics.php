<?php
/**
 * Plugin Name:       Kraken Semantics
 * Plugin URI:        https://github.com/maddenmedia/kraken-semantics
 * Description:       Score how confidently AI can trust your content, then rewrite and watch it improve. Insights dashboard, score history, Claude/OpenAI/Gemini providers, local scoring via MCP, REST API, front-end badges, and WP-CLI commands.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Madden Media
 * Author URI:        https://maddenmedia.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kraken-semantics
 * Domain Path:       /languages
 *
 * @package Kraken_Semantics
 */

// Abort if this file is called directly rather than loaded by WordPress.
defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Plugin constants
 * -----------------------------------------------------------------------------
 * These are the only globals the plugin defines. Everything else lives inside
 * the Kraken_Semantics_* classes or kraken_semantics_*() functions.
 */

/** Current plugin version. Bump on release; used for cache-busting assets. */
define( 'KRAKEN_SEMANTICS_VERSION', '1.1.0' );

/** Absolute path to this file (used for activation hooks). */
define( 'KRAKEN_SEMANTICS_FILE', __FILE__ );

/** Absolute filesystem path to the plugin directory, with trailing slash. */
define( 'KRAKEN_SEMANTICS_DIR', plugin_dir_path( __FILE__ ) );

/** URL to the plugin directory, with trailing slash (for enqueuing assets). */
define( 'KRAKEN_SEMANTICS_URL', plugin_dir_url( __FILE__ ) );

/*
 * -----------------------------------------------------------------------------
 * Class and function loading
 * -----------------------------------------------------------------------------
 * The plugin is intentionally dependency-free (no Composer autoloader), so the
 * includes are explicit. Order matters only in that the provider interface
 * must load before its implementations.
 */
require_once KRAKEN_SEMANTICS_DIR . 'includes/functions.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-scores.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/providers/interface-kraken-semantics-provider.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/providers/class-kraken-semantics-provider-claude.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/providers/class-kraken-semantics-provider-openai.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/providers/class-kraken-semantics-provider-gemini.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-scanner.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-rest-api.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-frontend.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-settings.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-dashboard.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-admin.php';
require_once KRAKEN_SEMANTICS_DIR . 'includes/class-kraken-semantics.php';

// WP-CLI commands are only needed (and only safe to load) in a CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once KRAKEN_SEMANTICS_DIR . 'includes/class-cli.php';
}

/**
 * Returns the shared plugin instance, booting it on first call.
 *
 * Exposed as a function so themes and other plugins can reach plugin
 * internals without touching globals: `kraken_semantics()->scanner`.
 *
 * @return Kraken_Semantics The plugin singleton.
 */
function kraken_semantics() {
	return Kraken_Semantics::instance();
}

// Boot on plugins_loaded so other plugins can hook our filters first.
add_action( 'plugins_loaded', 'kraken_semantics' );

/*
 * -----------------------------------------------------------------------------
 * Activation / deactivation
 * -----------------------------------------------------------------------------
 */

/**
 * Seeds default settings on activation without overwriting an existing config.
 */
function kraken_semantics_activate() {
	// add_option() is a no-op when the option already exists, which makes
	// re-activation safe: user settings survive deactivate/activate cycles.
	add_option( 'kraken_semantics_settings', kraken_semantics_default_settings() );
}
register_activation_hook( __FILE__, 'kraken_semantics_activate' );

/**
 * Clears any queued background scans on deactivation.
 *
 * Settings and scores are deliberately left in place — full cleanup happens
 * in uninstall.php when the plugin is deleted, not merely deactivated.
 */
function kraken_semantics_deactivate() {
	// wp_unschedule_hook() removes every pending single event for the hook,
	// regardless of the post ID argument each event was scheduled with.
	wp_unschedule_hook( Kraken_Semantics_Scanner::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'kraken_semantics_deactivate' );
