<?php
/**
 * REST API surface.
 *
 * Namespace: kraken-semantics/v1
 *
 *   GET  /posts/<id>/score  Read a post's score (public for readable posts).
 *   POST /posts/<id>/score  Push a score from an external tool (edit_post cap).
 *   POST /posts/<id>/scan   Run the built-in scanner now (edit_post cap).
 *
 * Authenticated routes work with any core-supported auth scheme; WordPress
 * Application Passwords are the simplest fit for external scoring pipelines.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the plugin's REST routes.
 */
class Kraken_Semantics_Rest_Api {

	/** REST namespace shared by every route. */
	const API_NAMESPACE = 'kraken-semantics/v1';

	/**
	 * Scanner used by the /scan route.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * Hooks route registration.
	 *
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the three routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/posts/(?P<id>\d+)/score',
			array(
				// Read a score.
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_score' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				// Push a score from an external tool.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'push_score' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'score'     => array(
							'description' => __( 'Overall confidence score, 0–100.', 'kraken-semantics' ),
							'type'        => 'number',
							'required'    => true,
							'minimum'     => 0,
							'maximum'     => 100,
						),
						'breakdown' => array(
							'description'          => __( 'Per-dimension scores, 0–100 each.', 'kraken-semantics' ),
							'type'                 => 'object',
							'required'             => false,
							'additionalProperties' => array( 'type' => 'number' ),
						),
						'summary'   => array(
							'description' => __( 'Short rationale for the score.', 'kraken-semantics' ),
							'type'        => 'string',
							'required'    => false,
						),
						'provider'  => array(
							'description' => __( 'Identifier of the tool that produced the score.', 'kraken-semantics' ),
							'type'        => 'string',
							'required'    => false,
							'default'     => 'external',
						),
						'model'     => array(
							'description' => __( 'Model identifier, when applicable.', 'kraken-semantics' ),
							'type'        => 'string',
							'required'    => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/posts/(?P<id>\d+)/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_scan' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);
	}

	/**
	 * Permission: reading a score.
	 *
	 * Public for published posts (the badge shows the score publicly anyway);
	 * otherwise the caller needs edit access to the post.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool|WP_Error
	 */
	public function can_read( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post ) {
			return new WP_Error(
				'kraken_semantics_not_found',
				__( 'Post not found.', 'kraken-semantics' ),
				array( 'status' => 404 )
			);
		}

		if ( 'publish' === $post->post_status && empty( $post->post_password ) ) {
			return true;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Permission: writing a score or triggering a scan.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post ) {
			return new WP_Error(
				'kraken_semantics_not_found',
				__( 'Post not found.', 'kraken-semantics' ),
				array( 'status' => 404 )
			);
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * GET /posts/<id>/score
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_score( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$data    = Kraken_Semantics_Scores::get( $post_id );

		if ( null === $data ) {
			return new WP_Error(
				'kraken_semantics_no_score',
				__( 'This post has not been scored yet.', 'kraken-semantics' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array_merge( array( 'post_id' => $post_id ), $data ) );
	}

	/**
	 * POST /posts/<id>/score — store a score pushed by an external tool.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_score( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];

		$saved = Kraken_Semantics_Scores::save(
			$post_id,
			array(
				'score'     => $request['score'],
				'breakdown' => (array) $request->get_param( 'breakdown' ),
				'summary'   => (string) $request->get_param( 'summary' ),
				'provider'  => (string) $request->get_param( 'provider' ),
				'model'     => (string) $request->get_param( 'model' ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			$saved->add_data( array( 'status' => 400 ) );
			return $saved;
		}

		return rest_ensure_response( array_merge( array( 'post_id' => $post_id ), $saved ) );
	}

	/**
	 * POST /posts/<id>/scan — run the configured provider now.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_scan( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$result  = $this->scanner->scan( $post_id );

		if ( is_wp_error( $result ) ) {
			// Distinguish "you configured it wrong" (400) from upstream API
			// trouble (502) so callers can decide whether retrying helps.
			$config_errors = array(
				'kraken_semantics_no_api_key',
				'kraken_semantics_provider_unconfigured',
				'kraken_semantics_unknown_provider',
				'kraken_semantics_empty_content',
				'kraken_semantics_invalid_post',
			);

			$status = in_array( $result->get_error_code(), $config_errors, true ) ? 400 : 502;
			$result->add_data( array( 'status' => $status ) );

			return $result;
		}

		return rest_ensure_response( array_merge( array( 'post_id' => $post_id ), $result ) );
	}
}
