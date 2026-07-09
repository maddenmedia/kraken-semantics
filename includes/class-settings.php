<?php
/**
 * Settings screen (Kraken Semantics → Settings).
 *
 * Storage still rides the core Settings API — one option
 * (`kraken_semantics_settings`) sanitized in one place — but the screen is
 * custom-rendered: provider cards with per-provider keys and models, a live
 * threshold band preview, and a badge preview.
 *
 * @package Kraken_Semantics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings page.
 */
class Kraken_Semantics_Settings {

	/** Option name holding the settings array. */
	const OPTION = 'kraken_semantics_settings';

	/** Settings page slug (submenu of the dashboard) and settings group. */
	const PAGE = 'kraken-semantics-settings';

	/**
	 * Scanner, used to list the available providers in the UI.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * Hook suffix of the settings screen, for targeted asset loading.
	 *
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Hooks the admin menu and settings registration.
	 *
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;

		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the page under the Kraken Semantics menu.
	 */
	public function add_page() {
		$this->hook_suffix = (string) add_submenu_page(
			Kraken_Semantics_Dashboard::MENU_SLUG,
			__( 'Kraken Semantics Settings', 'kraken-semantics' ),
			__( 'Settings', 'kraken-semantics' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the option with its sanitizer.
	 */
	public function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => kraken_semantics_default_settings(),
			)
		);
	}

	/**
	 * Loads the shared admin styles on the settings screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		// The dashboard stylesheet carries the shared card/token styles.
		wp_enqueue_style(
			'kraken-semantics-dashboard',
			KRAKEN_SEMANTICS_URL . 'assets/css/dashboard.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);

		// Badge styles, for the front-end badge preview.
		wp_enqueue_style(
			'kraken-semantics',
			KRAKEN_SEMANTICS_URL . 'assets/css/kraken-semantics.css',
			array(),
			KRAKEN_SEMANTICS_VERSION
		);

		wp_enqueue_script(
			'kraken-semantics-settings',
			KRAKEN_SEMANTICS_URL . 'assets/js/settings.js',
			array(),
			KRAKEN_SEMANTICS_VERSION,
			true
		);
	}

	/**
	 * Describes the per-provider configuration surface.
	 *
	 * @return array<string,array<string,string>> Provider slug => field map.
	 */
	protected function provider_fields() {
		return array(
			'claude' => array(
				'constant'    => 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY',
				'key_setting' => 'api_key',
				'model'       => 'model',
				'placeholder' => 'sk-ant-…',
				'hint'        => __( 'Models: claude-opus-4-8, claude-sonnet-5, claude-haiku-4-5-20251001', 'kraken-semantics' ),
			),
			'openai' => array(
				'constant'    => 'KRAKEN_SEMANTICS_OPENAI_API_KEY',
				'key_setting' => 'openai_api_key',
				'model'       => 'openai_model',
				'placeholder' => 'sk-…',
				'hint'        => __( 'Models: gpt-4o, gpt-4-turbo, gpt-4', 'kraken-semantics' ),
			),
			'gemini' => array(
				'constant'    => 'KRAKEN_SEMANTICS_GEMINI_API_KEY',
				'key_setting' => 'gemini_api_key',
				'model'       => 'gemini_model',
				'placeholder' => 'AIza…',
				'hint'        => __( 'Models: gemini-2.0-flash, gemini-1.5-pro, gemini-1.5-flash', 'kraken-semantics' ),
			),
		);
	}

	/**
	 * Sanitizes the submitted settings array.
	 *
	 * Every field is whitelisted and coerced; anything unknown is dropped.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed> Clean settings.
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = kraken_semantics_get_settings();
		$defaults = kraken_semantics_default_settings();

		$clean = array();

		// Post types: keep only slugs of post types that actually exist.
		$submitted           = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$clean['post_types'] = array_values(
			array_filter( array_map( 'sanitize_key', $submitted ), 'post_type_exists' )
		);

		$clean['auto_scan']     = ! empty( $input['auto_scan'] );
		$clean['display_badge'] = ! empty( $input['display_badge'] );

		$clean['provider'] = isset( $input['provider'] )
			? sanitize_key( $input['provider'] )
			: $defaults['provider'];

		// Parallel providers: keep only known provider slugs that are not the
		// primary and that actually have a key configured. Anything else (a
		// stale slug, the primary, a keyless provider) is dropped.
		$providers                   = $this->scanner->providers();
		$submitted_parallel          = isset( $input['parallel_providers'] ) ? (array) $input['parallel_providers'] : array();
		$clean['parallel_providers'] = array();

		foreach ( $submitted_parallel as $slug ) {
			$slug = sanitize_key( $slug );

			if (
				$slug === $clean['provider']
				|| ! isset( $providers[ $slug ] )
				|| in_array( $slug, $clean['parallel_providers'], true )
			) {
				continue;
			}

			if ( $providers[ $slug ]->is_configured() ) {
				$clean['parallel_providers'][] = $slug;
			}
		}

		// Per-provider keys and models. An empty key field means "keep the
		// stored key" — otherwise admins would wipe a key every time they
		// saved another setting. Models fall back to their defaults.
		foreach ( $this->provider_fields() as $fields ) {
			$key_setting   = $fields['key_setting'];
			$model_setting = $fields['model'];

			$clean[ $key_setting ] = ( isset( $input[ $key_setting ] ) && '' !== trim( $input[ $key_setting ] ) )
				? trim( sanitize_text_field( $input[ $key_setting ] ) )
				: $current[ $key_setting ];

			$clean[ $model_setting ] = ( isset( $input[ $model_setting ] ) && '' !== trim( $input[ $model_setting ] ) )
				? sanitize_text_field( $input[ $model_setting ] )
				: $defaults[ $model_setting ];
		}

		$clean['badge_position'] = ( isset( $input['badge_position'] ) && 'before' === $input['badge_position'] )
			? 'before'
			: 'after';

		$high = isset( $input['threshold_high'] ) ? (int) $input['threshold_high'] : $defaults['threshold_high'];
		$low  = isset( $input['threshold_low'] ) ? (int) $input['threshold_low'] : $defaults['threshold_low'];

		$high = max( 0, min( 100, $high ) );
		$low  = max( 0, min( 100, $low ) );

		// Keep the bands ordered; a low threshold above the high one would
		// make the medium band impossible.
		if ( $low > $high ) {
			list( $low, $high ) = array( $high, $low );
		}

		$clean['threshold_high'] = $high;
		$clean['threshold_low']  = $low;

		return $clean;
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page() {
		$settings = kraken_semantics_get_settings();
		?>
		<div class="wrap kraken-dash kraken-settings">
			<h1 class="kraken-settings__heading"><?php esc_html_e( 'Kraken Semantics settings', 'kraken-semantics' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( self::PAGE ); ?>

				<div class="kraken-card kraken-settings__card">
					<h2 class="kraken-card__title"><?php esc_html_e( 'Content', 'kraken-semantics' ); ?></h2>
					<p class="kraken-card__sub"><?php esc_html_e( 'What gets scored, and when.', 'kraken-semantics' ); ?></p>
					<?php $this->field_post_types( $settings ); ?>
					<?php $this->field_auto_scan( $settings ); ?>
				</div>

				<div class="kraken-card kraken-settings__card">
					<h2 class="kraken-card__title"><?php esc_html_e( 'Scanning provider', 'kraken-semantics' ); ?></h2>
					<p class="kraken-card__sub"><?php esc_html_e( 'Pick the AI service used by server-side scans. Each provider keeps its own API key and model.', 'kraken-semantics' ); ?></p>
					<?php $this->field_providers( $settings ); ?>
					<?php $this->field_parallel_providers( $settings ); ?>
					<p class="description kraken-settings__mcp-note">
						<?php
						echo wp_kses_post(
							__( 'Prefer not to store any API key? Score locally with Claude Code instead — the bundled MCP server pushes scores over the REST API. See <code>mcp/README.md</code>.', 'kraken-semantics' )
						);
						?>
					</p>
				</div>

				<div class="kraken-card kraken-settings__card">
					<h2 class="kraken-card__title"><?php esc_html_e( 'Display', 'kraken-semantics' ); ?></h2>
					<p class="kraken-card__sub"><?php esc_html_e( 'How scores appear to readers and how they band into High / Medium / Low.', 'kraken-semantics' ); ?></p>
					<?php $this->field_display_badge( $settings ); ?>
					<?php $this->field_thresholds( $settings ); ?>
				</div>

				<?php submit_button( __( 'Save settings', 'kraken-semantics' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field: post type checkboxes.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_post_types( $settings ) {
		$enabled = (array) $settings['post_types'];

		// Public post types only — scoring nav menu items makes no sense.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		echo '<div class="kraken-settings__row">';
		echo '<span class="kraken-settings__rowlabel">' . esc_html__( 'Post types', 'kraken-semantics' ) . '</span>';
		echo '<div class="kraken-settings__rowbody">';
		echo '<div class="kraken-checks">';
		foreach ( $post_types as $post_type ) {
			printf(
				'<label class="kraken-check"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $enabled, true ), true, false ),
				esc_html( $post_type->labels->singular_name )
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Content of these types can be scanned and can display a confidence badge.', 'kraken-semantics' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Field: auto-scan toggle.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_auto_scan( $settings ) {
		echo '<div class="kraken-settings__row">';
		echo '<span class="kraken-settings__rowlabel">' . esc_html__( 'Automatic scanning', 'kraken-semantics' ) . '</span>';
		echo '<div class="kraken-settings__rowbody">';
		printf(
			'<label class="kraken-check"><input type="checkbox" name="%1$s[auto_scan]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['auto_scan'], true, false ),
			esc_html__( 'Queue a background scan whenever an enabled post type is published or updated.', 'kraken-semantics' )
		);
		echo '</div></div>';
	}

	/**
	 * Field: provider selection cards with per-provider key + model.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_providers( $settings ) {
		$providers = $this->scanner->providers();
		$fields    = $this->provider_fields();

		echo '<div class="kraken-providers">';

		foreach ( $providers as $slug => $provider ) {
			$meta         = isset( $fields[ $slug ] ) ? $fields[ $slug ] : null;
			$selected     = $settings['provider'] === $slug;
			$configured   = $provider->is_configured();
			$has_constant = $meta && defined( $meta['constant'] ) && constant( $meta['constant'] );

			printf(
				'<label class="kraken-provider %s">',
				$selected ? 'is-selected' : ''
			);

			printf(
				'<span class="kraken-provider__head"><input type="radio" name="%1$s[provider]" value="%2$s" %3$s> <span class="kraken-provider__name">%4$s</span>%5$s</span>',
				esc_attr( self::OPTION ),
				esc_attr( $slug ),
				checked( $selected, true, false ),
				esc_html( $provider->get_label() ),
				$configured
					? '<span class="kraken-chip kraken-chip--ready">' . esc_html__( 'Ready', 'kraken-semantics' ) . '</span>'
					: '<span class="kraken-chip kraken-chip--missing">' . esc_html__( 'Needs API key', 'kraken-semantics' ) . '</span>'
			);

			if ( $meta ) {
				echo '<span class="kraken-provider__fields">';

				if ( $has_constant ) {
					printf(
						'<span class="description">%s</span>',
						esc_html(
							sprintf(
								/* translators: %s: constant name. */
								__( '%s is defined in wp-config.php and is being used.', 'kraken-semantics' ),
								$meta['constant']
							)
						)
					);
				} else {
					$stored = ! empty( $settings[ $meta['key_setting'] ] );
					printf(
						'<span class="kraken-provider__field"><span>%1$s</span><input type="password" name="%2$s[%3$s]" value="" placeholder="%4$s" autocomplete="new-password"></span>',
						esc_html__( 'API key', 'kraken-semantics' ),
						esc_attr( self::OPTION ),
						esc_attr( $meta['key_setting'] ),
						esc_attr( $stored ? __( 'Saved — leave blank to keep', 'kraken-semantics' ) : $meta['placeholder'] )
					);
				}

				printf(
					'<span class="kraken-provider__field"><span>%1$s</span><input type="text" name="%2$s[%3$s]" value="%4$s"></span>',
					esc_html__( 'Model', 'kraken-semantics' ),
					esc_attr( self::OPTION ),
					esc_attr( $meta['model'] ),
					esc_attr( $settings[ $meta['model'] ] )
				);

				printf( '<span class="description">%s</span>', esc_html( $meta['hint'] ) );

				echo '</span>';
			}

			echo '</label>';
		}

		echo '</div>';

		echo '<p class="description">' .
			esc_html__( 'For better security, define API keys as constants in wp-config.php instead of storing them here.', 'kraken-semantics' ) .
			'</p>';
	}

	/**
	 * Field: parallel-scoring provider checkboxes with a cost warning.
	 *
	 * Lets an editor pick extra providers to run alongside the primary on every
	 * scan. Only providers with a configured key can be selected; the primary
	 * always runs and is shown checked-and-disabled for context. The warning
	 * appears once a scan would call more than one provider.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_parallel_providers( $settings ) {
		$providers = $this->scanner->providers();
		$primary   = $settings['provider'];
		$selected  = (array) $settings['parallel_providers'];

		// How many providers a scan will actually call, to size the warning.
		$run_count = 0;
		foreach ( $providers as $slug => $provider ) {
			if ( $slug === $primary || ( in_array( $slug, $selected, true ) && $provider->is_configured() ) ) {
				$run_count++;
			}
		}

		echo '<div class="kraken-settings__row">';
		echo '<span class="kraken-settings__rowlabel">' . esc_html__( 'Parallel scoring', 'kraken-semantics' ) . '</span>';
		echo '<div class="kraken-settings__rowbody">';
		echo '<p class="description">' . esc_html__( 'Optionally score with additional providers on every scan, so you can compare how different LLMs rate the same content. The primary provider above is always scored.', 'kraken-semantics' ) . '</p>';

		echo '<div class="kraken-checks">';
		foreach ( $providers as $slug => $provider ) {
			$is_primary = ( $slug === $primary );
			$configured = $provider->is_configured();
			$disabled   = $is_primary || ! $configured;
			$checked    = $is_primary || in_array( $slug, $selected, true );

			if ( $is_primary ) {
				$note = ' <em>' . esc_html__( '(primary — always scored)', 'kraken-semantics' ) . '</em>';
			} elseif ( ! $configured ) {
				$note = ' <em>' . esc_html__( '(add an API key to enable)', 'kraken-semantics' ) . '</em>';
			} else {
				$note = '';
			}

			printf(
				'<label class="kraken-check"><input type="checkbox" name="%1$s[parallel_providers][]" value="%2$s" %3$s %4$s> %5$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $slug ),
				checked( $checked, true, false ),
				disabled( $disabled, true, false ),
				wp_kses_post( esc_html( $provider->get_label() ) . $note )
			);
		}
		echo '</div>';

		if ( $run_count > 1 ) {
			printf(
				'<p class="description kraken-settings__cost-warning">⚠️ <strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Heads up:', 'kraken-semantics' ),
				esc_html(
					sprintf(
						/* translators: %d: number of providers a scan will call. */
						_n(
							'each scan will call %d provider, using its API and incurring its cost.',
							'each scan will call %d providers, multiplying API usage and cost accordingly.',
							$run_count,
							'kraken-semantics'
						),
						$run_count
					)
				)
			);
		}

		echo '</div></div>';
	}

	/**
	 * Field: badge toggle, position, and preview.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_display_badge( $settings ) {
		echo '<div class="kraken-settings__row">';
		echo '<span class="kraken-settings__rowlabel">' . esc_html__( 'Front-end badge', 'kraken-semantics' ) . '</span>';
		echo '<div class="kraken-settings__rowbody">';

		printf(
			'<label class="kraken-check"><input type="checkbox" name="%1$s[display_badge]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['display_badge'], true, false ),
			esc_html__( 'Automatically show the confidence badge on scored content.', 'kraken-semantics' )
		);

		printf(
			'<p><select name="%1$s[badge_position]">
				<option value="after" %2$s>%3$s</option>
				<option value="before" %4$s>%5$s</option>
			</select></p>',
			esc_attr( self::OPTION ),
			selected( $settings['badge_position'], 'after', false ),
			esc_html__( 'After the content', 'kraken-semantics' ),
			selected( $settings['badge_position'], 'before', false ),
			esc_html__( 'Before the content', 'kraken-semantics' )
		);

		// A static sample so admins see what readers will see.
		echo '<div class="kraken-settings__badge-preview">';
		echo '<span class="description">' . esc_html__( 'Preview:', 'kraken-semantics' ) . '</span> ';
		printf(
			'<div class="kraken-semantics-badge kraken-semantics-badge--high"><span class="kraken-semantics-badge__score">87</span><span class="kraken-semantics-badge__text">%s</span></div>',
			esc_html__( 'Semantic confidence: High', 'kraken-semantics' )
		);
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Field: high/low thresholds with a live band preview.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	protected function field_thresholds( $settings ) {
		$low  = (int) $settings['threshold_low'];
		$high = (int) $settings['threshold_high'];

		echo '<div class="kraken-settings__row">';
		echo '<span class="kraken-settings__rowlabel">' . esc_html__( 'Score thresholds', 'kraken-semantics' ) . '</span>';
		echo '<div class="kraken-settings__rowbody">';

		printf(
			'<p class="kraken-settings__thresholds">
				<label>%3$s <input type="number" id="kraken-threshold-high" min="0" max="100" step="1" name="%1$s[threshold_high]" value="%2$d"></label>
				<label>%5$s <input type="number" id="kraken-threshold-low" min="0" max="100" step="1" name="%1$s[threshold_low]" value="%4$d"></label>
			</p>',
			esc_attr( self::OPTION ),
			$high,
			esc_html__( 'High ≥', 'kraken-semantics' ),
			$low,
			esc_html__( 'Medium ≥', 'kraken-semantics' )
		);

		// Live preview: settings.js resizes the segments as thresholds change.
		echo '<div class="kraken-bandpreview" id="kraken-bandpreview">';
		printf(
			'<span class="kraken-bandpreview__seg kraken-bandpreview__seg--low" style="width:%1$d%%"><i></i>%2$s</span>
			<span class="kraken-bandpreview__seg kraken-bandpreview__seg--medium" style="width:%3$d%%"><i></i>%4$s</span>
			<span class="kraken-bandpreview__seg kraken-bandpreview__seg--high" style="width:%5$d%%"><i></i>%6$s</span>',
			$low,
			esc_html__( 'Low', 'kraken-semantics' ),
			max( 0, $high - $low ),
			esc_html__( 'Medium', 'kraken-semantics' ),
			max( 0, 100 - $high ),
			esc_html__( 'High', 'kraken-semantics' )
		);
		echo '</div>';

		echo '<p class="description">' .
			esc_html__( 'Scores at or above the first value are “High”; at or above the second are “Medium”; anything lower is “Low”.', 'kraken-semantics' ) .
			'</p>';

		echo '</div></div>';
	}
}
