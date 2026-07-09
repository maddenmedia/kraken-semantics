# Kraken Semantics MCP server

Score WordPress content **locally with Claude Code** — no AI provider API key
on the server, no per-request API billing in WordPress. The MCP server is a
thin bridge to your site's REST API; the Claude model you're already running
(ideally **Haiku**, for cost efficiency) does the actual judging and pushes
scores back into the plugin.

```
┌─────────────┐   MCP (stdio)   ┌──────────────┐   WordPress REST   ┌───────────┐
│ Claude Code  │◄──────────────►│ server.mjs   │◄─────────────────►│ WordPress │
│ (Haiku agents│                │ (this folder)│  Application       │ + plugin  │
│  do scoring) │                │              │  Password auth     │           │
└─────────────┘                 └──────────────┘                    └───────────┘
```

## Requirements

- Node.js 18+ (uses the built-in `fetch`; nothing to `npm install`)
- The Kraken Semantics plugin active on the target site
- A WordPress [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
  for a user who can edit the posts being scored
  (create one under **Users → Profile → Application Passwords**)

## Setup

Register the server with Claude Code:

```bash
claude mcp add kraken-semantics \
  --env KRAKEN_WP_URL=https://example.com \
  --env KRAKEN_WP_USER=admin \
  --env KRAKEN_WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
  -- node /path/to/kraken-semantics/mcp/server.mjs
```

Or, if you run `claude` from the plugin directory itself, the bundled
`.mcp.json` registers the server automatically — just export the three
environment variables first:

```bash
export KRAKEN_WP_URL=https://example.com
export KRAKEN_WP_USER=admin
export KRAKEN_WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
claude
```

## Usage

The fastest path is the bundled slash command (available when running `claude`
inside the plugin directory):

```
/score-posts                 # score everything unscored, in parallel Haiku batches
/score-posts page            # only pages
/score-posts 12 34 56        # specific post IDs
/score-posts --rescore       # include posts that already have scores
```

It lists unscored posts, fans them out to parallel **Haiku** subagents (a
compact judgment task is exactly what a small fast model is for), and each
agent reads the content, applies the plugin's rubric, and submits the score.

Or just ask in plain language:

> Score all my unscored posts with Haiku subagents using the kraken-semantics
> tools, then show me the five lowest-scoring ones.

Scores land in the same place as server-side scans: the wp-admin dashboard,
the editor meta box, list-table columns, and front-end badges — attributed to
provider `claude-cli` and whichever model did the judging.

## Tools

| Tool | What it does |
| --- | --- |
| `list_posts` | List posts with current scores; `unscored_only=true` finds work. |
| `get_post` | Fetch one post as plain text plus the scoring rubric. |
| `submit_score` | Save a score (overall, per-dimension breakdown, summary, model). |
| `trigger_server_scan` | Ask the site to scan with *its* configured provider instead. |
| `get_rubric` | Return the rubric and dimension definitions. |

The rubric served by this server is identical to the one the plugin's built-in
Claude/OpenAI/Gemini providers use, so locally produced scores are directly
comparable to server-side scans.

## Why Haiku?

Scoring a post is a single, well-bounded judgment call over a few thousand
words — no tools, no multi-step reasoning. Haiku handles it well at a fraction
of the cost and latency of larger models, which matters when you're sweeping
hundreds of posts. If a site needs maximum scoring quality (e.g. sensitive
health/finance content), tell the command to use Sonnet or Opus subagents
instead — the stored score records which model produced it either way.
