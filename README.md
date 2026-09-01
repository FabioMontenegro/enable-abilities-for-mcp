# Enable Abilities for MCP

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/advanced/)
[![WordPress Tested](https://img.shields.io/wordpress/plugin/tested/enable-abilities-for-mcp)](https://wordpress.org/plugins/enable-abilities-for-mcp/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Let AI assistants like Claude manage your WordPress site through the [Model Context Protocol](https://modelcontextprotocol.io/) — with full control over exactly what they can and cannot do.

**96 abilities in 19 categories**, each one individually toggleable from the dashboard: content, SEO (Rank Math / SEOPress / Yoast), navigation menus, WooCommerce, Elementor, LearnDash, Tutor LMS, JetEngine, multilanguage, `llms.txt`, FSE block templates, cache purge, and more.

## How it works

WordPress 6.9 introduced the **Abilities API**: a standard way for external tools to discover and execute actions on your site. The official [MCP Adapter](https://github.com/WordPress/mcp-adapter) exposes those abilities to any MCP client.

This plugin completes the stack:

```
Claude / MCP client  ──►  MCP Adapter  ──►  Abilities API  ──►  Enable Abilities for MCP
                                                                (96 abilities + per-ability toggles
                                                                 + auth + activity log)
```

1. It **registers 93 content-management abilities** (plus exposing the 3 native WordPress core ones to MCP).
2. It gives you an **admin dashboard** to enable or disable each ability individually — expose only what you need.
3. It provides **authentication** (claude.ai OAuth custom connector, Application Passwords, or single-admin Bearer token) and an **activity log** of every ability executed.
4. It also governs **third-party abilities**: anything other MCP-ready plugins (Fluent Forms, …) register shows up in the same dashboard, grouped by plugin, with the same toggles — disabling one removes it from every MCP server on the site.

## Abilities by category

| Category | Abilities | Highlights |
|---|---|---|
| **WordPress Core** | 3 | Native site/user/environment info, exposed to MCP by this plugin |
| **Read** | 9 | Posts, pages, categories, tags, comments, media, users — with filters |
| **Write** | 11 | Create/update/delete posts and pages, moderate and reply to comments, upload images from URL, duplicate any post/page/CPT with meta and taxonomies |
| **SEO — Rank Math** | 3 | Read/write all meta + write structured-data schema blocks (FAQPage, Article, Product…) as JSON-LD |
| **SEO — SEOPress** | 3 | Read/write all meta + **content analysis**: every SEOPress check (headings, internal links, schemas…) with impact level and recommendation, optionally re-analyzing the rendered page first |
| **SEO — Yoast SEO** | 3 | Read/write all meta + sitemap index |
| **Utility** | 6 | Search & replace, site stats, raw post-meta read/write, active plugins with capability detection, **cache purge** (WP Rocket, LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache) |
| **Multilanguage** | 3 | Assign language and link translations via Polylang or WPML; `create-post` accepts `language` + `translation_of` |
| **Navigation Menus** | 8 | Create menus, list/get with full item hierarchy, add/update items (pages, posts, terms, custom URLs), remove items, assign theme locations, delete menus (destructive ones opt-in) |
| **Custom Post Types** | 10 | Discover and CRUD any CPT with taxonomy and meta support, including reading/writing term meta by exact key |
| **WooCommerce** | 7 | Products, orders, customers — native WC API, HPOS-compatible |
| **The Events Calendar** | 4 | List/get/create/update events with venue and organizer |
| **Code Snippets** | 1 | Create PHP snippets (always inactive, syntax-validated, dangerous functions blocked) |
| **JetEngine — Options Pages** | 3 | Read/write Options Pages fields, including repeaters |
| **Elementor** | 3 | Read the element tree, edit element settings by id (single or batch), bind widget settings to dynamic tags |
| **LearnDash** | 6 | Courses, user progress, quiz results, enroll/unenroll |
| **Tutor LMS** | 8 | Courses, course detail with topics/lessons hierarchy, user progress and quiz results, enroll/unenroll (opt-in), plus reading and setting a lesson's video source via Tutor's own storage function — avoids the string-only limitation of the generic post-meta ability |
| **AI — Agent Readiness** | 2 | Read, validate, and write the site **llms.txt** (llmstxt.org spec, audited by Lighthouse "Agentic Browsing") — integrates with SEOPress Pro or serves the file itself |
| **FSE Block Templates** | 3 | List and read `wp_template` / `wp_template_part` entries for the active theme (merges theme-file defaults with database overrides), write new block markup — auto-creates a database override when needed. Requires a theme with block-templates support |

Write abilities validate per-post permissions (`edit_post`, `read_post`) and destructive or high-impact abilities are **opt-in** (disabled by default): Elementor edits, LearnDash and Tutor LMS enrollment, Options Pages writes, `llms.txt` writes, FSE template writes, code snippets, and removing/deleting menu items or menus.

## Quick start

### 1. Install

- WordPress 6.9+ · PHP 8.0+
- Install **[Enable Abilities for MCP](https://wordpress.org/plugins/enable-abilities-for-mcp/)** from the plugin directory
- Install the **[MCP Adapter](https://github.com/WordPress/mcp-adapter/releases)** plugin

### 2. Configure access

Go to **Settings → WP Abilities**:

- **Connection tab** — choose an auth method:
  - **claude.ai OAuth Custom Connector**: add your site to claude.ai (web, mobile, or desktop) with just a URL — an embedded OAuth 2.1 server (CIMD) lets each user log in with their own WordPress account and approve a consent screen. No Client ID, no tokens to copy.
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

## Security model

- **Capability checks everywhere** — every ability declares a `permission_callback`; per-post abilities check `read_post`/`edit_post` on the specific target, not just site-wide caps
- **OAuth 2.1 connector** — opt-in, PKCE S256, Client ID Metadata Documents restricted to trusted publishers (Claude bundled), per-user consent screen, JWT-authenticated transport
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

Abilities are registered with the standard `wp_register_ability()` API on the `wp_abilities_api_init` hook — you can add your own alongside. Useful hooks: `ewpa_after_update_post_meta` (cache busting after raw meta writes), `ewpa_blocked_meta_keys` (extend the protected-keys blocklist).

## Links

- [Plugin homepage](https://mcp.fabiomontenegro.com/)
- [Plugin on WordPress.org](https://wordpress.org/plugins/enable-abilities-for-mcp/)
- [Support forum](https://wordpress.org/support/plugin/enable-abilities-for-mcp/)
- [Changelog](https://wordpress.org/plugins/enable-abilities-for-mcp/#developers)
- [WordPress Abilities API](https://make.wordpress.org/core/2025/07/17/abilities-api/) · [MCP Adapter](https://github.com/WordPress/mcp-adapter)
- Author: [Fabio Montenegro](https://fabiomontenegro.com) · [LinkedIn](https://www.linkedin.com/in/fabio-montenegro/) · [Support on Ko-fi](https://ko-fi.com/fabiomontenegro)

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.
