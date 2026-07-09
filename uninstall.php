<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the plugin is *deleted* from the Plugins screen (not on
 * deactivation). Removes every trace of the plugin: settings, all score
 * meta, and any queued cron jobs.
 *
 * @package Kraken_Semantics
 */

// WordPress defines this constant only during a legitimate uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin was not loaded for this request, so keep this file
// self-contained: literal keys instead of class constants.
delete_option( 'kraken_semantics_settings' );

// Remove score meta for every post of every type in one query per key.
$kraken_semantics_meta_keys = array(
	'_kraken_semantics_score',
	'_kraken_semantics_breakdown',
	'_kraken_semantics_summary',
	'_kraken_semantics_provider',
	'_kraken_semantics_model',
	'_kraken_semantics_scanned_at',
	'_kraken_semantics_reviewed',
);

foreach ( $kraken_semantics_meta_keys as $kraken_semantics_meta_key ) {
	delete_post_meta_by_key( $kraken_semantics_meta_key );
}

// Clear any background scans still waiting in cron.
wp_unschedule_hook( 'kraken_semantics_scan_event' );
