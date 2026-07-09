#!/usr/bin/env node
/**
 * Kraken Semantics MCP server.
 *
 * Lets a local AI agent (e.g. Claude Code running Haiku subagents) score
 * WordPress content without the site needing any AI provider API key:
 * the agent reads post content through this server, judges it locally
 * against the same rubric the plugin's built-in providers use, and pushes
 * the score back through the plugin's REST API.
 *
 * Dependency-free by design, matching the plugin: plain Node (>= 18 for
 * global fetch), JSON-RPC 2.0 over stdio per the MCP spec. No npm install.
 *
 * Configuration (environment variables):
 *   KRAKEN_WP_URL           Site URL, e.g. https://example.com
 *   KRAKEN_WP_USER          WordPress username
 *   KRAKEN_WP_APP_PASSWORD  Application Password for that user
 *                           (Users → Profile → Application Passwords)
 *
 * Register with Claude Code:
 *   claude mcp add kraken-semantics \
 *     --env KRAKEN_WP_URL=https://example.com \
 *     --env KRAKEN_WP_USER=admin \
 *     --env KRAKEN_WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
 *     -- node /path/to/kraken-semantics/mcp/server.mjs
 */

'use strict';

const SERVER_NAME = 'kraken-semantics';
const SERVER_VERSION = '1.1.0';
const PROTOCOL_VERSION = '2025-06-18';

/** Character budget mirroring the plugin's kraken_semantics_max_content_chars. */
const MAX_CONTENT_CHARS = 30000;

/**
 * The scoring rubric. Must stay in lockstep with the DIMENSIONS constant in
 * the plugin's provider classes so local scores are comparable to API scores.
 */
const DIMENSIONS = {
	factual_grounding:
		'Are factual claims consistent with well-established knowledge, plausible, and free of fabrication?',
	internal_consistency:
		'Does the content avoid contradicting itself in facts, numbers, names, or logic?',
	source_attribution:
		'Are non-obvious claims attributed, hedged appropriately, or otherwise verifiable?',
	specificity:
		'Is the content concrete and substantive rather than vague, generic filler?',
};

const RUBRIC = [
	'You are a semantic confidence evaluator for published web content. Grade how',
	'semantically reliable a piece of content is — how much a careful reader could',
	'trust it — on a 0-100 scale, where 100 is fully grounded, consistent, and',
	'specific, and 0 is incoherent or fabricated.',
	'',
	'Score each dimension from 0 to 100:',
	...Object.entries(DIMENSIONS).map(([key, question]) => `- ${key}: ${question}`),
	'',
	'The overall score is your holistic judgment, not a mechanical average. Keep',
	'the summary to one or two plain sentences a site editor can act on. Judge only',
	'the text you are given; do not penalize content for being an excerpt.',
].join('\n');

const INSTRUCTIONS = [
	'Kraken Semantics: score WordPress content for semantic confidence.',
	'',
	'Typical workflow:',
	'1. list_posts with unscored_only=true to find work.',
	'2. get_post to read a post (the response includes the rubric).',
	'3. Judge the content yourself against the rubric — you are the scoring model.',
	'4. submit_score with the overall score, per-dimension breakdown, and summary.',
	'',
	'Scoring rubric:',
	'',
	RUBRIC,
].join('\n');

/* -------------------------------------------------------------------------
 * WordPress REST client
 * ---------------------------------------------------------------------- */

const config = {
	url: (process.env.KRAKEN_WP_URL || '').replace(/\/+$/, ''),
	user: process.env.KRAKEN_WP_USER || '',
	password: process.env.KRAKEN_WP_APP_PASSWORD || '',
};

function assertConfigured() {
	const missing = [];
	if (!config.url) missing.push('KRAKEN_WP_URL');
	if (!config.user) missing.push('KRAKEN_WP_USER');
	if (!config.password) missing.push('KRAKEN_WP_APP_PASSWORD');
	if (missing.length) {
		throw new Error(
			`Missing environment variable(s): ${missing.join(', ')}. ` +
				'Set them when registering the MCP server (claude mcp add --env ...).'
		);
	}
}

/**
 * Performs an authenticated request against the site's REST API.
 *
 * @param {string} path  Path under /wp-json, e.g. '/wp/v2/posts'.
 * @param {object} [options] fetch options; body objects are JSON-encoded.
 * @returns {Promise<{data: any, headers: Headers}>}
 */
async function wp(path, options = {}) {
	assertConfigured();

	const url = `${config.url}/wp-json${path}`;
	const headers = {
		authorization:
			'Basic ' + Buffer.from(`${config.user}:${config.password}`).toString('base64'),
		...(options.body ? { 'content-type': 'application/json' } : {}),
		...(options.headers || {}),
	};

	const response = await fetch(url, {
		...options,
		headers,
		body: options.body ? JSON.stringify(options.body) : undefined,
		signal: AbortSignal.timeout(60000),
	});

	let data = null;
	const text = await response.text();
	try {
		data = text ? JSON.parse(text) : null;
	} catch {
		// Non-JSON body (e.g. an HTML error page from a security plugin).
	}

	if (!response.ok) {
		const message =
			(data && data.message) || `${url} returned HTTP ${response.status}`;
		throw new Error(message);
	}

	return { data, headers: response.headers };
}

/** Cache of post type slug → REST base ('post' → 'posts'). */
const restBaseCache = new Map();

async function restBaseFor(postType) {
	if (restBaseCache.has(postType)) {
		return restBaseCache.get(postType);
	}

	try {
		const { data } = await wp(`/wp/v2/types/${encodeURIComponent(postType)}`);
		const base = data.rest_base || postType;
		restBaseCache.set(postType, base);
		return base;
	} catch (error) {
		// Improve the error with the list of types that do exist.
		const { data } = await wp('/wp/v2/types');
		throw new Error(
			`Unknown post type "${postType}". Available types: ${Object.keys(data).join(', ')}.`
		);
	}
}

/**
 * Flattens rendered post HTML to plain text, roughly matching the plugin's
 * wp_strip_all_tags() treatment so local and server scans grade equivalent text.
 */
function htmlToText(html) {
	const entities = {
		'&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"',
		'&#039;': "'", '&#8217;': '’', '&#8216;': '‘',
		'&#8220;': '“', '&#8221;': '”', '&#8211;': '–',
		'&#8212;': '—', '&#8230;': '…', '&nbsp;': ' ',
	};

	return html
		.replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
		.replace(/<\/(p|div|li|h[1-6]|blockquote|tr|figcaption)>/gi, '\n')
		.replace(/<(br|hr)\s*\/?>/gi, '\n')
		.replace(/<[^>]+>/g, '')
		.replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
		.replace(/&[a-z#0-9]+;/gi, (entity) => entities[entity] ?? entity)
		.replace(/[ \t]+/g, ' ')
		.replace(/\n{3,}/g, '\n\n')
		.trim();
}

/** Extracts the plugin's score meta from a core REST post object. */
function scoreFromMeta(meta) {
	// scanned_at is the reliable "has been scored" signal: a score of 0 is
	// indistinguishable from "never scored" in the numeric meta alone.
	if (!meta || !meta._kraken_semantics_scanned_at) {
		return null;
	}

	const history = Array.isArray(meta._kraken_semantics_history)
		? meta._kraken_semantics_history
		: [];

	return {
		score: meta._kraken_semantics_score,
		breakdown: meta._kraken_semantics_breakdown || {},
		summary: meta._kraken_semantics_summary || '',
		provider: meta._kraken_semantics_provider || '',
		model: meta._kraken_semantics_model || '',
		scanned_at: meta._kraken_semantics_scanned_at,
		times_scored: history.length,
		// Change since the previous scoring event — the rewrite feedback loop.
		delta:
			history.length >= 2
				? Number(
						(
							Number(meta._kraken_semantics_score) -
							Number(history[history.length - 2].score)
						).toFixed(1)
				  )
				: null,
	};
}

/* -------------------------------------------------------------------------
 * Tools
 * ---------------------------------------------------------------------- */

const TOOLS = [
	{
		name: 'list_posts',
		description:
			'List WordPress posts with their current semantic confidence scores. ' +
			'Use unscored_only=true to find content that still needs scoring. ' +
			'Returns pagination info; call again with the next page to continue.',
		inputSchema: {
			type: 'object',
			properties: {
				post_type: {
					type: 'string',
					description: 'Post type slug (default "post"). Also accepts "page" or any custom post type.',
				},
				status: {
					type: 'string',
					description: 'Post status filter (default "publish").',
				},
				unscored_only: {
					type: 'boolean',
					description: 'Only return posts that have never been scored.',
				},
				search: {
					type: 'string',
					description: 'Optional keyword search.',
				},
				page: { type: 'integer', description: 'Page number, starting at 1.' },
				per_page: {
					type: 'integer',
					description: 'Posts per page, 1-100 (default 20).',
				},
			},
		},
	},
	{
		name: 'get_post',
		description:
			'Fetch one post as plain text ready to score, along with the scoring rubric ' +
			'and any existing score. Judge the content against the rubric yourself, then ' +
			'call submit_score.',
		inputSchema: {
			type: 'object',
			properties: {
				id: { type: 'integer', description: 'Post ID.' },
				post_type: {
					type: 'string',
					description: 'Post type slug if not "post" (e.g. "page").',
				},
			},
			required: ['id'],
		},
	},
	{
		name: 'submit_score',
		description:
			'Save a semantic confidence score for a post. Call this after judging the ' +
			'content from get_post against the rubric. The score is stored by the ' +
			'Kraken Semantics plugin and appears in wp-admin and front-end badges.',
		inputSchema: {
			type: 'object',
			properties: {
				id: { type: 'integer', description: 'Post ID.' },
				score: {
					type: 'number',
					description: 'Overall holistic confidence score, 0-100.',
				},
				breakdown: {
					type: 'object',
					description:
						'Per-dimension scores, 0-100 each. Keys: ' +
						Object.keys(DIMENSIONS).join(', ') + '.',
					properties: Object.fromEntries(
						Object.keys(DIMENSIONS).map((key) => [key, { type: 'number' }])
					),
				},
				summary: {
					type: 'string',
					description: 'One or two sentences a site editor can act on.',
				},
				model: {
					type: 'string',
					description:
						'Model that produced the score, e.g. "claude-haiku-4-5-20251001". Stored for attribution.',
				},
			},
			required: ['id', 'score', 'breakdown', 'summary'],
		},
	},
	{
		name: 'trigger_server_scan',
		description:
			'Ask the WordPress site to scan a post itself using its configured AI provider ' +
			'(requires an API key on the server). Prefer get_post + submit_score for local scoring.',
		inputSchema: {
			type: 'object',
			properties: {
				id: { type: 'integer', description: 'Post ID.' },
			},
			required: ['id'],
		},
	},
	{
		name: 'get_rubric',
		description:
			'Return the scoring rubric and dimension definitions used by Kraken Semantics.',
		inputSchema: { type: 'object', properties: {} },
	},
];

const handlers = {
	async list_posts(args = {}) {
		const postType = args.post_type || 'post';
		const base = await restBaseFor(postType);

		const params = new URLSearchParams({
			status: args.status || 'publish',
			page: String(args.page || 1),
			per_page: String(Math.min(Math.max(args.per_page || 20, 1), 100)),
			_fields: 'id,title,link,status,modified,meta',
		});
		if (args.search) {
			params.set('search', args.search);
		}

		const { data, headers } = await wp(`/wp/v2/${base}?${params}`);

		let posts = data.map((post) => ({
			id: post.id,
			title: post.title?.rendered ?? '',
			link: post.link,
			status: post.status,
			modified: post.modified,
			score: scoreFromMeta(post.meta),
		}));

		if (args.unscored_only) {
			posts = posts.filter((post) => post.score === null);
		}

		return {
			posts,
			page: args.page || 1,
			total_posts: Number(headers.get('x-wp-total')) || posts.length,
			total_pages: Number(headers.get('x-wp-totalpages')) || 1,
			note: args.unscored_only
				? 'unscored_only filters after pagination; check further pages until total_pages is reached.'
				: undefined,
		};
	},

	async get_post(args) {
		const base = await restBaseFor(args.post_type || 'post');
		const { data } = await wp(`/wp/v2/${base}/${args.id}`);

		let text = htmlToText(data.content?.rendered ?? '');
		let truncated = false;
		if (text.length > MAX_CONTENT_CHARS) {
			text = text.slice(0, MAX_CONTENT_CHARS);
			truncated = true;
		}

		return {
			id: data.id,
			title: data.title?.rendered ?? '',
			link: data.link,
			existing_score: scoreFromMeta(data.meta),
			content_text: text,
			content_truncated: truncated,
			rubric: RUBRIC,
			next_step:
				'Judge the content against the rubric, then call submit_score with ' +
				'{ id, score, breakdown: {' +
				Object.keys(DIMENSIONS).join(', ') +
				'}, summary, model }.',
		};
	},

	async submit_score(args) {
		const { data } = await wp(`/kraken-semantics/v1/posts/${args.id}/score`, {
			method: 'POST',
			body: {
				score: args.score,
				breakdown: args.breakdown || {},
				summary: args.summary || '',
				provider: 'claude-cli',
				model: args.model || '',
			},
		});

		return { saved: true, ...data };
	},

	async trigger_server_scan(args) {
		const { data } = await wp(`/kraken-semantics/v1/posts/${args.id}/scan`, {
			method: 'POST',
		});

		return data;
	},

	async get_rubric() {
		return { rubric: RUBRIC, dimensions: DIMENSIONS };
	},
};

/* -------------------------------------------------------------------------
 * MCP plumbing: JSON-RPC 2.0 over newline-delimited stdio
 * ---------------------------------------------------------------------- */

function send(message) {
	process.stdout.write(JSON.stringify(message) + '\n');
}

function sendResult(id, result) {
	send({ jsonrpc: '2.0', id, result });
}

function sendError(id, code, message) {
	send({ jsonrpc: '2.0', id, error: { code, message } });
}

async function handleRequest(request) {
	const { id, method, params } = request;

	switch (method) {
		case 'initialize':
			sendResult(id, {
				protocolVersion: params?.protocolVersion || PROTOCOL_VERSION,
				capabilities: { tools: {} },
				serverInfo: { name: SERVER_NAME, version: SERVER_VERSION },
				instructions: INSTRUCTIONS,
			});
			break;

		case 'ping':
			sendResult(id, {});
			break;

		case 'tools/list':
			sendResult(id, { tools: TOOLS });
			break;

		case 'tools/call': {
			const handler = handlers[params?.name];

			if (!handler) {
				sendError(id, -32602, `Unknown tool: ${params?.name}`);
				break;
			}

			try {
				const result = await handler(params.arguments || {});
				sendResult(id, {
					content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
				});
			} catch (error) {
				// Tool-level failures are results with isError, not protocol errors,
				// so the model can read the message and adjust.
				sendResult(id, {
					content: [{ type: 'text', text: `Error: ${error.message}` }],
					isError: true,
				});
			}
			break;
		}

		default:
			// Notifications (no id) never get a response; unknown requests do.
			if (id !== undefined && id !== null) {
				sendError(id, -32601, `Method not found: ${method}`);
			}
	}
}

let buffer = '';

process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
	buffer += chunk;

	let newline;
	while ((newline = buffer.indexOf('\n')) !== -1) {
		const line = buffer.slice(0, newline).trim();
		buffer = buffer.slice(newline + 1);

		if (!line) continue;

		let message;
		try {
			message = JSON.parse(line);
		} catch {
			sendError(null, -32700, 'Parse error');
			continue;
		}

		handleRequest(message).catch((error) => {
			if (message.id !== undefined && message.id !== null) {
				sendError(message.id, -32603, error.message);
			}
		});
	}
});

process.stdin.on('end', () => process.exit(0));
