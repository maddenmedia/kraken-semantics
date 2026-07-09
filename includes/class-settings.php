<?php
/**
 * Settings screen (Settings → Kraken Semantics).
 *
 * Built on the core Settings API: one option (`kraken_semantics_settings`)
 * holding the whole configuration array, sanitized in one place.
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

	/** Settings page slug. */
	const PAGE = 'kraken-semantics';

	/**
	 * Scanner, used to list the available providers in the UI.
	 *
	 * @var Kraken_Semantics_Scanner
	 */
	protected $scanner;

	/**
	 * Hooks the admin menu and settings registration.
	 *
	 * @param Kraken_Semantics_Scanner $scanner Shared scanner instance.
	 */
	public function __construct( Kraken_Semantics_Scanner $scanner ) {
		$this->scanner = $scanner;

		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_page() {
		add_options_page(
			__( 'Kraken Semantics', 'kraken-semantics' ),
			__( 'Kraken Semantics', 'kraken-semantics' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the option, sections, and fields.
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

		// --- Section: content ------------------------------------------------
		add_settings_section(
			'kraken_semantics_content',
			__( 'Content', 'kraken-semantics' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'post_types',
			__( 'Post types', 'kraken-semantics' ),
			array( $this, 'field_post_types' ),
			self::PAGE,
			'kraken_semantics_content'
		);

		add_settings_field(
			'auto_scan',
			__( 'Automatic scanning', 'kraken-semantics' ),
			array( $this, 'field_auto_scan' ),
			self::PAGE,
			'kraken_semantics_content'
		);

		// --- Section: scanning -----------------------------------------------
		add_settings_section(
			'kraken_semantics_scanning',
			__( 'Scanning provider', 'kraken-semantics' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'provider',
			__( 'Provider', 'kraken-semantics' ),
			array( $this, 'field_provider' ),
			self::PAGE,
			'kraken_semantics_scanning'
		);

		add_settings_field(
			'api_key',
			__( 'Anthropic API key', 'kraken-semantics' ),
			array( $this, 'field_api_key' ),
			self::PAGE,
			'kraken_semantics_scanning'
		);

		add_settings_field(
			'model',
			__( 'Model', 'kraken-semantics' ),
			array( $this, 'field_model' ),
			self::PAGE,
			'kraken_semantics_scanning'
		);

		// --- Section: display ------------------------------------------------
		add_settings_section(
			'kraken_semantics_display',
			__( 'Display', 'kraken-semantics' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'display_badge',
			__( 'Front-end badge', 'kraken-semantics' ),
			array( $this, 'field_display_badge' ),
			self::PAGE,
			'kraken_semantics_display'
		);

		add_settings_field(
			'thresholds',
			__( 'Score thresholds', 'kraken-semantics' ),
			array( $this, 'field_thresholds' ),
			self::PAGE,
			'kraken_semantics_display'
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

		// An empty API key field means "keep the stored key" — otherwise
		// admins would wipe the key every time they saved another setting.
		$clean['api_key'] = ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) )
			? trim( sanitize_text_field( $input['api_key'] ) )
			: $current['api_key'];

		$clean['model'] = isset( $input['model'] ) && '' !== trim( $input['model'] )
			? sanitize_text_field( $input['model'] )
			: $defaults['model'];

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
	 * Renders the settings page wrapper.
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kraken Semantics', 'kraken-semantics' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field: post type checkboxes.
	 */
	public function field_post_types() {
		$settings = kraken_semantics_get_settings();
		$enabled  = (array) $settings['post_types'];

		// Public post types only — scoring nav menu items makes no sense.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $enabled, true ), true, false ),
				esc_html( $post_type->labels->singular_name )
			);
		}

		echo '<p class="description">' . esc_html__( 'Content of these types can be scanned and can display a confidence badge.', 'kraken-semantics' ) . '</p>';
	}

	/**
	 * Field: auto-scan toggle.
	 */
	public function field_auto_scan() {
		$settings = kraken_semantics_get_settings();

		printf(
			'<label><input type="checkbox" name="%1$s[auto_scan]" value="1" %2$s> %3$s</label>',
			esc_attr( self::OPTION ),
			checked( $settings['auto_scan'], true, false ),
			esc_html__( 'Queue a background scan whenever an enabled post type is published or updated.', 'kraken-semantics' )
		);
	}

	/**
	 * Field: provider select.
	 */
	public function field_provider() {
		$settings = kraken_semantics_get_settings();

		echo '<select name="' . esc_attr( self::OPTION ) . '[provider]">';
		foreach ( $this->scanner->providers() as $slug => $provider ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $settings['provider'], $slug, false ),
				esc_html( $provider->get_label() )
			);
		}
		echo '</select>';
	}

	/**
	 * Field: API key.
	 */
	public function field_api_key() {
		$settings     = kraken_semantics_get_settings();
		$has_constant = defined( 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY' ) && KRAKEN_SEMANTICS_ANTHROPIC_API_KEY;

		if ( $has_constant ) {
			// The constant wins over the stored option, so the field would mislead.
			echo '<p><code>KRAKEN_SEMANTICS_ANTHROPIC_API_KEY</code> ' .
				esc_html__( 'is defined in wp-config.php and is being used.', 'kraken-semantics' ) . '</p>';
			return;
		}

		printf(
			'<input type="password" class="regular-text" name="%1$s[api_key]" value="" placeholder="%2$s" autocomplete="new-password">',
			esc_attr( self::OPTION ),
			esc_attr( $settings['api_key'] ? __( 'Saved — leave blank to keep', 'kraken-semantics' ) : 'sk-ant-…' )
		);

		echo '<p class="description">' .
			esc_html__( 'Stored in the database. For better security define KRAKEN_SEMANTICS_ANTHROPIC_API_KEY in wp-config.php instead.', 'kraken-semantics' ) .
			'</p>';
	}

	/**
	 * Field: model.
	 */
	public function field_model() {
		$settings = kraken_semantics_get_settings();

		printf(
			'<input type="text" class="regular-text" name="%1$s[model]" value="%2$s">',
			esc_attr( self::OPTION ),
			esc_attr( $settings['model'] )
		);

		echo '<p class="description">' .
			esc_html__( 'Anthropic model ID used by the built-in scanner. Default: claude-opus-4-8.', 'kraken-semantics' ) .
			'</p>';
	}

	/**
	 * Field: badge toggle + position.
	 */
	public function field_display_badge() {
		$settings = kraken_semantics_get_settings();

		printf(
			'<label><input type="checkbox" name="%1$s[display_badge]" value="1" %2$s> %3$s</label><br><br>',
			esc_attr( self::OPTION ),
			checked( $settings['display_badge'], true, false ),
			esc_html__( 'Automatically show the confidence badge on scored content.', 'kraken-semantics' )
		);

		printf(
			'<select name="%1$s[badge_position]">
				<option value="after" %2$s>%3$s</option>
				<option value="before" %4$s>%5$s</option>
			</select>',
			esc_attr( self::OPTION ),
			selected( $settings['badge_position'], 'after', false ),
			esc_html__( 'After the content', 'kraken-semantics' ),
			selected( $settings['badge_position'], 'before', false ),
			esc_html__( 'Before the content', 'kraken-semantics' )
		);
	}

	/**
	 * Field: high/low thresholds.
	 */
	public function field_thresholds() {
		$settings = kraken_semantics_get_settings();

		printf(
			'<label>%3$s <input type="number" min="0" max="100" step="1" name="%1$s[threshold_high]" value="%2$d" style="width:70px;"></label> &nbsp; ',
			esc_attr( self::OPTION ),
			(int) $settings['threshold_high'],
			esc_html__( 'High ≥', 'kraken-semantics' )
		);

		printf(
			'<label>%3$s <input type="number" min="0" max="100" step="1" name="%1$s[threshold_low]" value="%2$d" style="width:70px;"></label>',
			esc_attr( self::OPTION ),
			(int) $settings['threshold_low'],
			esc_html__( 'Medium ≥', 'kraken-semantics' )
		);

		echo '<p class="description">' .
			esc_html__( 'Scores at or above the first value are “High”; at or above the second are “Medium”; anything lower is “Low”.', 'kraken-semantics' ) .
			'</p>';
	}
}
