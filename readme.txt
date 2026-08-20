=== Enable Abilities for MCP ===
Contributors: fabiomontenegro1987
Donate link: https://ko-fi.com/fabiomontenegro
Tags: mcp, ai, rest-api, content-management, woocommerce
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage which WordPress Abilities are exposed to MCP servers. Supports WooCommerce, The Events Calendar, and any custom post type.

== Description ==

**Enable Abilities for MCP** gives you full control over which WordPress Abilities are available to AI assistants through the MCP (Model Context Protocol) Adapter.

WordPress 6.9 introduced the Abilities API, allowing external tools to discover and execute actions on your site. This plugin extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

= Connect from claude.ai with just a URL =

Since version 2.1 the plugin ships an embedded OAuth 2.1 server built for claude.ai custom connectors. Add your site in claude.ai → Settings → Connectors, log in with your WordPress user, approve the consent screen — connected. No Client ID, no Application Password, no local configuration.

* Works from the claude.ai web app, mobile apps, and Claude Desktop
* Each team member authenticates with their **own WordPress account and role** — a subscriber can never do what only an editor should
* Every ability execution lands in the activity log under the real user's name
* Works on single sites, subdirectory installs, and multisite networks (network-activate so the main site serves the OAuth discovery documents for every subsite)

Prefer tokens? Application Passwords (per-user) and a single-admin Bearer token connect Claude Desktop / Claude Code, OpenAI Codex CLI, and Google Antigravity — the Connection tab generates ready-to-paste configuration for each client, and fills in your credentials automatically.

= Features =

* **84 abilities** organized in 18 categories: Core, Read, Write, SEO (Rank Math), SEO (SEOPress), SEO (Yoast), Navigation Menus, Utility, Multilanguage, Custom Post Types, WooCommerce, The Events Calendar, Code Snippets, JetEngine Options Pages, Elementor, LearnDash, Tutor LMS, and AI Agent Readiness (llms.txt)
* **WooCommerce integration** — dedicated abilities to manage products, orders, and customers using the native WooCommerce API (HPOS-compatible, formally declared)
* **The Events Calendar integration** — list, get, create, and update events with venue, organizer, and date filters
* **claude.ai OAuth custom connector** — connect from claude.ai (web, mobile, or desktop) with zero local setup: an embedded OAuth 2.1 server with Client ID Metadata Document (CIMD) support lets each user log in with their own WordPress account and role
* **Admin dashboard** with toggle switches for each ability
* **Per-ability control** — expose only what you need
* **Third-party ability control** — abilities registered by other MCP-ready plugins (e.g. Fluent Forms) appear in the same dashboard, grouped by plugin, with the same per-ability toggles; disabling one removes it from every MCP server on the site
* **Secure by design** — proper capability checks, input sanitization, and per-post permission validation
* **WPCS compliant** — fully passes WordPress Coding Standards (phpcs)
* **MCP-ready** — all abilities include `show_in_rest` and `mcp.public` metadata

= Available Abilities =

**Read (safe, query-only):**

* Get posts with filters (status, category, tag, search)
* Get single post details (content, SEO meta, featured image)
* Get categories, tags, pages, comments, media, and users

**Write (create & modify):**

* Create, update, and delete posts
* Create categories and tags
* Create pages
* Moderate comments
* Reply to comments as the authenticated user
* Upload images from external URLs to the media library (with optional auto-assign as featured image)

**SEO — Rank Math:**

* Get full Rank Math metadata for any post/page (title, description, keywords, robots, Open Graph, SEO score)
* Update Rank Math metadata: SEO title, description, focus keyword, canonical URL, robots, Open Graph, primary category, pillar content

**SEO — SEOPress:**

* Get full SEOPress metadata for any post/page (title, description, focus keyword, robots, canonical, Open Graph, Twitter Card)
* Update SEOPress metadata: SEO title, description, focus keyword, canonical URL, robots directives, Open Graph, Twitter Card

**SEO — Yoast SEO:**

* Get full Yoast SEO metadata for any post/page (title, description, focus keyphrase, canonical, robots, Open Graph, Twitter Card)
* Update Yoast SEO metadata: SEO title, description, focus keyphrase, canonical URL, robots (noindex, nofollow, advanced), Open Graph, Twitter Card
* Get Yoast sitemap index — fetch and parse the sitemap index, returning all registered sitemap URLs with last modification date

**Custom Post Types:**

* List all registered custom post types with configuration and taxonomies
* Get items from any CPT with filtering, search, and taxonomy queries
* Get full details of a CPT item including all meta fields (WooCommerce, ACF, JetEngine, etc.)
* Create, update, and delete CPT items with taxonomy and meta field support
* Get CPT taxonomies with their terms
* Assign taxonomy terms to CPT items

**WooCommerce:**

* List products with price, SKU, stock status, categories, and type
* Get full product detail including gallery, attributes, and variations
* Update product price, sale price, stock quantity, and status
* List orders with customer, total, status, and date (HPOS-compatible)
* Get full order detail: line items, billing/shipping, totals, and notes
* Update order status with optional note
* List customers with email, name, total spent, and order count

**The Events Calendar:**

* List events with start/end date, venue, organizer, and date range filter
* Get full event detail with resolved venue address and organizer contact
* Create new events with title, description, dates, venue, and organizer
* Update existing events

**Navigation Menus:**

* List menus with item counts and theme locations, and get one menu's full item hierarchy
* Create a new menu, and add pages, posts, categories, tags, or custom URLs as items (with parent and position)
* Update an item's title, URL, parent, or position
* Remove an item, assign a menu to a theme location, or delete a menu entirely (opt-in — destructive)

**Tutor LMS:**

* Read a lesson's video source configuration (type, value, and runtime)
* Set a lesson's video source (external URL, YouTube, Vimeo, HTML5, or a third-party source such as Bunny.net) using Tutor's own storage function — avoids the string-only limitation of the generic Update Post Meta ability, which Tutor cannot read back

**Utility:**

* Search and replace text in post content
* Site statistics overview (includes custom post type counts)
* Update any post meta field by exact key (with protected internal key blocklist)

= Requirements =

* WordPress 6.9 or later (Abilities API)
* MCP Adapter plugin installed and configured
* PHP 8.0 or later

== Installation ==

1. In your WordPress dashboard, go to **Plugins > Add New** and search for **Enable Abilities for MCP**.
2. Click **Install Now**, then **Activate**.
3. Go to **Settings > WP Abilities** to manage which abilities are active.
4. Install and configure the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin to connect with AI assistants.

== Frequently Asked Questions ==

= Do I need anything else for this plugin to work? =

Yes. This plugin requires WordPress 6.9+ (which includes the Abilities API) and the MCP Adapter plugin to connect abilities with AI assistants like Claude.

= Are all abilities enabled by default? =

Yes. On first activation, all abilities are enabled. You can disable any of them from **Settings > WP Abilities**.

= Is it safe to enable write abilities? =

Write abilities respect WordPress capabilities. For example, creating a post requires the `publish_posts` capability, and editing checks per-post permissions. The MCP user must have the appropriate WordPress role.

= Does it work on Multisite? =

Yes. The plugin can be network-activated. Each site in the network has its own ability configuration, its own OAuth toggle, and its own connector URL — enabling MCP on one subsite never exposes the others.

For **subdirectory networks** (site.com/blog-a, site.com/blog-b) the claude.ai OAuth connector needs the plugin network-activated (or active on the main site): OAuth clients resolve discovery documents against the domain root, which belongs to the main site, so the plugin bridges those requests to the owning subsite automatically.

= Does it work with WooCommerce? =

Yes. The Custom Post Types section automatically detects WooCommerce products, orders, coupons, and any other registered post type. You can list, create, update, and delete items with full access to WooCommerce meta fields like `_price`, `_sku`, `_stock_status`, `_regular_price`, and more.

= Can I add custom abilities? =

This plugin registers abilities using the standard `wp_register_ability()` API. You can register additional abilities in your own plugin using the `wp_abilities_api_init` hook.

= Another plugin (Fluent Forms, etc.) registers its own MCP abilities — can I control those too? =

Yes. Since 2.2, abilities registered by other plugins appear in the Abilities tab under their own "Third-party" section, grouped by plugin namespace, with the same toggles as this plugin's abilities. New third-party abilities start enabled; disabling one unregisters it before any MCP server can expose it — including this plugin's claude.ai OAuth connector and the other plugin's own MCP endpoint. Disabled abilities stay listed so you can re-enable them at any time.

= The claude.ai custom connector fails with "Couldn't register with the sign-in service" — why? =

In almost every reported case the OAuth flow is fine and the request never reaches WordPress: a security layer in front of your site is blocking Anthropic's backend, which connects with a non-browser User-Agent (`python-httpx`). Common culprits are hosting WAFs (cPGuard, Imunify360, ModSecurity rules like "generic HTTP client User-Agent") and Cloudflare's Bot Fight Mode or AI-crawler blocking. To diagnose, run `curl -A "python-httpx/0.28.1" https://your-site.com/.well-known/oauth-authorization-server` from an external machine — a 403 confirms the block. Ask your host to allow that User-Agent (or Anthropic's IP range 160.79.104.0/23) for `/.well-known/oauth-*`, `/oauth/*`, and `/wp-json/mcp/*`, or disable the relevant bot protection for the site.

= The OAuth discovery documents return a 301 redirect or 404 — is that a problem? =

Yes — strict OAuth clients require a direct `200` on `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`. This plugin already prevents WordPress's trailing-slash canonical redirect on those paths and serves the RFC 9728 path-suffixed variants. If they still return 404, your web server is intercepting `.well-known/` before WordPress runs (common with Let's Encrypt auto-SSL configs) — see **Tools → Site Health** for the "MCP OAuth discovery documents" check and ask your host to route those two paths to WordPress.

== Screenshots ==

1. Admin settings page showing all abilities organized by category with toggle switches.

== Changelog ==

= 2.4.0 =
* New: Tutor LMS section — 2 abilities to read and set a lesson's video source: `ewpa/tutor-get-lesson-video` and `ewpa/tutor-update-lesson-video`, both enabled by default. Requires Tutor LMS (`tutor_utils()`).
* Fix: writing a lesson's video through the generic `ewpa/update-post-meta` ability silently broke video playback — Tutor stores `_video` as a native PHP array, but `update-post-meta` always writes strings, and WordPress's own serialization safeguards (`is_serialized()`) prevent a plain or hand-serialized string from ever being reinterpreted as that array. `ewpa/tutor-update-lesson-video` calls `tutor_utils()->update_video()` directly — the same function Tutor's own editor uses — so the value is always stored correctly, including third-party sources registered via the `tutor_preferred_video_sources` filter (e.g. Bunny.net). Validated on a live Tutor LMS site with a Bunny.net-hosted lesson video.
* Updated: Total abilities: 84 in 18 categories.
* i18n: POT and Spanish (es_ES) translation updated.

= 2.3.0 =
* New: Navigation Menus section — 8 new abilities to create and manage a site's navigation menus entirely through MCP: `create-menu`, `list-menus`, `get-menu`, `add-menu-item` (pages, posts, categories, tags, or custom URLs, with parent/position), and `update-menu-item` are enabled by default; `remove-menu-item`, `assign-menu-location`, and `delete-menu` are opt-in (destructive). All require `edit_theme_options`, the same capability WordPress demands for Appearance → Menus.
* Updated: Total abilities: 82 in 17 categories.
* i18n: POT and Spanish (es_ES) translation updated.

= 2.2.1 =
* Fix: `ewpa/get-cpt-items` ignored the requested `post_type` when the `s` (search) parameter was set, silently returning items from a different post type. Caused by `suppress_filters => false` on the internal `get_posts()` call, which left third-party `pre_get_posts` hooks free to rewrite the query once a search term was present (observed on a Tutor LMS site, where `lesson` is excluded from search and a search-integration plugin fell back to `courses`). Removed the override so the ability uses `get_posts()`'s own safe default (`suppress_filters => true`), matching every other CPT-listing ability in the plugin. Reported by a user testing the MCP connector against a live Tutor LMS site.

= 2.2.0 =
* New: third-party ability management — abilities registered by other MCP-ready plugins (e.g. Fluent Forms 6.2.12+) now appear in the Abilities tab under a "Third-party" section per plugin namespace, with the same per-ability toggles. Disabling one unregisters it at the end of `wp_abilities_api_init`, removing it from every MCP server on the site (this plugin's claude.ai OAuth connector, the default adapter server, and the third-party plugin's own MCP endpoint). New third-party abilities start enabled, and disabled ones stay listed for re-enabling — validated end-to-end against Fluent Forms' MCP tools.
* New: plugin homepage at https://mcp.fabiomontenegro.com/ (Plugin URI); donations moved to Ko-fi.
* i18n: POT and Spanish (es_ES) translation updated.

= 2.1.1 =
* Fix: OAuth discovery-document handling (canonical-redirect prevention and RFC 9728 path-suffixed URLs) now works for sites installed in a subdirectory — the matchers derive the site's base path from `home_url()` instead of assuming a root install.
* New: multisite discovery bridge — on subdirectory networks, OAuth clients resolve `/.well-known/oauth-*` against the domain root, which belongs to the main site. With the plugin network-activated, the main site now resolves those requests to the owning subsite and relays its discovery document, making the claude.ai connector work for every subsite (validated against claude.ai on a production-style subdirectory multisite).
* Docs: Multisite FAQ updated with the subdirectory-network requirements.

= 2.1.0 =
* New: claude.ai OAuth Custom Connector — embedded OAuth 2.1 server (wp-media/mcp-oauth) with Client ID Metadata Document (CIMD) support. Add your site as a custom connector in claude.ai (web, mobile, or desktop) with just a URL — no Client ID, no Application Password: each user logs in with their own WordPress account and approves a consent screen. Opt-in from Settings > WP Abilities > Connection.
* New: Connection tab redesigned — authentication methods now read top-down as options (claude.ai OAuth, Application Passwords, Single Admin Bearer Token) and the client configuration examples (Claude Desktop / Claude Code, OpenAI Codex CLI, Google Antigravity) moved to a shared, highlighted "Connect your AI client" section with the MCP endpoint URL, since they apply to both token methods.
* New: generating Application Password credentials now auto-fills every client example with your real `Basic` authorization header — copy-paste ready, no manual editing.
* Fix: OAuth discovery documents (`/.well-known/oauth-*`) and `/oauth/*` endpoints no longer receive WordPress's trailing-slash 301 canonical redirect, which strict OAuth clients (claude.ai) reject as a failed metadata fetch.
* Fix: RFC 9728 path-suffixed discovery URLs (`/.well-known/oauth-protected-resource/<mcp-path>`) are now served, matching the lookup order of Anthropic's OAuth client.
* Docs: FAQ entries on diagnosing hosting WAFs (cPGuard/Imunify/ModSecurity) and Cloudflare bot protections that block Anthropic's `python-httpx` client.
* i18n: POT and Spanish (es_ES) translation updated with the new strings.

= 2.0.25 =
* New: Connection tab now includes ready-to-copy configuration for three AI clients — Claude Desktop / Claude Code (`claude_desktop_config.json`), OpenAI Codex CLI (`~/.codex/config.toml`, TOML `[mcp_servers.*]`), and Google Antigravity (`mcp_config.json`, direct `serverUrl` + `headers` connection with no npx required).
* New: Review request notice — appears only for administrators, only after 25+ logged ability executions (threshold filterable via `ewpa_review_notice_threshold`), and only on the Dashboard, Plugins, and plugin settings screens. Snoozable for 30 days or permanently dismissible; the shown count comes from the real activity log.
* i18n: POT and Spanish (es_ES) translation updated with the new strings.

= 2.0.24 =
* New: `ewpa/clear-cache` (Utility) — purges the page cache for a single post (`post_id`, requires edit_post) or the whole site (no param, requires manage_options). Auto-detects WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, and WP Fastest Cache; falls back to the WordPress object cache when none is active. Fixes the stale-audit loop: write abilities that modify post meta directly (`update-seopress`, `update-post-meta`, `elementor-update-element`) do not fire `save_post`, so cache plugins kept serving the old rendered HTML — SEO audits and visitors saw stale titles and meta descriptions after AI fixes. Auto-enabled on upgrade. Reported from a production site running LiteSpeed.
* Updated: Total abilities: 71 in 16 categories

= 2.0.23 =
* i18n: Regenerated the POT template (524 strings, was stuck at v2.0.13) and completed the Spanish (es_ES) translation — 101 new strings covering the JetEngine Options Pages, Elementor, LearnDash, SEOPress content analysis, and llms.txt sections. Recompiled .mo files.

= 2.0.22 =
* New: AI — Agent Readiness section (2 abilities). `ewpa/get-llms-txt` fetches the site llms.txt (the AI-crawler guidance file audited by Lighthouse "Agentic Browsing"), detects which component serves it (SEOPress Pro, physical file, this plugin, third-party, or none), and validates it against the llmstxt.org spec with actionable issues (missing H1, no blockquote summary, no Markdown links, raw HTML entities, oversize). `ewpa/update-llms-txt` (opt-in, manage_options) writes the content with automatic routing: SEOPress Pro option when active (its dynamic placeholders keep working), or a virtual /llms.txt served by this plugin via do_parse_request; refuses when a physical file or third-party plugin already provides it. Content validated before saving.
* Updated: Total abilities: 70 in 16 categories

= 2.0.21 =
* New: LearnDash section (6 abilities) — `ewpa/ld-get-courses`, `ewpa/ld-get-course`, `ewpa/ld-get-user-progress`, `ewpa/ld-get-quiz-results` (read, enabled by default); `ewpa/ld-enroll-user`, `ewpa/ld-unenroll-user` (write, disabled by default). Requires LearnDash. Guard: `class_exists('SFWD_LMS')`. `learndash_get_course_users_access_from_meta()` wrapped in `function_exists()` for broad compatibility.
* Fix: Per-post permission callbacks (get-post, get-page, get-cpt-item, update-post, delete-post, and 7 more) now return a descriptive `WP_Error` instead of bare `false`. Previously, a missing `post_id` parameter or a nonexistent post ID surfaced as a generic "Permission denied" with no detail — even for administrators — because `current_user_can()` with a per-post capability resolves to `do_not_allow` when the post does not exist. MCP clients now receive actionable messages: missing parameter, invalid post ID, or an actual capability denial. Reported in the support forum (bearer-token thread).
* Updated: Total abilities: 68 in 15 categories

= 2.0.20 =
* New: `ewpa/get-seopress-content-analysis` — reads the SEOPress content analysis for a post or page: every check (meta title, meta description, headings, internal links, structured data, image alt texts, content depth, etc.) with its impact level (good/low/medium/high) and plain-text recommendation, plus a summary count and the target keywords. Optional `refresh=true` runs a fresh SEOPress analysis of the rendered page first (internally dispatching `GET seopress/v1/posts/{id}/content-analysis`), so agents can update content and immediately re-check the recommendations. Requires SEOPress 7.5+ (fresh analysis honors SEOPress 30 req/min per-user rate limit). Read-only; auto-enabled on upgrade.
* Updated: Total abilities: 62 in 14 categories

= 2.0.19 =
* New: Elementor section (3 abilities) — `ewpa/elementor-get-structure` returns a compact, read-only tree of an Elementor page/template (element ids, types, text preview); `ewpa/elementor-update-element` edits the settings of an element by id (static content or styles), supporting single edits and a batch `edits[]` mode applied in one read/write pass; `ewpa/elementor-bind-dynamic-field` binds a widget setting to a dynamic tag (native Post Title, or a JetEngine/meta field). All three are opt-in (disabled by default). Requires Elementor (Elementor Pro for post-title, JetEngine for meta fields). All edits validate the tree, save with correct slashing, and clear the Elementor cache.
* Updated: Total abilities: 61 in 14 categories

= 2.0.18 =
* Security: `ewpa/get-post`, `ewpa/get-page`, and `ewpa/get-cpt-item` now enforce a per-post visibility check (`current_user_can( 'read_post', $id )`) in their `permission_callback`, instead of only the site-wide `read` capability. Previously a low-privilege user authenticating via Application Passwords could read drafts, private, or password-protected content of other authors by ID (IDOR). Not exploitable via the Bearer token (which authenticates as an administrator). Reported by Hardik (hnanda21).

= 2.0.17 =
* Fix: `ewpa_filter_core_abilities()` wrapper closure now uses `$input = null` and calls `$original()` vs `$original($input)` conditionally — fixes `ArgumentCountError` on `core/get-user-info` and `core/get-environment-info` (PHP 8.4), which have no `input_schema` and are invoked with zero arguments by `WP_Ability::invoke_callback()`. `core/get-site-info` was unaffected because it declares an input schema. Same root cause as the v2.0.14 fix for `ewpa_register_ability_with_log()`.

= 2.0.16 =
* Fix: `ewpa_authenticate_api_key()` now uses a static re-entry guard (`$resolving`) to prevent infinite recursion when `user_can()` inside `ewpa_validate_api_key()` triggers `map_meta_cap`. Plugins like Yoast SEO hook `map_meta_cap` and call `wp_get_current_user()` from within it, re-entering the `determine_current_user` filter and causing unbounded recursion (PHP fatal / HTTP 500). Reproduced with Yoast SEO + WPML String Translation active.

= 2.0.15 =
* Enhancement: `ewpa/je-update-options-page-field` now supports repeater fields — pass an array of row objects where each key matches a sub-field name. `ewpa/je-get-options-page` now returns `repeater_fields` (name, title, type) for repeater fields so the AI can inspect the expected row structure before writing.

= 2.0.14 =
* New: JetEngine Options Pages section (3 abilities) — `ewpa/je-list-options-pages` lists all registered options pages with their field schema; `ewpa/je-get-options-page` returns field values for a given slug; `ewpa/je-update-options-page-field` writes a single field value with blocklist protection (html, tab, accordion, endpoint types are blocked). Both list and get abilities are enabled by default; update is off by default. Requires JetEngine with the Options Pages module enabled.
* Fix: `ewpa_register_ability_with_log()` wrapper closure now uses `$input = null` (optional parameter) so abilities without an `input_schema` are not broken by PHP 8.4's `ArgumentCountError` when `WP_Ability::invoke_callback()` calls them with zero arguments.
* Updated: Total abilities: 58 in 13 categories

= 2.0.13 =
* Fix: `ewpa/update-post`, `ewpa/create-post`, `ewpa/create-page`, `ewpa/create-cpt-item`, `ewpa/update-cpt-item`, `ewpa/search-replace`, and `ewpa/tec-update-event` now use `wp_slash()` instead of `wp_kses_post()` on post content before passing to `wp_insert_post()` / `wp_update_post()`. This prevents double-unslashing that corrupted JSON Unicode escapes (e.g. `<` → `u003c`) in Gutenberg block attributes such as Yoast FAQ questions. KSES is now applied by WordPress via the `content_save_pre` filter, which correctly respects `unfiltered_html` capability — allowing admins to save `<script type="application/ld+json">` inside `wp:html` blocks without stripping.

= 2.0.12 =
* New: `ewpa/create-code-snippet` ability — creates a PHP snippet via the Code Snippets plugin (2.x or 3.x). Snippet is always saved as inactive; activation requires a manual step from wp-admin. Validates PHP syntax via `token_get_all( TOKEN_PARSE )`, blocks dangerous functions (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, `base64_decode`, `file_put_contents`, `unlink`, `chmod`), and fires `ewpa_after_create_code_snippet` for audit. Requires `manage_options`.
* Updated: Total abilities: 55 in 12 categories

= 2.0.11 =
* New: `ewpa/create-code-snippet` ability — creates a PHP snippet via the Code Snippets plugin (2.x or 3.x). Snippet is always saved as inactive; activation requires a manual step from wp-admin. Validates PHP syntax via `token_get_all( TOKEN_PARSE )`, blocks dangerous functions (`eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, `base64_decode`, `file_put_contents`, `unlink`, `chmod`), and fires `ewpa_after_create_code_snippet` for audit. Requires `manage_options`.
* Fix: `ewpa/update-post-meta` no longer applies `sanitize_text_field()` to `meta_value` — allows storing HTML, CSS, and JavaScript content without corruption
* Fix: `ewpa/update-post-meta`, `ewpa/update-cpt-item`, and `ewpa/create-cpt-item` now call `wp_slash()` before `update_post_meta()` — preserves backslashes in values received via the REST API, which `update_post_meta()` internally strips via `wp_unslash()`

= 2.0.10 =
* Fix: `ewpa/update-rankmath-schema` — added missing `permission_callback` required by `WP_Ability`; absence caused a PHP notice on every page load and prevented the ability from registering correctly

= 2.0.9 =
* Fix: `ewpa/update-rankmath-schema` now correctly discoverable by MCP adapters — added `additionalProperties: true` to the `schema_data` input parameter so WordPress accepts the object schema during ability registration

= 2.0.8 =
* New: `ewpa/update-rankmath-schema` ability — writes a structured-data schema block (FAQPage, Article, Product, VideoObject, etc.) to a Rank Math schema meta key as a PHP-serialized array, so Rank Math renders it as JSON-LD in `<head>`; supports 20 schema types
* Fix: `ewpa/update-post-meta` now blocks `rank_math_schema_*` keys and returns a clear error directing users to `ewpa/update-rankmath-schema` instead — prevents PHP fatal errors caused by storing raw JSON strings in a field that Rank Math expects to hold PHP-serialized arrays
* Updated: Total abilities: 54 in 11 categories

= 2.0.7 =
* New: `ewpa/get-active-plugins` utility ability — returns all active plugins with name, version, and detected capabilities (SEO, multilanguage, WooCommerce, Events Calendar)
* New: Multilanguage section — `ewpa/set-post-language` assigns a language code to an existing post via Polylang or WPML
* New: Multilanguage section — `ewpa/link-post-translation` links two posts as translations of each other in the same translation group via Polylang or WPML
* New: Multilanguage section — `ewpa/get-post-translations` returns the full translation map for a post: language code, post ID, title, permalink, and status for each available translation via Polylang or WPML
* New: `language` and `translation_of` parameters added to `ewpa/create-post` — set the language and link a translation group in a single call when Polylang or WPML is active
* New: `ewpa_get_translation_plugin()` helper — detects the active multilanguage plugin (`polylang`, `wpml`, or empty string)
* Fix: input schema for abilities with no parameters omits the `properties` key — prevents PHP `Cannot use object of type stdClass as array` error in WordPress schema validation
* Updated: Total abilities: 53 in 11 categories

= 2.0.6 =
* New: `ewpa/get-post-meta` utility ability — reads any single post meta field by exact key; returns the value and a `found` flag indicating whether the key exists; companion to `ewpa/update-post-meta`
* New: `ewpa_after_update_post_meta` action hook — fires after every `ewpa/update-post-meta` write with `($post_id, $meta_key, $meta_value)`; SEO plugins can use it to flush their internal meta cache (e.g. TSF, AIOSEO)

= 2.0.5 =
* Fix: WooCommerce and The Events Calendar ability registrations now use `'label'` instead of `'name'` — resolves PHP notices from `WP_Abilities_Registry::register` on every page load
* Fix: WooCommerce and TEC abilities now use `'execute_callback'` instead of `'callback'` — abilities were registered but never executed
* Fix: `'woocommerce'` and `'tec'` categories now registered in `ewpa_register_ability_categories()` — abilities now appear correctly in the Abilities Explorer
* Credit: props @magicwand for the detailed report with root cause analysis and local fix

= 2.0.4 =
* Compatibility: Tested and confirmed compatible with WordPress 7.0
* Fix: `EWPA_VERSION` constant corrected to match plugin header version (was stuck at 2.0.2)

= 2.0.3 =
* New: SEOPress section — `ewpa/get-seopress` reads SEO title, description, focus keyword, canonical URL, robots (noindex/nofollow/noarchive/noimageindex/nosnippet), Open Graph, and Twitter Card for any post or page
* New: SEOPress section — `ewpa/update-seopress` updates any combination of those fields; only provided fields are modified
* New: Yoast SEO section — `ewpa/yoast-get-seo` reads SEO title, description, focus keyphrase, canonical URL, robots (noindex/nofollow/advanced), Open Graph, and Twitter Card
* New: Yoast SEO section — `ewpa/yoast-update-seo` updates any combination of those fields; only provided fields are modified
* New: Yoast SEO section — `ewpa/yoast-get-sitemap-index` fetches and parses the Yoast sitemap index, returning all registered sitemap URLs with last modification dates
* New: `ewpa/update-post-meta` utility ability — writes any post meta field by exact key; useful for SEO plugins or custom fields not covered by dedicated sections; protected against internal WP keys via blocklist (filterable with `ewpa_blocked_meta_keys`)
* Improved: SEO meta in `get-post`, `get-page`, `create-post`, `update-post`, `create-page`, and `update-page` now auto-detects the active SEO plugin (Rank Math, Yoast SEO, The SEO Framework, SEOPress, AIOSEO) instead of writing to both Yoast and Rank Math keys simultaneously
* Updated: Total abilities: 48 in 10 categories

= 2.0.2 =
* Fix: Re-release to ensure all users receive the corrected admin CSS and JavaScript — sites that auto-updated to 2.0.1 before the tab and JS fixes were in place will now receive the correct assets
* Fix: Ability count in plugin description corrected to 42 (get-page and update-comment were added in 1.9.2/1.9.3)

= 2.0.1 =
* Fix: Activity log DB table now created correctly — `PRIMARY KEY` SQL formatted for dbDelta
* Fix: `ewpa_log_activity()` is self-healing — auto-creates table on first failed insert, no manual intervention required
* Fix: Input parameter unified to `status` (was `post_status`) across `ewpa/get-posts`, `ewpa/get-pages`, and `ewpa/get-cpt-items` for consistency with write abilities
* Fix: Uninstall now cleans up `ewpa_bearer_enabled` and `ewpa_db_version` options (previously leaked on uninstall)
* Fix: Admin tabs now use WordPress native `nav-tab` classes — resolves broken button styling on sites where themes or plugins override default button CSS
* Fix: Admin JavaScript wrapped in `document.readyState` guard — tabs and toggle now work correctly on sites with optimization plugins (WP Rocket, LiteSpeed, etc.) that defer or combine scripts
* Code quality: WPCS auto-fixed 14 issues; short ternary operators and docblock corrections applied manually
* Code quality: `phpcs.xml` ruleset updated — WooCommerce and The Events Calendar custom capabilities declared
* Code quality: Zero errors across all core plugin files
* Note: If tabs or the Bearer toggle appear broken after updating, purge your Cloudflare or CDN cache — CDNs may serve stale JS/CSS files regardless of the plugin version parameter

= 2.0.0 =
* New: Activity log — tracks every MCP ability execution per user with timestamp; viewable and clearable from the admin
* New: Three-tab admin interface: Connection, Activity Log, Abilities — cleaner organization
* New: Bearer token is now optional with an on/off toggle; existing installs auto-detected and migrated without breaking changes
* New: Application Passwords configuration section with step-by-step setup guide
* New: In-browser Base64 credential generator — no terminal required to configure Claude Desktop
* New: `includes/activity-log.php` — table management, logging helpers, and AJAX clear handler
* New: File-only upgrade migration via `plugins_loaded` hook — no reactivation needed
* Changed: All copy buttons use HTTP-safe clipboard fallback (works on non-HTTPS local environments)
* Updated: Total abilities: 42 (includes new get-page, update-comment added in previous minor versions)

= 1.9.3 =
* New: Update Comment ability (ewpa/update-comment) — update content, author name, email, or WordPress user of an existing comment

= 1.9.2 =
* New: Get Single Page ability (ewpa/get-page) — retrieves full page detail by ID including content, template, hierarchy, and SEO metadata

= 1.9.1 =
* Fix: Formally declare WooCommerce HPOS (High-Performance Order Storage) compatibility via FeaturesUtil::declare_compatibility(), resolving the WooCommerce compatibility warning in WP Admin

= 1.9.0 =
* Fix: Replace date() with gmdate() to avoid timezone-related display issues
* New: WooCommerce section with 7 dedicated abilities (products, orders, customers) — HPOS-compatible
* New: The Events Calendar section with 4 abilities (list, get, create, update events)
* Updated: Total abilities increased from 32 to 40
* Code quality: Added phpcs.xml.dist ruleset declaring WooCommerce and The Events Calendar custom capabilities
* Code quality: Zero errors, zero warnings across all plugin files

= 1.8.0 =
* New: 8 Custom Post Type abilities — list, get, create, update, delete CPT items, get taxonomies, and assign terms
* New: Full CPT support works with any plugin or theme (WooCommerce, ACF, JetEngine, custom code, etc.)
* New: All meta fields accessible on CPT items (including _price, _sku, ACF fields, etc.)
* New: Contextual admin notices for CPT section (no CPTs detected) and SEO section (Rank Math not active)
* New: Site statistics now include custom post type counts
* Changed: All ability keys standardized to English (e.g. ewpa/obtener-posts → ewpa/get-posts)
* Changed: All source strings standardized to English; Spanish moved to translation files
* Changed: Automatic migration preserves existing settings when upgrading from v1.7
* Total abilities increased from 24 to 32

= 1.7.0 =
* New: Admin notice when MCP Adapter plugin is not installed with download link
* New: MCP endpoint URL and Claude Desktop configuration example in API Key section
* Updated: Installation instructions reflect WordPress.org plugin directory availability

= 1.6.0 =
* New: Reply to comments ability (responder-comentario) — respond to existing comments as the authenticated user
* Fix: Rank Math focus keyword parameter changed from array to single string for proper MCP compatibility
* Improved: Updated actualizar-rankmath label and descriptions for better AI discovery via MCP
* Total abilities increased from 23 to 24

= 1.5.0 =
* New: API Key authentication for external MCP connections (Perplexity, custom connectors)
* New: Generate, regenerate, and revoke API keys from Settings > WP Abilities
* New: Bearer token authentication scoped to MCP REST API routes only
* New: API key stored as SHA-256 hash with timing-safe validation
* New: Authorization header extraction with Apache/Nginx/CGI fallbacks
* New: `includes/auth.php` module with authentication logic
* Clean uninstall updated to remove API key option

= 1.4.0 =
* Security: removed server filesystem path exposure from image upload response
* Security: removed SVG from allowed upload extensions (XSS prevention)
* Security: upgraded capability checks for Rank Math metadata and site statistics abilities
* Security: replaced `@unlink()` with `wp_delete_file()` for proper file deletion
* Security: replaced `user_email` with `user_login` in user listing ability to prevent email exposure
* Code quality: full WordPress Coding Standards (WPCS 3.x) compliance — zero errors, zero warnings
* Code quality: tabs indentation, Yoda conditions, spaces inside parentheses, proper docblocks
* Code quality: replaced short ternary operators with explicit ternaries and helper function
* Code quality: named function callbacks for activation hook
* Code quality: proper multi-line comment formatting

= 1.3.0 =
* New: SEO — Rank Math section with 2 dedicated abilities
* New: Get Rank Math metadata (title, description, keywords, canonical URL, robots, Open Graph, Twitter, primary category, pillar content, SEO score)
* New: Update Rank Math metadata with per-field granularity and input validation
* New: Upload image from URL ability — downloads external images to the media library with optional auto-assign as featured image
* Total abilities increased from 20 to 23

= 1.2.0 =
* Security hardening: runtime validation of all enum inputs (post_status, orderby, order)
* Security hardening: integer inputs clamped to allowed ranges
* Security hardening: per-post capability checks for edit, delete, and search-replace
* Security hardening: sanitize tags, validate featured images, author IDs, and post dates
* Security hardening: wp_unslash and sanitize nonce verification
* Fixed: page template uses sanitize_file_name instead of sanitize_text_field
* Fixed: search-replace validates empty search and sanitizes replacement with wp_kses_post

= 1.1.0 =
* Fixed: added `show_in_rest => true` to all custom abilities meta (required for REST API and MCP discovery)
* Fixed: ability categories now register on `wp_abilities_api_categories_init` hook

= 1.0.0 =
* Initial release
* 17 custom abilities: 8 read, 7 write, 2 utility
* 3 core abilities exposed to MCP
* Admin settings page with per-ability toggles

== Upgrade Notice ==

= 2.0.9 =
Fix: `ewpa/update-rankmath-schema` was not discoverable by MCP adapters due to an invalid object schema. Update immediately if you use this ability.

= 2.0.8 =
New: `ewpa/update-rankmath-schema` writes Rank Math structured-data schema blocks (FAQPage, Article, Product, etc.) safely as PHP-serialized arrays. Fix: `ewpa/update-post-meta` now blocks `rank_math_schema_*` keys to prevent PHP fatal errors.

= 2.0.6 =
New: `ewpa/get-post-meta` reads any single meta field (companion to update-post-meta). New: `ewpa_after_update_post_meta` action hook lets SEO plugins flush their cache after a write.

= 2.0.5 =
Fix: WooCommerce and The Events Calendar abilities were generating PHP notices and not executing correctly due to API key mismatches (`name`/`callback` instead of `label`/`execute_callback`). Update immediately if WooCommerce or TEC is active.

= 2.0.4 =
Tested and confirmed compatible with WordPress 7.0. Fixes an internal version constant mismatch introduced in 2.0.3.

= 2.0.3 =
New: SEOPress section (get + update), Yoast SEO section (get + update + sitemap index), Update Post Meta utility ability, and smart SEO plugin auto-detection in get/create/update post abilities. 48 abilities total.

= 2.0.2 =
Re-release of 2.0.1 fixes. If your admin tabs still appear as plain buttons after updating to 2.0.1, update to 2.0.2 and purge your Cloudflare or CDN cache.

= 2.0.1 =
Fix: activity log now records correctly after file-only updates. Input parameter unified to `status` across get-posts, get-pages, and get-cpt-items. Uninstall cleanup improved. **If you use Cloudflare or a CDN, purge your cache after updating** — otherwise the admin interface (tabs, toggle) may show stale assets.

= 2.0.0 =
Major update: activity log per user, new three-tab admin interface, Bearer token now optional with toggle, and in-browser credential generator for Application Passwords. No breaking changes — existing Bearer token installs auto-migrated. If you use Cloudflare or a CDN, purge your cache after updating.

= 1.9.3 =
New: Update Comment ability (ewpa/update-comment). Change content, author, email, or associated WordPress user of any existing comment.

= 1.9.2 =
New: Get Single Page ability (ewpa/get-page). Retrieves full page detail by ID including content, template, parent, and SEO metadata.

= 1.9.1 =
Adds formal WooCommerce HPOS compatibility declaration. Resolves the WooCommerce compatibility warning in WP Admin for sites using High-Performance Order Storage.

= 1.9.0 =
New WooCommerce (7) and The Events Calendar (4) ability sections. Total 40 abilities. Plus date() timezone fix and zero-error WPCS compliance.

= 1.8.0 =
Major update: 8 new Custom Post Type abilities for WooCommerce, ACF, JetEngine, and more. All keys standardized to English with automatic migration. Contextual admin notices for missing dependencies.

= 1.7.0 =
MCP Adapter dependency notice, connection example for Claude Desktop, and updated installation instructions for WordPress.org.

= 1.6.0 =
New reply to comments ability. Fixed Rank Math focus keyword input for reliable MCP integration.

= 1.5.0 =
New API Key authentication. Generate a Bearer token from the admin panel to connect external services like Perplexity via custom MCP connector.

= 1.4.0 =
Security and code quality update. Fixes path exposure, SVG uploads, email leaks, and capability levels. Full WPCS compliance. Recommended for all users.

= 1.3.0 =
New SEO section with Rank Math metadata read/write abilities. Read and update SEO title, description, focus keywords, robots, Open Graph, and more.

= 1.2.0 =
Security update. Adds input validation, per-post capability checks, and sanitization improvements. Recommended for all users.
