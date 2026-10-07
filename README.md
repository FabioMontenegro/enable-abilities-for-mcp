# Enable Abilities for MCP

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/advanced/)
[![WordPress Tested](https://img.shields.io/wordpress/plugin/tested/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Let AI assistants like Claude manage your WordPress site through the [Model Context Protocol](https://modelcontextprotocol.io/) — with full control over exactly what they can and cannot do.

**112 abilities in 21 categories**, each one individually toggleable from the dashboard: content, SEO (Rank Math / SEOPress / Yoast), navigation menus, WooCommerce, Elementor, LearnDash, Tutor LMS, JetEngine (Options Pages + Query Builder), multilanguage, `llms.txt`, FSE block templates, accessibility (WCAG), cache purge, and more.

## How it works

WordPress 6.9 introduced the **Abilities API**: a standard way for external tools to discover and execute actions on your site. The official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) exposes those abilities to any MCP client.

This plugin completes the stack:

```
Claude / MCP client  ──►  MCP Adapter  ──►  Abilities API  ──►  Enable Abilities for MCP
                                                                (112 abilities + per-ability toggles
                                                                 + auth + activity log)
```

1. It **registers 109 content-management abilities** (plus exposing the 3 native WordPress core ones to MCP).
2. It gives you an **admin dashboard** to enable or disable each ability individually — expose only what you need.
3. It provides **authentication** (claude.ai OAuth custom connector, Application Passwords, or single-admin Bearer token) and an **activity log** of every ability executed.
4. It also governs **third-party abilities**: anything other MCP-ready plugins (Fluent Forms, …) register shows up in the same dashboard, grouped by plugin, with the same toggles — disabling one removes it from every MCP server on the site.

## Abilities by category

| Category | Abilities | Highlights |
|---|---|---|
| **WordPress Core** | 3 | Native site/user/environment info, exposed to MCP by this plugin |
| **Read** | 9 | Posts, pages, categories, tags, comments, media, users — with filters |
| **Write** | 12 | Create/update/delete posts and pages, moderate and reply to comments, upload images from URL, duplicate any post/page/CPT with meta and taxonomies, assign custom taxonomy terms to a post/page |
| **SEO — Rank Math** | 3 | Read/write all meta + write structured-data schema blocks (FAQPage, Article, Product…) as JSON-LD |
| **SEO — SEOPress** | 3 | Read/write all meta + **content analysis**: every SEOPress check (headings, internal links, schemas…) with impact level and recommendation, optionally re-analyzing the rendered page first |
| **SEO — Yoast SEO** | 3 | Read/write all meta + sitemap index |
| **Utility** | 6 | Search & replace, site stats, raw post-meta read/write, active plugins with capability detection, **cache purge** (WP Rocket, LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache) |
| **Multilanguage** | 9 | List languages, **create post and term translations** (the AI supplies the translated text; taxonomies, custom fields, featured image and term meta are copied over), assign languages, and link translation groups for both posts and taxonomy terms via Polylang, WPML, or Linguator AI; `create-post` accepts `language` + `translation_of` |
| **Navigation Menus** | 8 | Create menus, list/get with full item hierarchy, add/update items (pages, posts, terms, custom URLs), remove items, assign theme locations, delete menus (destructive ones opt-in) |
| **Custom Post Types** | 11 | Discover and CRUD any CPT with taxonomy and meta support, including reading/writing term meta and core term fields (name, slug, description, parent) |
| **WooCommerce** | 7 | Products, orders, customers — native WC API, HPOS-compatible |
| **The Events Calendar** | 4 | List/get/create/update events with venue and organizer |
| **Code Snippets** | 5 | Create, list, read, and update PHP snippets (syntax-validated, dangerous functions blocked); deactivate or request activation — activation always needs an administrator to review the code and confirm it in wp-admin |
| **JetEngine — Options Pages** | 3 | Read/write Options Pages fields, including repeaters |
| **JetEngine — Query Builder** | 3 | List, read, and update Query Builder queries via JetEngine's internal data layer — the missing edit/get/list counterpart to JetEngine's own native "Add Query" MCP tool |
| **Elementor** | 3 | Read the element tree, edit element settings by id (single or batch), bind widget settings to dynamic tags |
| **LearnDash** | 6 | Courses, user progress, quiz results, enroll/unenroll |
| **Tutor LMS** | 8 | Courses, course detail with topics/lessons hierarchy, user progress and quiz results, enroll/unenroll (opt-in), plus reading and setting a lesson's video source via Tutor's own storage function — avoids the string-only limitation of the generic post-meta ability |
| **AI — Agent Readiness** | 2 | Read, validate, and write the site **llms.txt** (llmstxt.org spec, audited by Lighthouse "Agentic Browsing") — integrates with SEOPress Pro or serves the file itself |
| **Accessibility (WCAG)** | 1 | Scan the media library for images missing alt text (WCAG 1.1.1), paginated. Full contrast/ARIA/keyboard-nav auditing is left to browser-based tools (e.g. Lighthouse) |
| **FSE Block Templates** | 3 | List and read `wp_template` / `wp_template_part` entries for the active theme (merges theme-file defaults with database overrides), write new block markup — auto-creates a database override when needed. Requires a theme with block-templates support |

Write abilities validate per-post permissions (`edit_post`, `read_post`) and destructive or high-impact abilities are **opt-in** (disabled by default): Elementor edits, LearnDash and Tutor LMS enrollment, Options Pages and Query Builder writes, `llms.txt` writes, FSE template writes, code snippet updates and activation requests, and removing/deleting menu items or menus.

## Quick start

### 1. Install

- WordPress 6.9+ · PHP 8.0+
- Install **[Enable Abilities for MCP](https://wordpress.org/plugins/enable-abilities-for-mcp/)** from the plugin directory
- Install the **[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)** plugin

From 0.7.0, MCP Adapter refuses to start when another plugin has already registered its own bundled copy of `WP\MCP\Core\McpAdapter` — WooCommerce ships one, among others. It says so with an "Another version of MCP Adapter is already loaded" notice, and this plugin then reports that the adapter is present but did not start, naming the folder the conflicting copy came from. The fix is to update the plugin bundling the stale copy; which copy wins depends on autoloader registration order, so the conflict does not appear on every site.

### 2. Configure access

Go to **Settings → WP Abilities**:

- **Connection tab** — choose an auth method:
  - **claude.ai OAuth Custom Connector**: add your site to claude.ai (web, mobile, or desktop) with just a URL — an embedded OAuth 2.1 server (CIMD) lets each user log in with their own WordPress account and approve a consent screen. No Client ID, no tokens to copy.
  - **ChatGPT & other OAuth connectors (beta)**: opt-in. ChatGPT registers itself dynamically (RFC 7591) instead of publishing a fixed metadata URL, so it needs its own door — turn this on to expose `/oauth/register` and list the callback URLs a connector may return users to. Same login-and-consent flow, same per-user roles.
  - **Application Passwords**: per-user access respecting each user's role
  - **Single Admin Bearer Token**: generate an API key (stored as SHA-256 hash, shown once)
- **Connect your AI client** — shared section with the MCP endpoint URL and ready-to-copy config for every client; generating Application Password credentials auto-fills the snippets
- **Abilities tab** — toggle exactly what your AI assistant may do
- **Activity Log tab** — audit every ability execution (user, ability, timestamp)

### 3. Connect your MCP client

The **Connection tab** includes ready-to-copy configuration for every client:

| Client | Config | Transport |
|---|---|---|
| claude.ai (web / mobile / desktop) | Settings → Connectors → add the OAuth URL | remote MCP over HTTPS (OAuth 2.1 + CIMD) |
| ChatGPT (web, paid plans) | Turn on Developer mode → create a connector with the same OAuth URL | remote MCP over HTTPS (OAuth 2.1 + RFC 7591) |
| Claude Desktop / Claude Code | `claude_desktop_config.json` | `npx mcp-remote` (stdio) |
| OpenAI Codex CLI | `~/.codex/config.toml` | `npx mcp-remote` (stdio) |
| Google Antigravity | `mcp_config.json` (Agent panel → MCP Servers) | direct `serverUrl` + `headers` — no npx |

Claude Desktop example (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "--header",
        "Authorization: Bearer YOUR_API_KEY"
      ]
    }
  }
}
```

Then just talk to your site:

> *"Audit the SEO of my latest posts, fix the meta descriptions with SEOPress, clear the cache, and re-run the content analysis."*

## The ChatGPT connector (beta)

claude.ai identifies itself with a fixed metadata URL (a Client ID Metadata Document) that the
embedded OAuth library already trusts. ChatGPT does not: it registers itself dynamically per
connector (RFC 7591) and mints a fresh `client_id` URL each time, so no static allowlist can
match it. Enabling **ChatGPT & Other OAuth Connectors** on the Connection tab adds the missing
pieces without changing anything about how Claude connects.

### Setup

1. Turn on the OAuth server, then turn on **ChatGPT & Other OAuth Connectors** below it.
2. Check the **Allowed callback URLs** box. It is prefilled with the callbacks ChatGPT is
   commonly seen to use — confirm the exact one your connector screen shows, and delete the rest.
3. Save, then in ChatGPT turn on **Developer mode** (paid plans: Plus, Pro, Business, Enterprise
   or Edu) and create a connector with the same MCP server URL.

ChatGPT reads `/.well-known/oauth-authorization-server`, finds `registration_endpoint`, registers
itself at `/oauth/register`, and then runs the normal login-and-consent flow. Each user logs in
with their own WordPress account, approves the consent screen, and gets a session bound to their
own role. A connector that asks you to paste a Client ID and Secret instead is covered too —
**Create a Client ID and Secret by hand** on the same panel issues one (the secret is shown once
and stored only as a hash).

### The callback allowlist

This is the security boundary. An authorization server that lets a client nominate any
`redirect_uri` is an open redirect that hands out access tokens, so a self-registered client may
only return users to a URL an administrator has listed — enforced at registration time *and*
again on every authorization request, so removing a URL immediately blocks clients that
registered while it was allowed.

| Component | Rule |
|---|---|
| Scheme | Literal; `https` required, except plain `http` on loopback (`127.0.0.1`, `localhost`, `::1`) for native apps per RFC 8252 §8.3 |
| Host | Literal — never wildcarded. A pattern with `*` in the host is discarded on save |
| Port | Exact match, except on loopback, where the per-session ephemeral port is ignored |
| Path / query | `*` matches any run of characters except `?` and `#`, so a wildcard can widen a path but can never swallow a query string or reach another host |
| Fragment, userinfo | A `redirect_uri` containing `#` or `user:pass@` is rejected outright |
| `#` at line start | Treated as a comment, so the list can be annotated |

The same list doubles as the SSRF gate for metadata fetches: a `client_id` URL is only ever
dereferenced when its host already appears in the allowlist.

### Endpoints

Served from `home_url()`, so they follow the Site Address on subdirectory installs.

| Path | Method | Purpose |
|---|---|---|
| `/.well-known/oauth-authorization-server` | GET | RFC 8414 metadata, with `registration_endpoint` added while connectors are on |
| `/oauth/register` | POST, OPTIONS | RFC 7591 dynamic client registration (unauthenticated, rate-limited per IP, CORS-enabled) |

Everything downstream — consent, single-use auth codes, PKCE verification, Application Password
creation, JWT signing, refresh-token rotation, transport authentication — is still the embedded
library's, unchanged. The handlers in [`includes/oauth-connectors.php`](includes/oauth-connectors.php)
run on `template_redirect` at priority 9, one tick ahead of the library's, and return without
output whenever the request is not theirs; nothing under `vendor/` is patched. With the toggle
off, the discovery document drops `registration_endpoint`, `/oauth/register` refuses every
request, and the library's own behaviour is bit-for-bit what it was.

### Limits

- Registration is open by design (that is what RFC 7591 means), so the allowlist is what keeps it
  safe. It is capped at 50 stored clients — oldest self-registered records are evicted first, and
  clients created by hand are never evicted — and throttled to 20 registrations per IP per hour.
- Removing a connector stops it starting new sessions; sessions it already holds are revoked from
  **Users → Profile → Application Passwords**, as before.
- Requires a public HTTPS site, like the claude.ai connector.

## Security model

- **Capability checks everywhere** — every ability declares a `permission_callback`; per-post abilities check `read_post`/`edit_post` on the specific target, not just site-wide caps. Publishing requires the publish capability of that post type and is refused with an error rather than degraded to a draft; an author or a parent taken from the input is validated against `edit_others_posts` and against permission on the parent; listing non-published statuses requires an editing capability and the results are filtered per post
- **OAuth 2.1 connector** — opt-in, PKCE S256, Client ID Metadata Documents restricted to trusted publishers (Claude bundled), per-user consent screen, JWT-authenticated transport
- **Revocable OAuth sessions** — each connector session is a WordPress Application Password named `MCP OAuth – <client> – <date>`, with Last Used and Last IP filled in. Turning the OAuth switch off, changing the user's password, "Log Out Everywhere", revoking the Application Password, or deactivating the plugin ends the session; a revoked connector must be approved again and never reconnects on its own. While the switch is off the OAuth server stays off, even if another plugin boots the same OAuth library
- **Third-party connectors** — separately opt-in; a self-registering client may only ever return users to a callback URL an administrator has listed, checked again on every authorization request
- **Bearer token** stored as SHA-256 hash, tied to an admin account, revocable at any time
- **Per-ability toggles** — anything disabled is simply never registered
- **Activity log** for full auditability
- **Opt-in destructive abilities** — disabled until you explicitly enable them
- **WPCS compliant** (WordPress Coding Standards 3.x)

## Troubleshooting the claude.ai connector

If adding the custom connector fails with *"Couldn't register with the sign-in service"*, in almost every reported case the request never reaches WordPress — a security layer is blocking Anthropic's backend, which connects with a non-browser User-Agent (`python-httpx`):

- **Hosting WAFs** (cPGuard, Imunify360, ModSecurity "generic HTTP client" rules) — ask your host to allow that User-Agent or Anthropic's IP range `160.79.104.0/23` for `/.well-known/oauth-*`, `/oauth/*`, and `/wp-json/mcp/*`
- **Cloudflare** — disable Bot Fight Mode and allow Anthropic's crawlers (`Claude-User`) in AI Crawl Control, or add a WAF skip rule for those paths

Diagnose from an external machine:

```bash
curl -A "python-httpx/0.28.1" https://your-site.com/.well-known/oauth-authorization-server
# 200 + JSON → OK · 403 → something in front of WordPress is blocking Anthropic
```

**ChatGPT reports the same block differently.** Its connector fails with *"OAuth authorization server metadata must advertise PKCE support with code_challenge_methods_supported containing S256"*. OpenAI's backend also fetches the documents server-side with `python-httpx`, so when the authorization-server document is blocked, ChatGPT is left with the protected-resource document — which has no PKCE field. Same diagnosis, same fix. With `WP_DEBUG_LOG` on, `[DISCOVERY] request received` lines in `wp-content/debug.log` confirm whether the request reached WordPress at all.

The plugin already handles the WordPress-side gotchas: it prevents the trailing-slash 301 canonical redirect on the discovery documents and serves the RFC 9728 path-suffixed variants. A Site Health check (**Tools → Site Health**) flags hosts that intercept `.well-known/` before WordPress runs.

**Subdirectory multisite** (site.com/blog-a): network-activate the plugin. OAuth clients resolve discovery documents against the domain root — which belongs to the main site — so the plugin bridges `/.well-known/oauth-*/<subsite-path>` requests from the main site to the owning subsite automatically. Each subsite keeps its own OAuth toggle, ability configuration, and connector URL.

## Development

```bash
composer install

# Code sniffer
vendor/bin/phpcs --standard=WordPress --extensions=php --exclude=WordPress.Files.FileName .

# Auto-fix
vendor/bin/phpcbf --standard=WordPress --extensions=php --exclude=WordPress.Files.FileName .
```

Abilities are registered with the standard `wp_register_ability()` API on the `wp_abilities_api_init` hook — you can add your own alongside. Useful hooks: `ewpa_after_update_post_meta` (cache busting after raw meta writes), `ewpa_blocked_meta_keys` (extend the protected-keys blocklist), `ewpa_manageable_private_post_types` (let the CPT abilities manage a structural post type that is not public — Tutor LMS `topics` is allowed by default; capability checks still apply).

## Links

- [Plugin homepage](https://mcp.fabiomontenegro.com/)
- [Plugin on WordPress.org](https://wordpress.org/plugins/enable-abilities-for-mcp/)
- [Support forum](https://wordpress.org/support/plugin/enable-abilities-for-mcp/)
- [Changelog](https://wordpress.org/plugins/enable-abilities-for-mcp/#developers)
- [WordPress Abilities API](https://make.wordpress.org/core/2025/07/17/abilities-api/) · [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)
- Author: [Fabio Montenegro](https://fabiomontenegro.com) · [LinkedIn](https://www.linkedin.com/in/fabio-montenegro/) · [Support on Ko-fi](https://ko-fi.com/fabiomontenegro)

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.
