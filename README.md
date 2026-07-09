# Kraken Semantics

Discover how well your content hits the mark when AI reads it. Kraken Semantics gives every post a quantifiable semantic confidence score (0–100) with a per-dimension breakdown, so content teams — DMOs, publishers, anyone whose content needs to be trusted by AI search and answer engines — can find weak content, rewrite it, rescan it, and watch the score improve over time.

## Features

- **Insights Dashboard**: Average score, coverage, band split, score distribution, per-dimension averages, a 12-week trend line, lowest-scoring "needs attention" posts, and the biggest post-rewrite improvements
- **Score History & Deltas**: Every scan is logged per post — the editor shows the change since the last scan and a history sparkline, so the rewrite → rescan → improve loop is visible everywhere
- **Score Locally with Claude Code (MCP)**: A bundled MCP server lets Claude Code score posts with cheap local Haiku agents — no AI API key stored in WordPress at all
- **AI-Agnostic**: Choose between Claude (Anthropic), OpenAI (GPT), or Google Gemini — or add your own provider
- **Multiple Built-in Providers**: Claude Opus, OpenAI GPT-4o, and Google Gemini 2.0 Flash included
- **REST API**: Push scores from external tools, read scores, or trigger scans via authenticated endpoints
- **Frontend Badges**: Automatically display confidence badges on your website with configurable styling
- **WP-CLI Support**: Manage scans and settings from the command line
- **Per-Dimension Scoring**: Break down confidence into specific dimensions (factual grounding, internal consistency, source attribution, specificity)
- **Post Type Support**: Works with any custom post type, not just posts
- **Auto-Scanning**: Optional automatic scanning when posts are published or updated
- **Extensible**: Implement the provider interface to integrate custom AI services
- **Kraken Hub Integration**: If [Kraken Core](https://github.com/maddenmedia/kraken-core) is also installed, Kraken Semantics automatically contributes a quick-link card and a live stats widget to the shared Kraken Hub dashboard — no configuration needed, and nothing changes if Kraken Core isn't present

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

3. Configure your API key and settings in the plugin settings page (Kraken Semantics → Settings), or skip API keys entirely and [score locally with Claude Code](#score-locally-with-claude-code-mcp)

## Dashboard

**Kraken Semantics → Dashboard** is the insight surface: how confidently can AI trust this site's content, where is it weakest, and is it getting better?

- **Average score** ring, **coverage** (scored vs total posts), **band split** (High/Medium/Low), and **30-day change** across rescanned posts
- **Score distribution** histogram with your Low/Medium/High threshold ranges marked
- **Average score over time** — a weekly trend of every scan in the last 12 weeks
- **Confidence by dimension** — the lowest bar is where rewrites move the needle most
- **Needs attention** (lowest scores, your rewrite candidates) and **biggest improvements** (rewrites that raised the score)

## Configuration

### Choosing a Provider

The plugin ships with three built-in AI providers. You can mix and match API keys:

#### Claude (Anthropic)
```php
define( 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY', 'sk-ant-...' );
```
Models: `claude-opus-4-8`, `claude-sonnet-5`, `claude-haiku-4-5-20251001`

#### OpenAI (GPT)
```php
define( 'KRAKEN_SEMANTICS_OPENAI_API_KEY', 'sk-...' );
```
Models: `gpt-4o`, `gpt-4-turbo`, `gpt-4`

#### Google Gemini
```php
define( 'KRAKEN_SEMANTICS_GEMINI_API_KEY', 'AIza...' );
```
Models: `gemini-2.0-flash`, `gemini-1.5-pro`, `gemini-1.5-flash`

### Settings

Access plugin settings from **Kraken Semantics → Settings** in the WordPress admin:

- **Post Types**: Select which post types can carry confidence scores
- **Provider**: Choose between Claude, OpenAI, or Gemini (default: Claude)
- **Model**: Select the model for your chosen provider
- **API Key**: Set the appropriate API key for your selected provider (or use wp-config.php constants)
- **Auto-Scan**: Automatically queue a scan when posts are published/updated
- **Display Badge**: Show confidence badges on the front-end automatically
- **Badge Position**: Place badges before or after content
- **Threshold: High**: Score threshold for "high" confidence (default: 80)
- **Threshold: Low**: Score threshold for "low" confidence (default: 50)

### API Key Security

Always set API keys via wp-config.php constants — they're more secure than storing them in the database:

```php
// wp-config.php
define( 'KRAKEN_SEMANTICS_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'KRAKEN_SEMANTICS_OPENAI_API_KEY', 'sk-...' );
define( 'KRAKEN_SEMANTICS_GEMINI_API_KEY', 'AIza...' );
```

The constants take precedence over the Settings page if both are defined.

## Kraken Hub Integration

[Kraken Core](https://github.com/maddenmedia/kraken-core) provides a shared "Kraken Hub" admin landing page used by several Madden Media plugins. Kraken Semantics is fully standalone and doesn't require Kraken Core — but if Kraken Core happens to be active, Kraken Semantics automatically:

- Adds a **quick-link card** to the Hub dashboard linking to its own Dashboard and Settings pages
- Adds a **live stats widget** to the Hub showing the average score and coverage, so you can see AI-trust health across the whole Kraken Hub at a glance

This requires no setup on either plugin — Kraken Semantics only listens for Kraken Core's `kraken-core/hub/quick_links` filter and `kraken-core/hub/dashboard_widgets` action, both of which are no-ops when Kraken Core isn't installed. Kraken Semantics's own **Kraken Semantics → Dashboard** menu is unaffected either way.

## Score locally with Claude Code (MCP)

Don't want to store an AI API key in WordPress or pay per-request API bills? The bundled MCP server (`mcp/server.mjs`, dependency-free Node) bridges Claude Code to your site's REST API: Claude Code lists your posts, reads their content, judges them locally against the same rubric the built-in providers use — ideally with cheap, fast **Haiku** subagents — and pushes the scores back.

```bash
claude mcp add kraken-semantics \
  --env KRAKEN_WP_URL=https://example.com \
  --env KRAKEN_WP_USER=admin \
  --env KRAKEN_WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
  -- node /path/to/kraken-semantics/mcp/server.mjs
```

Then score everything unscored with parallel Haiku agents:

```
/score-posts
```

Scores land in the dashboard, meta box, and badges exactly like server-side scans, attributed to `claude-cli` and the model that judged them. Full setup, tool reference, and workflow details: [`mcp/README.md`](mcp/README.md).

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
  "scanned_at": "2026-07-08T14:30:00Z"
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
    'history'    => array( ... ), // Past score events, oldest first (capped at 50)
    'delta'      => 6.5,          // Change vs the previous score event, or null
)
```

Each history entry records `score`, `scanned_at`, `provider`, and `model`, so you can see how a post's score moved across rewrites and which model produced each score. The cap is filterable via `kraken_semantics_history_max`.

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

**Q: Which AI provider should I use?**
A: All three built-in providers (Claude, OpenAI, Gemini) produce good results. Choose based on:
- **Cost**: Check current pricing for each service
- **Speed**: GPT-4o and Gemini 2.0 Flash are often faster than Claude Opus
- **Availability**: All three are widely available; pick whichever you already have an account for
- **Model preferences**: Each provider offers different model families

**Q: Can I switch providers later?**
A: Yes, but note that scores are stored with the provider/model that created them. You can have posts scored by different providers over time—each score records which provider made it.

**Q: Does this plugin store my API keys securely?**
A: The recommended approach is to define your API keys in `wp-config.php` as constants, which keeps them out of the database. If you store them in settings, they're stored in the database with no additional encryption—treat your WordPress database like a secret.

**Q: Can I use multiple providers?**
A: Yes. You can define multiple API keys in `wp-config.php` and switch between them in Settings. You can also extend the plugin to call multiple providers per scan.

**Q: Can I use a different model than the defaults?**
A: Yes. In settings, choose any model available through your chosen provider's API account. The defaults are solid, but you can switch to faster/cheaper models anytime.

**Q: What happens if the API call fails?**
A: Failed scans are logged but don't block post publishing. If auto-scan is enabled and a scan fails, you can retry manually or via WP-CLI.

**Q: Can I score custom post types?**
A: Yes. Select them in **Settings → Kraken Semantics → Post Types**.

**Q: How are scores displayed on the front-end?**
A: Scores appear as badges, styled with the confidence label (high/medium/low). You can customize the styling via CSS or disable automatic display and use the `kraken_semantics_badge()` template tag in your theme.

**Q: Can I create a provider for my own AI service?**
A: Yes. Implement the `Kraken_Semantics_Provider` interface (see "Extending the Plugin" section) to integrate any AI API. Your custom provider will appear in the Settings dropdown alongside the built-in ones.

## Support

For bug reports, feature requests, and documentation, visit the [GitHub repository](https://github.com/maddenmedia/kraken-semantics).

## License

This plugin is licensed under the GPL-2.0-or-later license. See [LICENSE](LICENSE) for details.

## Credits

**Kraken Semantics** is developed and maintained by [Madden Media](https://maddenmedia.com).
