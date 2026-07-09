<?php
/**
 * Score storage layer.
 *
 * All reads and writes of confidence-score data go through this class so the
 * meta keys, validation rules, and REST exposure live in exactly one place.
 *
 * Storage model: one post meta entry per field, all keys prefixed with an
 * underscore so WordPress hides them from the Custom Fields UI.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes, and validates score records stored in post meta.
 */
class Kraken_Semantics_Scores {

	/** Overall confidence score, stored as a float 0–100. */
	const META_SCORE = '_kraken_semantics_score';

	/** Per-dimension scores, stored as an associative array of floats. */
	const META_BREAKDOWN = '_kraken_semantics_breakdown';

	/** Short human-readable rationale produced by the scanner. */
	const META_SUMMARY = '_kraken_semantics_summary';

	/** Slug of the provider that produced the score (claude, external, manual…). */
	const META_PROVIDER = '_kraken_semantics_provider';

	/** Model identifier reported by the provider, when applicable. */
	const META_MODEL = '_kraken_semantics_model';

	/** ISO 8601 GMT timestamp of the most recent scan. */
	const META_SCANNED_AT = '_kraken_semantics_scanned_at';

	/** '1' when a human has reviewed/approved the score; absent otherwise. */
	const META_REVIEWED = '_kraken_semantics_reviewed';

	/**
	 * Hooks meta registration into init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Registers the score meta fields with WordPress.
	 *
	 * Registration buys us three things: REST exposure on the core post
	 * endpoints (so headless front ends can read scores without our custom
	 * API), type coercion, and a capability check on writes.
	 */
	public function register_meta() {
		// Only users who can edit the post may write score meta through core APIs.
		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		// Scalar fields share everything except key and type.
		$scalars = array(
			self::META_SCORE      => 'number',
			self::META_SUMMARY    => 'string',
			self::META_PROVIDER   => 'string',
			self::META_MODEL      => 'string',
			self::META_SCANNED_AT => 'string',
			self::META_REVIEWED   => 'boolean',
		);

		foreach ( $scalars as $key => $type ) {
			// An empty object subtype ('') registers the key for every post
			// type, which is what makes the plugin "universal" — enabled post
			// types are enforced at scan/display time, not storage time.
			register_post_meta(
				'',
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => $auth,
				)
			);
		}

		// The breakdown is an object of dimension => score, so it needs an
		// explicit REST schema (arrays/objects are not exposable without one).
		register_post_meta(
			'',
			self::META_BREAKDOWN,
			array(
				'type'          => 'object',
				'single'        => true,
				'auth_callback' => $auth,
				'show_in_rest'  => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'number' ),
					),
				),
			)
		);
	}

	/**
	 * Returns the full score record for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null Score record (see kraken_semantics_get_score_data()
	 *                                  for the shape), or null if never scored.
	 */
	public static function get( $post_id ) {
		$score = get_post_meta( $post_id, self::META_SCORE, true );

		// An empty string means the meta row does not exist: never scored.
		// A stored 0 comes back as the string '0', which is not ''.
		if ( '' === $score ) {
			return null;
		}

		$score     = (float) $score;
		$breakdown = get_post_meta( $post_id, self::META_BREAKDOWN, true );

		return array(
			'score'      => $score,
			'label'      => self::label_for( $score ),
			'breakdown'  => is_array( $breakdown ) ? array_map( 'floatval', $breakdown ) : array(),
			'summary'    => (string) get_post_meta( $post_id, self::META_SUMMARY, true ),
			'provider'   => (string) get_post_meta( $post_id, self::META_PROVIDER, true ),
			'model'      => (string) get_post_meta( $post_id, self::META_MODEL, true ),
			'scanned_at' => (string) get_post_meta( $post_id, self::META_SCANNED_AT, true ),
			'reviewed'   => (bool) get_post_meta( $post_id, self::META_REVIEWED, true ),
		);
	}

	/**
	 * Validates and persists a score record.
	 *
	 * Only the keys present in $data are written, so callers can update a
	 * single field (e.g. the reviewed flag) without re-sending the rest.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $data    Any of: score, breakdown, summary,
	 *                                     provider, model, scanned_at, reviewed.
	 * @return array<string,mixed>|WP_Error The stored record on success.
	 */
	public static function save( $post_id, array $data ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'kraken_semantics_invalid_post',
				__( 'Cannot save a score: the post does not exist.', 'kraken-semantics' )
			);
		}

		if ( array_key_exists( 'score', $data ) ) {
			if ( ! is_numeric( $data['score'] ) ) {
				return new WP_Error(
					'kraken_semantics_invalid_score',
					__( 'The score must be a number between 0 and 100.', 'kraken-semantics' )
				);
			}
			update_post_meta( $post_id, self::META_SCORE, self::clamp( $data['score'] ) );
		}

		if ( array_key_exists( 'breakdown', $data ) && is_array( $data['breakdown'] ) ) {
			$breakdown = array();
			foreach ( $data['breakdown'] as $dimension => $value ) {
				if ( is_numeric( $value ) ) {
					// Sanitize dimension names so arbitrary REST input cannot
					// smuggle odd keys into the database.
					$breakdown[ sanitize_key( $dimension ) ] = self::clamp( $value );
				}
			}
			update_post_meta( $post_id, self::META_BREAKDOWN, $breakdown );
		}

		if ( array_key_exists( 'summary', $data ) ) {
			update_post_meta( $post_id, self::META_SUMMARY, sanitize_textarea_field( (string) $data['summary'] ) );
		}

		if ( array_key_exists( 'provider', $data ) ) {
			update_post_meta( $post_id, self::META_PROVIDER, sanitize_key( (string) $data['provider'] ) );
		}

		if ( array_key_exists( 'model', $data ) ) {
			update_post_meta( $post_id, self::META_MODEL, sanitize_text_field( (string) $data['model'] ) );
		}

		// Default the timestamp to "now" whenever a score is written without one.
		if ( array_key_exists( 'scanned_at', $data ) ) {
			update_post_meta( $post_id, self::META_SCANNED_AT, sanitize_text_field( (string) $data['scanned_at'] ) );
		} elseif ( array_key_exists( 'score', $data ) ) {
			update_post_meta( $post_id, self::META_SCANNED_AT, gmdate( 'c' ) );
		}

		if ( array_key_exists( 'reviewed', $data ) ) {
			if ( $data['reviewed'] ) {
				update_post_meta( $post_id, self::META_REVIEWED, '1' );
			} else {
				delete_post_meta( $post_id, self::META_REVIEWED );
			}
		}

		return self::get( $post_id );
	}

	/**
	 * Removes every score field from a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete( $post_id ) {
		foreach ( self::meta_keys() as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Maps a numeric score onto the configured high/medium/low band.
	 *
	 * @param float $score Score, 0–100.
	 * @return string 'high', 'medium', or 'low'.
	 */
	public static function label_for( $score ) {
		$settings = kraken_semantics_get_settings();

		if ( $score >= (float) $settings['threshold_high'] ) {
			return 'high';
		}

		return $score >= (float) $settings['threshold_low'] ? 'medium' : 'low';
	}

	/**
	 * Returns every meta key the plugin owns (used by delete and uninstall).
	 *
	 * @return string[] Meta keys.
	 */
	public static function meta_keys() {
		return array(
			self::META_SCORE,
			self::META_BREAKDOWN,
			self::META_SUMMARY,
			self::META_PROVIDER,
			self::META_MODEL,
			self::META_SCANNED_AT,
			self::META_REVIEWED,
		);
	}

	/**
	 * Clamps a numeric value into the 0–100 score range.
	 *
	 * @param mixed $value Numeric value.
	 * @return float Clamped value, rounded to one decimal place.
	 */
	protected static function clamp( $value ) {
		return round( max( 0.0, min( 100.0, (float) $value ) ), 1 );
	}
}
