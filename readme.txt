=== Enable Abilities for MCP ===
Contributors: fabiomontenegro1987
Donate link: https://ko-fi.com/fabiomontenegro
Tags: mcp, ai, rest-api, content-management, woocommerce
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT & any MCP client to WordPress. 102 abilities: content, SEO, WooCommerce, FSE, LMS & more. Free & self-hosted.

== Description ==

**Enable Abilities for MCP** gives you full control over which WordPress Abilities are available to AI assistants through the MCP (Model Context Protocol) Adapter.

WordPress 6.9 introduced the Abilities API, allowing external tools to discover and execute actions on your site. This plugin extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

= Connect from claude.ai with just a URL =

Since version 2.1 the plugin ships an embedded OAuth 2.1 server built for claude.ai custom connectors. Add your site in claude.ai → Settings → Connectors, log in with your WordPress user, approve the consent screen — connected. No Client ID, no Application Password, no local configuration.

* Works from the claude.ai web app, mobile apps, and Claude Desktop
* Each team member authenticates with their **own WordPress account and role** — a subscriber can never do what only an editor should
* Every ability execution lands in the activity log under the real user's name
* Works on single sites, subdirectory installs, and multisite networks (network-activate so the main site serves the OAuth discovery documents for every subsite)

= Connect from ChatGPT with the same URL (beta) =

claude.ai identifies itself with a fixed metadata URL the plugin already trusts. ChatGPT instead registers itself dynamically per connector (RFC 7591), so it needs its own door: turn on **ChatGPT & Other OAuth Connectors** on the Connection tab and list the callback URLs a connector is allowed to send users back to. In ChatGPT, turn on Developer mode (available on paid plans) and create a connector with the same MCP server URL; the normal login-and-consent flow takes over.

* Opt-in and off by default — with the toggle off the discovery document drops `registration_endpoint`, `/oauth/register` refuses every request, and the claude.ai connector behaves exactly as before
* A connector may only ever return a user to a **callback URL you listed**, re-checked on every authorization request — remove one and clients that registered while it was allowed are blocked immediately
* Wildcards are allowed inside the path, never in the host; registration is capped and rate-limited per IP
* Connectors that ask you to paste a Client ID and Secret instead are covered too — the panel can issue one (the secret is shown once and stored only as a hash)
* Same as the claude.ai connector downstream: each user logs in with their own WordPress account and role, approves a consent screen, and every execution lands in the activity log under their name

Prefer tokens? Application Passwords (per-user) and a single-admin Bearer token connect Claude Desktop / Claude Code, OpenAI Codex CLI, and Google Antigravity — the Connection tab generates ready-to-paste configuration for each client, and fills in your credentials automatically.

= Features =

* **102 abilities** organized in 21 categories: Core, Read, Write, SEO (Rank Math), SEO (SEOPress), SEO (Yoast), Navigation Menus, Utility, Multilanguage, Custom Post Types, WooCommerce, The Events Calendar, Code Snippets, JetEngine Options Pages, JetEngine Query Builder, Elementor, LearnDash, Tutor LMS, AI Agent Readiness (llms.txt), FSE Block Templates, and Accessibility (WCAG)
* **WooCommerce integration** — dedicated abilities to manage products, orders, and customers using the native WooCommerce API (HPOS-compatible, formally declared)
* **The Events Calendar integration** — list, get, create, and update events with venue, organizer, and date filters
* **claude.ai OAuth custom connector** — connect from claude.ai (web, mobile, or desktop) with zero local setup: an embedded OAuth 2.1 server with Client ID Metadata Document (CIMD) support lets each user log in with their own WordPress account and role
* **ChatGPT & other OAuth connectors (beta)** — separately opt-in: RFC 7591 dynamic client registration at `/oauth/register`, gated by an administrator-managed callback allowlist, so clients that register themselves can connect through the same consent flow
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
* Duplicate any post, page, or custom post type item — including all post meta (ACF, SEO, featured image) and taxonomy terms; saved as a draft by default
* Assign a custom taxonomy's terms to a post or page (e.g. a taxonomy registered by a companion plugin)

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
* Read term meta by exact key, or all meta for a term
* Write a term meta field by exact key
* Update a term's core fields: name, slug, description, or parent

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
* List courses, and get a single course's full detail with its topics/lessons hierarchy
* Get a user's enrollment status and completion progress for a course
* Get a user's quiz attempt results, optionally filtered to a single quiz
* Enroll a user in a course, and unenroll them (both opt-in, `manage_options` only)

**Multilanguage:**

* Assign a language to an existing post via Polylang or WPML
* Link two posts as translations of each other in the same translation group
* Get the full translation map for a post: language, post ID, title, permalink, and status for each translation

**LearnDash:**

* List courses with enrollment count, and get a single course's full detail (lessons, topics, quizzes)
* Get a user's enrollment status/progress and quiz results for a course
* Enroll or unenroll a user in a course (opt-in — write, `manage_options`)

**JetEngine Options Pages:**

* List all registered Options Pages with their field schema
* Get all fields and current values for an Options Page by slug
* Update a single Options Page field, including repeater rows (opt-in — write)

**JetEngine Query Builder:**

* List all Query Builder queries with id, name, and query type
* Get the full settings of one query by id
* Update an existing query's name, type, or arguments — the missing counterpart to JetEngine's own native "Add Query" MCP tool, which has no edit/get/list equivalent (opt-in — write)

**Elementor:**

* Get a compact, read-only tree of an Elementor page/template (element ids, types, text preview)
* Update an Elementor element's settings by id — single or batch edits (opt-in — write)
* Bind a widget setting to a dynamic tag: post title, or a JetEngine/meta field (opt-in — write)

**Code Snippets:**

* Create a PHP code snippet via the Code Snippets plugin — always saved as inactive, activate manually from wp-admin. Validates PHP syntax and blocks dangerous functions (`eval`, `exec`, `shell_exec`, and more)

**AI Agent Readiness (llms.txt):**

* Fetch and validate the site's llms.txt against the llmstxt.org spec, with actionable issues
* Write llms.txt content — routes automatically to SEOPress Pro's option when active, or serves it directly (opt-in — write)

**FSE Block Templates:**

* List all `wp_template` and `wp_template_part` entries for the active theme, merging theme-file defaults with database overrides
* Get the full block markup for one template or template part by slug
* Write new block markup to a template or template part — creates a database override automatically when the target is still a theme default; rejects content with unbalanced block-comment delimiters (opt-in — write, `edit_theme_options`)

**Accessibility (WCAG):**

* Scan the media library for images missing alt text (WCAG 1.1.1 Non-text Content), paginated

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

= How do I connect ChatGPT? =

Turn on the OAuth server on the Connection tab, then turn on **ChatGPT & Other OAuth Connectors** below it. Check the **Allowed callback URLs** box — it is prefilled with the callbacks ChatGPT is commonly seen to use, so confirm the exact one your connector screen shows and delete the rest. Save, then in ChatGPT turn on Developer mode (available on paid plans) and create a connector with the same MCP server URL. ChatGPT reads the discovery document, finds `registration_endpoint`, registers itself, and runs the normal login-and-consent flow. The callback allowlist is the security boundary: a self-registered client can only ever return a user to a URL you listed.

= The OAuth discovery documents return a 301 redirect or 404 — is that a problem? =

Yes — strict OAuth clients require a direct `200` on `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`. This plugin already prevents WordPress's trailing-slash canonical redirect on those paths and serves the RFC 9728 path-suffixed variants. If they still return 404, your web server is intercepting `.well-known/` before WordPress runs (common with Let's Encrypt auto-SSL configs) — see **Tools → Site Health** for the "MCP OAuth discovery documents" check and ask your host to route those two paths to WordPress.

== Screenshots ==

1. Admin settings page showing all abilities organized by category with toggle switches.

== Changelog ==

= 2.9.0 =
* New: ChatGPT & Other OAuth Connectors (beta, opt-in, off by default) — RFC 7591 dynamic client registration at `/oauth/register`, so ChatGPT and other self-registering clients connect through the same login-and-consent flow as the claude.ai connector. The security boundary is an administrator-managed callback allowlist, enforced at registration and re-checked on every authorization request, so removing a URL immediately blocks clients that registered while it was allowed. The same list gates which client metadata documents are fetched (`wp_safe_remote_get`, no redirects). Consent, code issuance, PKCE, token signing and refresh rotation stay in the bundled wp-media/mcp-oauth library; with the toggle off, the claude.ai connector is unchanged. Contributed by @keyvansolha.
* New: Regression suite for the connector callback allowlist (`tests/oauth-connectors-test.php`, 42 checks) covering redirect-URI smuggling — userinfo and backslash authorities, subdomain confusion, port and path-prefix mismatches, query strings and fragments through wildcards, protocol-relative and non-HTTP schemes — plus RFC 8252 loopback handling. Runs without WordPress: `php tests/oauth-connectors-test.php`.
* Fix: IPv6 loopback redirect URIs (`http://[::1]:port/…`) were rejected, because PHP's `parse_url()` keeps the brackets around an IPv6 literal and the loopback check only knew `::1`. It failed closed, so nothing was ever allowed that should not have been; native clients on IPv6 loopback can now connect. Found by the new regression suite.

= 2.8.1 =
* New: `ewpa/assign-post-terms` (Write section, enabled by default) — assigns a custom taxonomy's terms to a post or page. `ewpa/assign-cpt-terms` explicitly rejects built-in post types (post, page, attachment, and others) by design, since native categories/tags on posts are already covered by `ewpa/update-post` — but that left a real gap for a custom taxonomy registered on `post`/`page` by a companion plugin, with no assignment path through MCP at all. Mirrors `ewpa/assign-cpt-terms`'s security checks exactly (`edit_post`, `taxonomy_exists`, taxonomy-post_type association, and the taxonomy's own `assign_terms` capability), so an admin-only taxonomy stays admin-only regardless of post type.
* Updated: Total abilities: 102 in 21 categories.

= 2.8.0 =
* New: Accessibility (WCAG) section — `ewpa/get-accessibility-snapshot` (read, enabled by default) scans the media library for images missing alt text (WCAG 1.1.1 Non-text Content) and returns a paginated list, so they can be fixed via `ewpa/update-post-meta` (`_wp_attachment_image_alt`). Deliberately narrow scope: full WCAG scanning (color contrast, ARIA, keyboard navigation) is a rendered-page/browser concern already covered by browser-based tools such as Lighthouse, not duplicated here.
* Updated: Total abilities: 101 in 21 categories.

= 2.7.2 =
* New: JetEngine Query Builder section (3 abilities) — `ewpa/je-list-queries` and `ewpa/je-get-query` (read, enabled by default) list and read Query Builder queries via JetEngine's own internal data layer (`Jet_Engine\Query_Builder\Manager`); `ewpa/je-update-query` (write, opt-in) updates an existing query's name, type, or arguments, converting `query_args` the same way JetEngine's own tools do. This is the missing counterpart to JetEngine's own native "Add Query" MCP tool — JetEngine ships its own separate, bundled MCP server with a tool to create a query but none to list, read, or edit one; these abilities close that gap through the standard WordPress Abilities API instead of JetEngine's internal tool registry. Requires JetEngine with the Query Builder module. Validated end-to-end on a live production site, including a real write to an in-use query with a non-trivial tax_query/post_type configuration that was confirmed intact after the update.
* Updated: Total abilities: 100 in 20 categories.

= 2.7.1 =
* New: `ewpa/update-term` (Custom Post Types section, enabled by default) — updates a taxonomy term's core fields (name, slug, description, parent term). Only the provided fields are modified. Complements `ewpa/get-term-meta` / `ewpa/update-term-meta` (v2.7.0), which only cover custom meta, not these core fields. Requested from a live JetEngine + Polylang site that needed to rename a mistranslated term.
* Updated: Total abilities: 97 in 19 categories.

= 2.7.0 =
* New: `ewpa/get-term-meta` and `ewpa/update-term-meta` (Custom Post Types section, enabled by default) — read and write taxonomy term meta by exact key, the term-level equivalent of `ewpa/get-post-meta` / `ewpa/update-post-meta`. `get-term-meta` returns every meta field for the term when `meta_key` is omitted. Both require the `edit_term` capability on the target term.
* Fix: `ewpa/get-cpt-taxonomies` threw an output-schema validation error ("not of type string") for taxonomies registered with `'label' => false` — a pattern used by internal taxonomies some multilingual plugins (e.g. Polylang) attach to custom post types. The taxonomy slug is now used as a fallback label instead of the raw `false` value. Reported from a live JetEngine + Polylang site.
* Updated: Total abilities: 96 in 19 categories.

= 2.6.0 =
* New: FSE Block Templates section (3 abilities) — `ewpa/fse-list-templates` and `ewpa/fse-get-template` (read, enabled by default) list and read `wp_template` / `wp_template_part` entries for the active theme via `get_block_templates()` / `get_block_template()`, merging theme-file defaults with database overrides; `ewpa/fse-update-template` (write, opt-in) writes new block markup, creating a database override when the target is still a theme default, and rejects content with unbalanced block-comment delimiters before saving. Guarded behind `current_theme_supports('block-templates')`. Requested by redsoulwarrior in a WordPress.org review.
* Fix: `ewpa/create-code-snippet` failed on every call with "Cannot instantiate Snippet class" on Code Snippets 3.10.0+ — its PSR-4 refactor moved the `Snippet` class from `Code_Snippets\Snippet` to `Code_Snippets\Model\Snippet`. Now checks both locations (plus the legacy global `Snippet` for 2.x), so it works across Code Snippets 2.x through 3.10+. Reported by redsoulwarrior with a confirmed version-downgrade repro.
* Docs: "Available Abilities" section now documents the Multilanguage, LearnDash, JetEngine Options Pages, Elementor, Code Snippets, and AI Agent Readiness (llms.txt) sections — previously listed only in the Features summary, not broken out in detail.
* Compatibility: Tested up to WordPress 7.1.
* Updated: Total abilities: 94 in 19 categories.

= 2.5.2 =
* Fix: `ewpa/search-replace` matched against a `sanitize_text_field()`-cleaned copy of the search term instead of the raw text — a search containing HTML tags, line breaks, or repeated whitespace would silently never match `post_content` (which stores that raw). The search and replacement values are now used as-is.
* Fix: `ewpa/search-replace` rejected a search term of `"0"` as empty, because PHP's `empty()` treats the string `"0"` as falsy. Now uses a strict empty-string check.
* Fix: `Stable tag` in this readme was left at 2.5.0 after the 2.5.1 release, so WordPress.org never picked up 2.5.1 as current. Corrected to 2.5.2.

= 2.5.1 =
* Fix: `ewpa/tutor-get-user-progress` always returned `enrollment_status: null` — Tutor's `EnrollmentModel::is_enrolled()` doesn't select `post_status` in its query, so the field silently read as null. Now resolved via `get_post()`.
* Docs: v2.5.0 also shipped 6 Tutor LMS parity abilities (list courses, get course detail with topics/lessons hierarchy, get user progress, get quiz results, enroll user, unenroll user) that were left undocumented in that release — now reflected here and in the Available Abilities list.
* Updated: Total abilities: 91 in 18 categories (corrected from the 85 mistakenly listed in 2.5.0).

= 2.5.0 =
* New: `ewpa/duplicate-post` — duplicates any post, page, or CPT item, copying all post meta (ACF fields, SEO data, featured image) and taxonomy terms. The copy is saved as a draft by default. Opt-in write ability.
* Fix: meta values are now re-slashed before `add_post_meta()` to match WordPress's internal `wp_unslash()` contract — prevents backslash loss in serialized or JSON meta fields (e.g. `_elementor_data`) when duplicating.
* Fix: self-healing migration automatically adds `ewpa/duplicate-post` to the enabled-abilities list on existing installs upgrading from earlier versions.
* Updated: Total abilities: 85 in 18 categories.
* i18n: POT and Spanish (es_ES) translation updated.

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

= 2.0.25 and earlier =
* See [changelog.txt](https://plugins.trac.wordpress.org/browser/enable-abilities-for-mcp/trunk/changelog.txt) for the full history of older versions.

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
