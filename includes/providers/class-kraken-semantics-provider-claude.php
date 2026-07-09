<?php
/**
 * Claude (Anthropic API) scan provider.
 *
 * Sends post content to the Anthropic Messages API and asks Claude to grade
 * it against a semantic-confidence rubric. Uses structured outputs
 * (output_config.format with a JSON schema) so the response is guaranteed to
 * be valid, parseable JSON — no brittle regex extraction.
 *
 * API reference: https://platform.claude.com/docs/en/api/messages
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores content by calling the Anthropic Messages API.
 */
class Kraken_Semantics_Provider_Claude implements Kraken_Semantics_Provider {

	/** Anthropic Messages API endpoint. */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/** Required anthropic-version header value. */
	const API_VERSION = '2023-06-01';

	/** Model used when the setting is empty. */
	const DEFAULT_MODEL = 'claude-opus-4-8';

	/**
	 * The scoring dimensions and what each one asks the model to judge.
	 *
	 * Keys become the breakdown meta keys; values are woven into the prompt.
	 *
	 * @var array<string,string>
	 */
	const DIMENSIONS = array(
		'factual_grounding'    => 'Are factual claims consistent with well-established knowledge, plausible, and free of fabrication?',
		'internal_consistency' => 'Does the content avoid contradicting itself in facts, numbers, names, or logic?',
		'source_attribution'   => 'Are non-obvious claims attributed, hedged appropriately, or otherwise verifiable?',
		'specificity'          => 'Is the content concrete and substantive rather than vague, generic filler?',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'claude';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Claude (Anthropic API)', 'kraken-semantics' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return '' !== $this->api_key();
	}

	/**
	 * Resolves the API key, preferring the wp-config.php constant.
	 *
	 * Defining KRAKEN_SEMANTICS_ANTHROPIC_API_KEY in wp-config.php keeps the
	 * secret out of the database (and out of database exports/backups).
	 *
	 * @return string API key, or empty string when unconfigured.
	 */
	protected function api_key() {
		if ( defined( 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY' ) && KRAKEN_SEMANTICS_ANTHROPIC_API_KEY ) {
			return (string) KRAKEN_SEMANTICS_ANTHROPIC_API_KEY;
		}

		$settings = kraken_semantics_get_settings();

		return trim( (string) $settings['api_key'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan( $content, WP_Post $post ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'kraken_semantics_no_api_key',
				__( 'No Anthropic API key is configured. Add one under Kraken Semantics → Settings, or define KRAKEN_SEMANTICS_ANTHROPIC_API_KEY in wp-config.php.', 'kraken-semantics' )
			);
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				// Scoring a long post can legitimately take a while; the
				// WordPress default of 5 seconds would fail constantly.
				'timeout' => 90,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $this->build_request_body( $content, $post ) ),
			)
		);

		// Transport-level failure (DNS, TLS, timeout…).
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		// API-level failure: surface Anthropic's error message when present.
		if ( 200 !== $status ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Anthropic API returned HTTP %d.', 'kraken-semantics' ),
					$status
				);

			return new WP_Error( 'kraken_semantics_api_error', $message, array( 'status' => $status ) );
		}

		return $this->parse_response( $data );
	}

	/**
	 * Builds the Messages API request body.
	 *
	 * Notes on the shape (current as of the Claude 5 / Opus 4.8 API):
	 * - `thinking: adaptive` lets the model decide how much to reason; the
	 *   default on Opus 4.8 when omitted is *no* thinking, so we opt in.
	 * - `output_config.format` (structured outputs) constrains the response
	 *   to our JSON schema, so json_decode() below cannot get non-JSON.
	 * - Sampling params like `temperature` are intentionally absent — they
	 *   were removed on current models and would cause a 400.
	 *
	 * @param string  $content Plain-text content to score.
	 * @param WP_Post $post    Post being scored (title gives the model context).
	 * @return array<string,mixed> Request body ready for wp_json_encode().
	 */
	protected function build_request_body( $content, WP_Post $post ) {
		$settings = kraken_semantics_get_settings();

		/**
		 * Filters the Claude model used for scanning.
		 *
		 * @param string  $model Model ID, e.g. 'claude-opus-4-8'.
		 * @param WP_Post $post  Post being scanned.
		 */
		$model = apply_filters(
			'kraken_semantics_claude_model',
			$settings['model'] ? $settings['model'] : self::DEFAULT_MODEL,
			$post
		);

		$rubric = '';
		foreach ( self::DIMENSIONS as $dimension => $question ) {
			$rubric .= "- {$dimension}: {$question}\n";
		}

		$system_prompt = <<<PROMPT
You are a semantic confidence evaluator for published web content. Grade how
semantically reliable a piece of content is — how much a careful reader could
trust it — on a 0-100 scale, where 100 is fully grounded, consistent, and
specific, and 0 is incoherent or fabricated.

Score each dimension from 0 to 100:
{$rubric}
The overall_score is your holistic judgment, not a mechanical average. Keep
the summary to one or two plain sentences a site editor can act on. Judge only
the text you are given; do not penalize content for being an excerpt.
PROMPT;

		/**
		 * Filters the system prompt sent to Claude.
		 *
		 * @param string  $system_prompt The evaluation instructions.
		 * @param WP_Post $post          Post being scanned.
		 */
		$system_prompt = apply_filters( 'kraken_semantics_claude_system_prompt', $system_prompt, $post );

		$user_message = sprintf(
			"Title: %s\n\nContent:\n%s",
			$post->post_title,
			$content
		);

		return array(
			'model'         => $model,

			/**
			 * Filters the max_tokens sent to the API.
			 *
			 * The cap covers thinking plus the JSON answer; 16000 leaves
			 * comfortable headroom while staying inside HTTP timeouts.
			 *
			 * @param int $max_tokens Maximum tokens for the response.
			 */
			'max_tokens'    => (int) apply_filters( 'kraken_semantics_claude_max_tokens', 16000 ),

			// Let Claude decide how much reasoning the content warrants.
			'thinking'      => array( 'type' => 'adaptive' ),

			'system'        => $system_prompt,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),

			// Structured outputs: the API guarantees the text block matches
			// this schema. Note: JSON Schema numeric min/max constraints are
			// not supported here, so ranges live in descriptions and values
			// are clamped again in parse_response().
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $this->response_schema(),
				),
			),
		);
	}

	/**
	 * JSON schema the model's answer must conform to.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	protected function response_schema() {
		$dimension_properties = array();
		foreach ( array_keys( self::DIMENSIONS ) as $dimension ) {
			$dimension_properties[ $dimension ] = array(
				'type'        => 'integer',
				'description' => 'Score from 0 to 100.',
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'overall_score' => array(
					'type'        => 'integer',
					'description' => 'Holistic semantic confidence score from 0 (untrustworthy) to 100 (fully reliable).',
				),
				'breakdown'     => array(
					'type'                 => 'object',
					'properties'           => $dimension_properties,
					'required'             => array_keys( self::DIMENSIONS ),
					'additionalProperties' => false,
				),
				'summary'       => array(
					'type'        => 'string',
					'description' => 'One or two sentences explaining the score for a site editor.',
				),
			),
			'required'             => array( 'overall_score', 'breakdown', 'summary' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Extracts the scan result from a successful API response.
	 *
	 * @param array<string,mixed>|null $data Decoded response body.
	 * @return array<string,mixed>|WP_Error Scan result or error.
	 */
	protected function parse_response( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'kraken_semantics_bad_response',
				__( 'The Anthropic API returned an unreadable response.', 'kraken-semantics' )
			);
		}

		// Current Claude models can decline a request for safety reasons.
		// That arrives as HTTP 200 with stop_reason "refusal" — treat it as
		// a scan failure rather than reading (possibly empty) content.
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error(
				'kraken_semantics_refusal',
				__( 'Claude declined to evaluate this content.', 'kraken-semantics' )
			);
		}

		// A max_tokens stop means the JSON may be truncated — do not trust it.
		if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
			return new WP_Error(
				'kraken_semantics_truncated',
				__( 'The response was truncated before the score was complete. Raise the kraken_semantics_claude_max_tokens filter value and retry.', 'kraken-semantics' )
			);
		}

		// The content array can contain thinking blocks before the text
		// block, so find the first block of type "text" rather than [0].
		$json = null;
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$json = json_decode( $block['text'], true );
				break;
			}
		}

		if ( ! is_array( $json ) || ! isset( $json['overall_score'], $json['breakdown'], $json['summary'] ) ) {
			return new WP_Error(
				'kraken_semantics_bad_response',
				__( 'The model response did not contain a valid score payload.', 'kraken-semantics' )
			);
		}

		// The schema constrains types but not numeric ranges, so clamp here.
		$clamp = function ( $value ) {
			return max( 0, min( 100, (int) $value ) );
		};

		return array(
			'score'     => $clamp( $json['overall_score'] ),
			'breakdown' => array_map( $clamp, (array) $json['breakdown'] ),
			'summary'   => (string) $json['summary'],
			'model'     => isset( $data['model'] ) ? (string) $data['model'] : '',
		);
	}
}
