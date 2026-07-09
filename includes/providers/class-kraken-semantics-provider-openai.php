<?php
/**
 * OpenAI scan provider.
 *
 * Sends post content to the OpenAI API and asks GPT to grade it against a
 * semantic-confidence rubric. Uses JSON mode so the response is guaranteed to
 * be valid, parseable JSON.
 *
 * API reference: https://platform.openai.com/docs/guides/structured-outputs
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores content by calling the OpenAI API.
 */
class Kraken_Semantics_Provider_OpenAI implements Kraken_Semantics_Provider {

	/** OpenAI Chat Completions endpoint. */
	const API_URL = 'https://api.openai.com/v1/chat/completions';

	/** Model used when the setting is empty. */
	const DEFAULT_MODEL = 'gpt-4o';

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
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'OpenAI (GPT)', 'kraken-semantics' );
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
	 * @return string API key, or empty string when unconfigured.
	 */
	protected function api_key() {
		if ( defined( 'KRAKEN_SEMANTICS_OPENAI_API_KEY' ) && KRAKEN_SEMANTICS_OPENAI_API_KEY ) {
			return (string) KRAKEN_SEMANTICS_OPENAI_API_KEY;
		}

		$settings = kraken_semantics_get_settings();

		return isset( $settings['openai_api_key'] ) ? trim( (string) $settings['openai_api_key'] ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan( $content, WP_Post $post ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'kraken_semantics_no_api_key',
				__( 'No OpenAI API key is configured. Add one under Settings → Kraken Semantics, or define KRAKEN_SEMANTICS_OPENAI_API_KEY in wp-config.php.', 'kraken-semantics' )
			);
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 90,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $this->api_key(),
				),
				'body'    => wp_json_encode( $this->build_request_body( $content, $post ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: sprintf(
					__( 'The OpenAI API returned HTTP %d.', 'kraken-semantics' ),
					$status
				);

			return new WP_Error( 'kraken_semantics_api_error', $message, array( 'status' => $status ) );
		}

		return $this->parse_response( $data );
	}

	/**
	 * Builds the Chat Completions API request body.
	 *
	 * @param string  $content Plain-text content to score.
	 * @param WP_Post $post    Post being scored.
	 * @return array<string,mixed> Request body ready for wp_json_encode().
	 */
	protected function build_request_body( $content, WP_Post $post ) {
		$settings = kraken_semantics_get_settings();

		$model = apply_filters(
			'kraken_semantics_openai_model',
			isset( $settings['openai_model'] ) ? $settings['openai_model'] : self::DEFAULT_MODEL,
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

Respond with a JSON object containing:
- overall_score: integer from 0 to 100
- breakdown: object with keys for each dimension above, values 0-100
- summary: string with one or two sentences explaining the score
PROMPT;

		$system_prompt = apply_filters( 'kraken_semantics_openai_system_prompt', $system_prompt, $post );

		$user_message = sprintf(
			"Title: %s\n\nContent:\n%s",
			$post->post_title,
			$content
		);

		return array(
			'model'       => $model,
			'temperature' => 0,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),
			'max_tokens'  => (int) apply_filters( 'kraken_semantics_openai_max_tokens', 1000 ),
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
				__( 'The OpenAI API returned an unreadable response.', 'kraken-semantics' )
			);
		}

		$message_content = null;
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$message_content = $data['choices'][0]['message']['content'];
		}

		if ( ! $message_content ) {
			return new WP_Error(
				'kraken_semantics_bad_response',
				__( 'The OpenAI API response did not contain message content.', 'kraken-semantics' )
			);
		}

		$json = json_decode( $message_content, true );

		if ( ! is_array( $json ) || ! isset( $json['overall_score'], $json['breakdown'], $json['summary'] ) ) {
			return new WP_Error(
				'kraken_semantics_bad_response',
				__( 'The model response did not contain a valid score payload.', 'kraken-semantics' )
			);
		}

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
