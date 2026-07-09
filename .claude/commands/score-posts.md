---
description: Score unscored WordPress posts locally using cheap Haiku subagents via the Kraken Semantics MCP server
---

Score WordPress posts for semantic confidence using the `kraken-semantics` MCP
server tools (list_posts, get_post, submit_score). You act as the orchestrator;
Haiku subagents do the scoring for cost efficiency.

Arguments (optional): $ARGUMENTS
- A post type slug (e.g. `page`) limits scoring to that type.
- One or more numeric post IDs score exactly those posts.
- `--rescore` includes posts that already have a score.

Workflow:

1. Call `list_posts` with `unscored_only=true` (omit when `--rescore` was
   passed) to collect the posts that need scoring. Page through until you have
   the full list.
2. If there are no posts to score, say so and stop.
3. Batch the posts into groups of about 5 and launch one subagent per batch,
   **using the Haiku model** (`model: haiku`) since scoring is a compact,
   well-scoped judgment task. Launch batches in parallel. Each subagent must:
   - Call `get_post` for each assigned post ID.
   - Judge the content strictly against the rubric returned by `get_post`
     (dimensions: factual_grounding, internal_consistency, source_attribution,
     specificity, each 0-100, plus a holistic overall score and a one-to-two
     sentence editor-facing summary).
   - Call `submit_score` with `{ id, score, breakdown, summary, model:
     "claude-haiku-4-5-20251001" }`.
   - Report back a compact list of `post id → score` results and any failures.
4. When all subagents finish, summarize for the user: how many posts were
   scored, the score range, average, and any posts that failed (with reasons).
   Point out the lowest-scoring posts as candidates for editorial review.

Do not fabricate scores for posts whose content could not be fetched — report
those as failures instead.
