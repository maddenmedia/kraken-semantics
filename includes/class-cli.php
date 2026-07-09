<?php
/**
 * WP-CLI commands.
 *
 * Usage:
 *   wp kraken-semantics scan 123 456        # scan specific posts
 *   wp kraken-semantics scan --all          # scan everything enabled
 *   wp kraken-semantics scan --all --post-type=page --missing-only
 *   wp kraken-semantics status 123          # print a post's score record
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans content and inspects scores from the command line.
 */
class Kraken_Semantics_CLI {

	/**
	 * Scanner used to run scans.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Scans one or more posts with the configured provider.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post IDs to scan.
	 *
	 * [--all]
	 * : Scan every published post of the enabled post types.
	 *
	 * [--post-type=<type>]
	 * : With --all, restrict to a single post type.
	 *
	 * [--missing-only]
	 * : With --all, skip posts that already have a score.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kraken-semantics scan 42
	 *     wp kraken-semantics scan --all --missing-only
	 *
	 * @param array<int,string>    $args       Positional args (post IDs).
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ) {
		$ids = array_map( 'intval', $args );

		if ( isset( $assoc_args['all'] ) ) {
			$post_types = isset( $assoc_args['post-type'] )
				? array( sanitize_key( $assoc_args['post-type'] ) )
				: kraken_semantics_get_post_types();

			$ids = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,   // Bulk scan: we genuinely want everything.
					'fields'         => 'ids',
				)
			);
		}

		if ( empty( $ids ) ) {
			WP_CLI::error( 'Nothing to scan. Pass post IDs or use --all.' );
		}

		$done   = 0;
		$failed = 0;

		foreach ( $ids as $post_id ) {
			if ( isset( $assoc_args['missing-only'] ) && null !== Kraken_Semantics_Scores::get( $post_id ) ) {
				WP_CLI::log( "Post {$post_id}: already scored, skipping." );
				continue;
			}

			$result = $this->scanner->scan( $post_id );

			if ( is_wp_error( $result ) ) {
				$failed++;
				WP_CLI::warning( "Post {$post_id}: " . $result->get_error_message() );
				continue;
			}

			$done++;
			WP_CLI::log( "Post {$post_id}: {$result['score']} ({$result['label']})" );

			// When parallel scoring ran, list each provider's score too.
			$results = Kraken_Semantics_Scores::results( $post_id );
			if ( count( $results ) > 1 ) {
				foreach ( $results as $slug => $entry ) {
					WP_CLI::log( "  - {$slug}: {$entry['score']} ({$entry['label']})" );
				}
			}
		}

		if ( $failed && ! $done ) {
			WP_CLI::error( "All {$failed} scan(s) failed." );
		}

		WP_CLI::success( "Scanned {$done} post(s)" . ( $failed ? ", {$failed} failed." : '.' ) );
	}

	/**
	 * Prints the stored score record for a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The post ID.
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kraken-semantics status 42
	 *     wp kraken-semantics status 42 --format=json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$post_id = (int) $args[0];
		$data    = Kraken_Semantics_Scores::get( $post_id );

		if ( null === $data ) {
			WP_CLI::error( "Post {$post_id} has not been scored." );
		}

		// Flatten the breakdown so every value fits the two-column table.
		$rows = array();
		foreach ( $data as $field => $value ) {
			if ( 'breakdown' === $field ) {
				foreach ( $value as $dimension => $score ) {
					$rows[] = array(
						'field' => "breakdown.{$dimension}",
						'value' => $score,
					);
				}
				continue;
			}
			$rows[] = array(
				'field' => $field,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value,
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}
}
