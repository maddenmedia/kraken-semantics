# Kraken Semantics

Store, scan, and display AI semantic confidence scores for any WordPress post type. Ships with a Claude-powered scanner, a REST API for external scoring tools, front-end badges, and WP-CLI commands.

## Features

- **AI-Powered Scanning**: Built-in Claude-based scanner that analyzes post content and generates semantic confidence scores
- **Flexible Providers**: Extensible provider interface for custom AI models or external scoring services
- **REST API**: Push scores from external tools, read scores, or trigger scans via authenticated endpoints
- **Frontend Badges**: Automatically display confidence badges on your website with configurable styling
- **WP-CLI Support**: Manage scans and settings from the command line
- **Per-Dimension Scoring**: Break down confidence into specific dimensions (e.g., clarity, coherence, accuracy)
- **Post Type Support**: Works with any custom post type, not just posts
- **Auto-Scanning**: Optional automatic scanning when posts are published or updated
- **Human Review Tracking**: Mark scores as reviewed by human editors

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Anthropic API key (for the built-in Claude scanner)

## Installation

1. Download or clone this plugin into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/maddenmedia/kraken-semantics.git wp-content/plugins/kraken-semantics
   ```

2. Activate the plugin from the WordPress admin panel or via WP-CLI:
   ```bash
   wp plugin activate kraken-semantics
   ```

3. Configure your API key and settings in the plugin settings page (Settings → Kraken Semantics)

## Configuration

### API Key

Set your Anthropic API key in one of these ways:

1. **Via wp-config.php (recommended)**:
   ```php
   define( 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY', 'your-api-key-here' );
   ```

2. **Via the Settings page**: Settings → Kraken Semantics → API Key

The constant always takes precedence over the stored setting.

### Settings

Access plugin settings from **Settings → Kraken Semantics** in the WordPress admin:

- **Post Types**: Select which post types can carry confidence scores
- **Provider**: Choose the scanning provider (Claude or custom implementations)
- **Model**: Select the Claude model to use (default: claude-opus-4-8)
- **Auto-Scan**: Automatically queue a scan when posts are published/updated
- **Display Badge**: Show confidence badges on the front-end automatically
- **Badge Position**: Place badges before or after content
- **Threshold: High**: Score threshold for "high" confidence (default: 80)
- **Threshold: Low**: Score threshold for "low" confidence (default: 50)

## Usage

### Template Tags

Display scores and badges in your theme:

```php
<?php
// Get the confidence score for the current post (0–100)
$score = kraken_semantics_get_score();
echo $score ? "Confidence: {$score}%" : 'Not yet scored';

// Get full score data including breakdown and summary
$data = kraken_semantics_get_score_data();
if ( $data ) {
    echo $data['label']; // 'high', 'medium', or 'low'
    echo $data['summary']; // One-line rationale
    print_r( $data['breakdown'] ); // Per-dimension scores
}

// Output the badge HTML
kraken_semantics_badge();

// Or return the badge HTML instead of echoing it
$badge_html = kraken_semantics_badge( null, false );
?>
```

All template tags accept an optional post ID or `WP_Post` object; they default to the current post in the loop.

### REST API

The plugin exposes three endpoints under the `kraken-semantics/v1` namespace:

#### Read a Post's Score
```
GET /wp-json/kraken-semantics/v1/posts/<id>/score
```

**Access**: Public (if the post is readable by the user)

**Response**:
```json
{
  "id": 123,
  "score": 87.5,
  "label": "high",
  "summary": "Well-structured content with clear argumentation.",
  "breakdown": {
    "clarity": 90,
    "coherence": 85,
    "accuracy": 80
  },
  "provider": "claude",
  "model": "claude-opus-4-8",
  "scanned_at": "2026-07-08T14:30:00Z",
  "reviewed": false
}
```

#### Push a Score from an External Tool
```
POST /wp-json/kraken-semantics/v1/posts/<id>/score
```

**Access**: Authenticated user with `edit_posts` capability

**Parameters**:
- `score` (number, required): Overall confidence score, 0–100
- `breakdown` (object, optional): Per-dimension scores, 0–100 each
- `summary` (string, optional): Short rationale for the score
- `model` (string, optional): Model identifier for attribution

**Example**:
```bash
curl -X POST https://example.com/wp-json/kraken-semantics/v1/posts/123/score \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "score": 75,
    "breakdown": {
      "clarity": 70,
      "coherence": 75,
      "accuracy": 80
    },
    "summary": "Generally clear but could benefit from better structure.",
    "model": "custom-ai-v2"
  }'
```

#### Trigger a Scan
```
POST /wp-json/kraken-semantics/v1/posts/<id>/scan
```

**Access**: Authenticated user with `edit_posts` capability

**Parameters**: None (the built-in scanner runs immediately in most cases, or queues in background depending on load)

**Response**:
```json
{
  "status": "queued",
  "id": 123,
  "message": "Scan queued for processing."
}
```

#### Authentication

For authenticated endpoints, use any WordPress core-supported auth scheme:
- Application Passwords (simplest for external tools)
- OAuth 2.0
- Basic Auth (if enabled)

Application Passwords are recommended. Create one from your user profile page.

### WP-CLI Commands

Manage scans and settings from the command line:

```bash
# Scan a specific post
wp kraken-semantics scan 123

# Scan all posts (one-time full sweep)
wp kraken-semantics scan-all

# Scan posts of a specific post type
wp kraken-semantics scan --post-type=page

# Get a post's score
wp kraken-semantics get-score 123

# Check plugin settings
wp kraken-semantics settings

# Update settings (API key, model, thresholds, etc.)
wp kraken-semantics update-settings --model=claude-sonnet-5

# Clear all scores
wp kraken-semantics clear-scores
```

## Extending the Plugin

### Creating a Custom Provider

Implement the `Kraken_Semantics_Provider` interface to add your own scoring engine:

```php
<?php
/**
 * Custom AI provider for Kraken Semantics
 */
class Kraken_Semantics_Provider_Custom implements Kraken_Semantics_Provider {

    /**
     * Unique identifier for this provider.
     */
    public function get_slug() {
        return 'custom-provider';
    }

    /**
     * Human-readable name.
     */
    public function get_name() {
        return 'My Custom AI Service';
    }

    /**
     * Scan post content and return a score.
     *
     * @param WP_Post $post Post to scan.
     * @return array {
     *     @type float                $score    Overall score, 0–100.
     *     @type array<string,float>  $breakdown Per-dimension scores.
     *     @type string               $summary   One-line rationale.
     *     @type string               $model     Model identifier for attribution.
     * }
     * @throws Exception On API errors.
     */
    public function scan( WP_Post $post ) {
        // Your scanning logic here
        return array(
            'score'     => 78.5,
            'breakdown' => array(
                'clarity'   => 80,
                'coherence' => 78,
            ),
            'summary'   => 'Content is well-organized.',
            'model'     => 'custom-v1',
        );
    }
}

// Register your provider
add_filter(
    'kraken_semantics_providers',
    function( $providers ) {
        $providers['custom-provider'] = new Kraken_Semantics_Provider_Custom();
        return $providers;
    }
);
```

## Database Schema

Scores are stored as post meta under the `_kraken_semantics_score` key. Each record contains:

```php
array(
    'score'      => 87.5,        // 0–100
    'label'      => 'high',      // 'high', 'medium', or 'low'
    'breakdown'  => array( ... ), // Per-dimension scores
    'summary'    => '...',        // One-line rationale
    'provider'   => 'claude',     // Provider slug
    'model'      => 'claude-opus-4-8', // Model identifier
    'scanned_at' => '2026-07-08T14:30:00Z', // ISO 8601 timestamp (GMT)
    'reviewed'   => false,        // Human review flag
)
```

## Uninstallation

When you delete the plugin from WordPress:

1. All plugin scores and settings are permanently removed from the database
2. The `uninstall.php` hook cleans up all `kraken_semantics_*` options and post meta
3. Other plugins' functionality is not affected

To simply deactivate (keeping scores intact), use "Deactivate" instead of "Delete".

## Development

This plugin is intentionally dependency-free. There is no `composer.json` or NPM build process — it's a straightforward WordPress plugin ready to use as-is.

## Hooks and Filters

### Filters

**`kraken_semantics_post_types`**
- Filter the post types the plugin operates on
- Default: value from settings
- Usage: `apply_filters( 'kraken_semantics_post_types', $post_types )`

**`kraken_semantics_providers`**
- Register custom scoring providers
- Default: array with the built-in Claude provider
- Usage: `apply_filters( 'kraken_semantics_providers', $providers )`

**`kraken_semantics_badge_html`**
- Customize the front-end badge HTML
- Default: themed badge with score, label, and summary
- Usage: `apply_filters( 'kraken_semantics_badge_html', $html, $post_id )`

### Actions

**`kraken_semantics_score_saved`**
- Fired after a score is saved to the database
- Usage: `do_action( 'kraken_semantics_score_saved', $post_id, $score_data )`

**`kraken_semantics_scan_complete`**
- Fired after a scan is completed
- Usage: `do_action( 'kraken_semantics_scan_complete', $post_id, $score_data, $error )`

## Frequently Asked Questions

**Q: Does this plugin store my API key securely?**
A: The recommended approach is to define your API key in `wp-config.php` as a constant, which keeps it out of the database. If you store it in settings, it's stored in the database with no additional encryption—treat your WordPress database like a secret.

**Q: Can I use a different AI model?**
A: Yes. In settings, choose any Claude model available through your Anthropic API account. Or implement the `Kraken_Semantics_Provider` interface to integrate a different service entirely.

**Q: What happens if the API call fails?**
A: Failed scans are logged but don't block post publishing. If auto-scan is enabled and a scan fails, you can retry manually or via WP-CLI.

**Q: Can I score custom post types?**
A: Yes. Select them in **Settings → Kraken Semantics → Post Types**.

**Q: How are scores displayed on the front-end?**
A: Scores appear as badges, styled with the confidence label (high/medium/low). You can customize the styling via CSS or disable automatic display and use the `kraken_semantics_badge()` template tag in your theme.

## Support

For bug reports, feature requests, and documentation, visit the [GitHub repository](https://github.com/maddenmedia/kraken-semantics).

## License

This plugin is licensed under the GPL-2.0-or-later license. See [LICENSE](LICENSE) for details.

## Credits

**Kraken Semantics** is developed and maintained by [Madden Media](https://maddenmedia.com).
