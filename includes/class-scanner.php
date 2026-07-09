<?php
/**
 * Scan orchestration.
 *
 * The scanner is the traffic controller between WordPress and the providers:
 * it prepares post content, picks the configured provider, persists results
 * through Kraken_Semantics_Scores, and handles background (cron) scanning.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs scans and manages the auto-scan cron queue.
 */
class Kraken_Semantics_Scanner {

	/** Cron hook used for queued background scans. */
	const CRON_HOOK = 'kraken_semantics_scan_event';

	/**
	 * Wires up cron and auto-scan hooks.
	 */
	public function __construct() {
		// The cron callback that performs a queued scan.
		add_action( self::CRON_HOOK, array( $this, 'run_queued_scan' ) );

		// Watch publish/update events so auto-scan can queue work.
		add_action( 'transition_post_status', array( $this, 'maybe_queue_scan' ), 10, 3 );
	}

	/**
	 * Returns the registered scan providers.
	 *
	 * @return array<string,Kraken_Semantics_Provider> Providers keyed by slug.
	 */
	public function providers() {
		$providers = array(
			'claude' => new Kraken_Semantics_Provider_Claude(),
			'openai' => new Kraken_Semantics_Provider_OpenAI(),
			'gemini' => new Kraken_Semantics_Provider_Gemini(),
		);

		/**
		 * Filters the available scan providers.
		 *
		 * Add a custom provider by returning an instance that implements
		 * Kraken_Semantics_Provider under its slug.
		 *
		 * @param array<string,Kraken_Semantics_Provider> $providers Providers keyed by slug.
		 */
		$providers = apply_filters( 'kraken_semantics_providers', $providers );

		// Defensively drop anything that does not honor the contract, so a
		// broken third-party registration cannot fatal the whole plugin.
		return array_filter(
			$providers,
			function ( $provider ) {
				return $provider instanceof Kraken_Semantics_Provider;
			}
		);
	}

	/**
	 * Returns the provider selected in settings.
	 *
	 * @return Kraken_Semantics_Provider|WP_Error The active provider.
	 */
	public function active_provider() {
		$settings  = kraken_semantics_get_settings();
		$providers = $this->providers();
		$slug      = $settings['provider'];

		if ( ! isset( $providers[ $slug ] ) ) {
			return new WP_Error(
				'kraken_semantics_unknown_provider',
				sprintf(
					/* translators: %s: provider slug. */
					__( 'The configured scan provider "%s" is not registered.', 'kraken-semantics' ),
					$slug
				)
			);
		}

		return $providers[ $slug ];
	}

	/**
	 * Returns the providers a scan should run: the primary provider plus any
	 * configured "parallel" providers selected in settings.
	 *
	 * The primary provider is always first. Only registered, configured
	 * providers are included, and the primary is never duplicated. With no
	 * parallel providers set (the default), this is just the primary.
	 *
	 * @return array<string,Kraken_Semantics_Provider> Providers keyed by slug,
	 *                                                  primary first.
	 */
	public function effective_providers() {
		$primary = $this->active_provider();

		if ( is_wp_error( $primary ) ) {
			return array();
		}

		$providers = $this->providers();
		$effective = array( $primary->get_id() => $primary );

		$settings = kraken_semantics_get_settings();
		$extra    = isset( $settings['parallel_providers'] ) ? (array) $settings['parallel_providers'] : array();

		foreach ( $extra as $slug ) {
			$slug = sanitize_key( $slug );

			if ( $slug === $primary->get_id() || ! isset( $providers[ $slug ] ) || isset( $effective[ $slug ] ) ) {
				continue;
			}

			// Silently skip extras that lost their API key; the primary scan
			// still succeeds, and the settings screen flags the missing key.
			if ( $providers[ $slug ]->is_configured() ) {
				$effective[ $slug ] = $providers[ $slug ];
			}
		}

		return $effective;
	}

	/**
	 * Scans a post and stores the resulting score.
	 *
	 * This is the single entry point used by the REST API, the admin
	 * meta box, WP-CLI, and cron.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|WP_Error The stored score record on success.
	 */
	public function scan( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'kraken_semantics_invalid_post',
				__( 'Cannot scan: the post does not exist.', 'kraken-semantics' )
			);
		}

		$primary = $this->active_provider();
		if ( is_wp_error( $primary ) ) {
			return $primary;
		}

		if ( ! $primary->is_configured() ) {
			return new WP_Error(
				'kraken_semantics_provider_unconfigured',
				sprintf(
					/* translators: %s: provider label. */
					__( 'The provider "%s" is not configured yet.', 'kraken-semantics' ),
					$primary->get_label()
				)
			);
		}

		$content = $this->prepare_content( $post );

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'kraken_semantics_empty_content',
				__( 'Cannot scan: the post has no text content.', 'kraken-semantics' )
			);
		}

		$primary_id = $primary->get_id();
		$saved      = null;

		// Run the primary provider plus any configured parallel providers.
		// Every result is recorded in the per-provider map; the primary's is
		// also written as the canonical score. A failure from the primary is
		// fatal; a failing parallel provider is logged and skipped so it can
		// never take down the whole scan.
		foreach ( $this->effective_providers() as $slug => $provider ) {
			$result = $provider->scan( $content, $post );

			if ( is_wp_error( $result ) ) {
				if ( $slug === $primary_id ) {
					return $result;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						sprintf(
							'Kraken Semantics: parallel provider "%s" failed on post %d: %s',
							$slug,
							$post->ID,
							$result->get_error_message()
						)
					);
				}

				continue;
			}

			// Side-map entry for every provider that scored (parallel view).
			Kraken_Semantics_Scores::save_result( $post->ID, $slug, $result );

			if ( $slug === $primary_id ) {
				// The scanner — not the provider — owns persistence and bookkeeping.
				$saved = Kraken_Semantics_Scores::save(
					$post->ID,
					array(
						'score'      => $result['score'],
						'breakdown'  => isset( $result['breakdown'] ) ? $result['breakdown'] : array(),
						'summary'    => isset( $result['summary'] ) ? $result['summary'] : '',
						'provider'   => $primary_id,
						'model'      => isset( $result['model'] ) ? $result['model'] : '',
						'scanned_at' => gmdate( 'c' ),
						// A fresh machine score supersedes any earlier human review.
						'reviewed'   => false,
					)
				);
			}
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( null === $saved ) {
			// The primary is validated above, so this is defensive only.
			return new WP_Error(
				'kraken_semantics_scan_failed',
				__( 'The scan did not produce a score.', 'kraken-semantics' )
			);
		}

		/**
		 * Fires after a post has been scanned and its score stored.
		 *
		 * @param int                 $post_id Post ID.
		 * @param array<string,mixed> $saved   The stored score record.
		 */
		do_action( 'kraken_semantics_post_scanned', $post->ID, $saved );

		return $saved;
	}

	/**
	 * Converts a post into the plain text a provider will grade.
	 *
	 * @param WP_Post $post Post to prepare.
	 * @return string Plain-text content.
	 */
	protected function prepare_content( WP_Post $post ) {
		// Render shortcodes/blocks first so the model grades what readers
		// actually see, then flatten the result to plain text.
		$content = apply_filters( 'the_content', $post->post_content );
		$content = wp_strip_all_tags( $content, true );

		/**
		 * Filters the maximum number of characters sent to a provider.
		 *
		 * Long posts are truncated to keep request sizes and token costs
		 * predictable; ~30k characters comfortably covers typical articles.
		 *
		 * @param int     $max_chars Character budget.
		 * @param WP_Post $post      Post being prepared.
		 */
		$max_chars = (int) apply_filters( 'kraken_semantics_max_content_chars', 30000, $post );

		if ( strlen( $content ) > $max_chars ) {
			$content = substr( $content, 0, $max_chars );
		}

		/**
		 * Filters the prepared plain-text content before it is scanned.
		 *
		 * @param string  $content Plain-text content.
		 * @param WP_Post $post    Post being prepared.
		 */
		return apply_filters( 'kraken_semantics_scan_content', $content, $post );
	}

	/**
	 * Queues a background scan when an enabled post type is published.
	 *
	 * Runs on transition_post_status. The scan itself happens in cron a few
	 * seconds later so a slow API call can never block the editor's save.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       The post being transitioned.
	 */
	public function maybe_queue_scan( $new_status, $old_status, $post ) {
		$settings = kraken_semantics_get_settings();

		if ( ! $settings['auto_scan'] || 'publish' !== $new_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, kraken_semantics_get_post_types(), true ) ) {
			return;
		}

		// Ignore programmatic saves that aren't real content changes.
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$args = array( $post->ID );

		// Avoid stacking duplicate jobs when a post is saved repeatedly.
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK, $args );
		}
	}

	/**
	 * Cron callback: performs a queued scan.
	 *
	 * Failures are logged rather than surfaced — there is no user to show
	 * them to in a cron context, and the next save will queue a retry.
	 *
	 * @param int $post_id Post ID queued by maybe_queue_scan().
	 */
	public function run_queued_scan( $post_id ) {
		$result = $this->scan( (int) $post_id );

		if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'Kraken Semantics: scan of post %d failed: %s', $post_id, $result->get_error_message() )
			);
		}
	}
}
