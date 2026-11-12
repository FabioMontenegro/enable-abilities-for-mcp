=== Enable Abilities for MCP ===
Contributors: fabiomontenegro1987
Donate link: https://ko-fi.com/fabiomontenegro
Tags: mcp, ai, rest-api, content-management, woocommerce
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.16.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT & any MCP client to WordPress. 114 abilities: content, SEO, WooCommerce, FSE, LMS & more. Free & self-hosted.

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

* **114 abilities** organized in 21 categories: Core, Read, Write, SEO (Rank Math), SEO (SEOPress), SEO (Yoast), Navigation Menus, Utility, Multilanguage, Custom Post Types, WooCommerce, The Events Calendar, Code Snippets, JetEngine Options Pages, JetEngine Query Builder, Elementor, LearnDash, Tutor LMS, AI Agent Readiness (llms.txt), FSE Block Templates, and Accessibility (WCAG)
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

* Get posts with filters (status, category, tag, search, meta key and value)
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

**Multilanguage (Polylang, WPML, or Linguator AI):**

* List the languages configured on the site
* Create the translation of a post or page in a target language, linked to the source: the AI supplies the translated title, content, and excerpt; taxonomies, custom fields, and the featured image are copied over, and an existing translation is updated instead of duplicated
* Create the translation of a category, tag, or custom taxonomy term: translated name and description, term meta copied, parent remapped to its target-language counterpart
* Assign a language to an existing post or taxonomy term
* Link two posts, or two terms, as translations of each other in the same translation group
* Get the full translation map for a post or a term

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

* Create a PHP code snippet via the Code Snippets plugin — always saved as inactive. Validates PHP syntax and blocks dangerous functions (`eval`, `exec`, `shell_exec`, and more)
* List snippets (without their code) and read one snippet by ID, including its code
* Update a PHP snippet's name, description, code, scope, or tags (opt-in); new code is validated, and an active snippet whose code changes is deactivated
* Deactivate a snippet, or request its activation (opt-in). Activation is never automatic: the request returns a wp-admin link where an administrator reviews the code and confirms it. The confirmation is bound to the exact code and expires after 24 hours

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
4. Install and configure the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin to connect with AI assistants.

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

= The ChatGPT connector fails with "metadata must advertise PKCE support with code_challenge_methods_supported containing S256" — why? =

The discovery documents do advertise `S256`, and checking them in a browser proves nothing: ChatGPT reads them server-side with the same non-browser User-Agent Claude uses (`python-httpx`). When a WAF or bot protection answers that request with a 403 or an HTML challenge page, ChatGPT never gets the authorization-server document and falls back to the protected-resource document, which has no PKCE field — hence this error. Diagnose it like the claude.ai case above, with `curl -A "python-httpx/0.28.1" https://your-site.com/.well-known/oauth-authorization-server` from an external machine, and ask your host to allow that User-Agent for `/.well-known/oauth-*`, `/oauth/*`, and `/wp-json/mcp/*`. With `WP_DEBUG_LOG` on, `[DISCOVERY] request received` lines in `wp-content/debug.log` tell you whether the request reached WordPress at all.

= The OAuth discovery documents return a 301 redirect or 404 — is that a problem? =

Yes — strict OAuth clients require a direct `200` on `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`. This plugin already prevents WordPress's trailing-slash canonical redirect on those paths and serves the RFC 9728 path-suffixed variants. If they still return 404, your web server is intercepting `.well-known/` before WordPress runs (common with Let's Encrypt auto-SSL configs) — see **Tools → Site Health** for the "MCP OAuth discovery documents" check and ask your host to route those two paths to WordPress.

== Screenshots ==

1. Admin settings page showing all abilities organized by category with toggle switches.

== Changelog ==

= 2.16.0 =
* New: `ewpa/create-upload-link` gets an image from the user's computer into the media library without the file passing through the conversation. Sending a file as base64 makes the model type out the whole file, which is slow, expensive and usually fails on size. Instead the ability returns a temporary link: an assistant that can run commands sends the file with a single `PUT`, and one that cannot (Claude Desktop, claude.ai) hands the user a small upload page to drop the image on. The link works once, for 10 minutes, for the user who created it, who must still be able to upload files when it is used. Only JPEG, PNG, GIF, WebP and AVIF are accepted, checked by content and not just by name, up to the site's upload limit capped at 20 MB. Each user can hold five unused links at a time. Only a hash of the link is stored. Opt-in.
* New: `ewpa/get-upload-status` tells the assistant whether the file has arrived and returns the attachment ID and URL. A link that belongs to someone else reads as expired, so it cannot be used to probe other users' uploads. Opt-in.
* New: filters `ewpa_upload_link_ttl`, `ewpa_upload_link_max_bytes` and `ewpa_upload_link_max_active` adjust the link lifetime, the size cap and the per-user limit.
* Security: password-protected posts no longer give away their content. A user who could read a post but not edit it got its full content and excerpt from `ewpa/get-post`, `ewpa/get-page` and `ewpa/get-cpt-item`, its excerpt from `ewpa/get-posts` and `ewpa/get-cpt-items`, and its description from `ewpa/tec-get-event`, `ewpa/ld-get-course` and `ewpa/tutor-get-course`. That content now comes back empty, as in the WordPress REST API, and the single-item readers return `password_protected` so the assistant knows why. `ewpa/duplicate-post` refuses to copy a protected post the user cannot edit. Users who can edit the post see everything as before.
* Security: `ewpa/elementor-update-element` filters the HTML it writes for users without the `unfiltered_html` capability, as WordPress does for post content. It writes the Elementor data directly, so Elementor's own filtering never ran, and an author could store a script in a page. Administrators and editors on a single site are not affected.
* Updated: Total abilities: 114 in 21 categories.

= 2.15.0 =
* New: `ewpa/create-post` accepts `meta`, an object of custom fields written in the same request that creates the post. An agent that tags each post with its own run ID can now find, after a timeout or a retry, the post an earlier attempt already created, instead of publishing it twice. Protected keys (those starting with an underscore) still need an administrator, and if any key is refused the post is deleted again, so a failed call never leaves a half-written post behind.
* New: `ewpa/get-posts` filters by custom field with `meta_key`, and optionally `meta_value` for an exact match. Filtering needs an editing capability, since it reveals which posts carry a value; protected keys need an administrator. `meta_value` on its own is refused rather than ignored. Drafts by other authors stay hidden exactly as before.

= 2.14.0 =
* Fixed: the four WooCommerce read abilities asked for a capability to *edit* instead of one to *read*, so a user whose role was built to read orders and products and nothing else was refused. `ewpa/wc-get-orders` and `ewpa/wc-get-order` now ask for `read_private_shop_orders`, and `ewpa/wc-get-products` and `ewpa/wc-get-product` for `read_private_products`. Nobody loses access: a role that only holds the old `edit_shop_orders` or `edit_products` is still accepted. Plain `read` is not enough, and is not meant to be — order data carries the buyer's name, address, e-mail and phone, so the role still has to be given the capability to read private records of that type. Reported by @petis.
* New: the `ewpa_ability_capability` filter changes the capability an ability requires, as `( $capability, $ability )`. It is currently routed through those four WooCommerce abilities, so a read-only role can be given exactly the access it needs. It tightens as readily as it loosens, and when it is used the built-in capability is no longer accepted, so the value you return is the only one that grants access. An empty or numeric value is refused and the built-in capability stands: WordPress rewrites a numeric capability into `level_0`, which every subscriber holds, so a site writing `'0'` to close the door would have opened it to every logged-in user instead.
* New: a Settings link in the plugin's row on the Plugins screen. The settings page lives under Settings rather than in its own top-level menu, which made it easy to lose. A Donate link sits on the description line, beside "View details".

= 2.13.7 =
* **Security:** the Bearer token could be used against the whole WordPress REST API, not only this plugin's MCP routes. The check that limited it read the request URL, but WordPress decides which route to run from the `rest_route` query variable, and that variable wins over the URL. A request could therefore carry the allowed text in the URL while WordPress served a completely different route. Because the token is only ever issued to an administrator, anything the REST API exposes to an administrator was reachable, regardless of which abilities the site owner had enabled. The check now reads the route WordPress is actually serving, and matches it on a namespace boundary. Found in-house while testing an unrelated change; no reports of it being used. **If you use the Bearer token, update.** Application Passwords and the OAuth connector were never affected.
* Fixed: the Bearer token now works on sites that do not use pretty permalinks. The old check looked for `/wp-json/` in the URL, which those sites never send, so the token was always refused there.
* New: the `ewpa_bearer_route_prefixes` filter adds REST route prefixes the Bearer token is accepted on, for sites that run a second MCP server — JetEngine 3.9 ships its own. It is a filter and not a setting on purpose: the token carries administrator rights, so every prefix added is driven as an administrator, and that belongs in reviewed code.

= 2.13.6 =
* Fixed: ten abilities were switched back on by themselves on every page load, so turning any of them off never lasted beyond the next request. Seven of the migrations that add abilities to existing installs never recorded that they had run, unlike every other migration in the plugin, so they ran again on every request and added their abilities back. The ten: `ewpa/duplicate-post`, `ewpa/fse-list-templates`, `ewpa/fse-get-template`, `ewpa/get-term-meta`, `ewpa/update-term-meta`, `ewpa/update-term`, `ewpa/je-list-queries`, `ewpa/je-get-query`, `ewpa/get-accessibility-snapshot` and `ewpa/assign-post-terms`. Your current selection is kept as it is: on an install that already passed those releases the migrations are recorded as done instead of running one final time, so an ability you had turned off stays off. An install upgrading from an older release still receives the abilities it never got. Reported by @peterbrej.

= 2.13.5 =
* Updated: the bundled `wp-media/mcp-oauth` library goes from 1.0.3 to 2.0. Other plugins ship their own copy of the same library — Imagify carries a newer one — and since every Composer autoloader involved registers itself first, whichever plugin loads last wins for the whole site. Shipping the oldest copy meant we could win that race and leave another plugin running against a library older than it expects. This release also drops the `replace` workaround that kept `wordpress/mcp-adapter` out of the packaged files: version 2.0 no longer requires it and relies on the standalone MCP Adapter plugin, as this plugin already did.
* Updated: `composer.lock` is now part of the repository, so the packaged library versions are reproducible instead of depending on the machine that builds the release.

= 2.13.4 =
* Fixed: the "requires the MCP Adapter plugin to work" notice appeared on sites where MCP Adapter was installed and active. Since its 0.7.0 release the adapter refuses to start when another plugin has already registered its own bundled copy of `WP\MCP\Core\McpAdapter`, and it then never defines `WP_MCP_VERSION`, which is what this plugin checked. The notice now tells three situations apart: the adapter working, the adapter present but stopped, and the adapter missing. When it is present but stopped the notice names the folder the conflicting copy was loaded from, so the plugin shipping it can be found instead of reinstalling one that is already there. Reported by @joshbour.

= 2.13.3 =
* Security: object-level authorization across every ability that reads or writes a post. A capability was checked site-wide (`edit_posts`, `read`, `edit_products`) but never against the specific post, so a Contributor could duplicate another author's draft and publish it keeping the original author, publish their own posts without review, and a Subscriber could list every draft on the site. Every per-post ability now checks `read_post` or `edit_post` on the target, an `author_id` from the input requires `edit_others_posts`, and a `post_parent` from the input requires permission on the parent.
* Security: student records (`ewpa/tutor-get-user-progress`, `ewpa/tutor-get-quiz-results`) require `edit_users`, matching their LearnDash equivalents, instead of `edit_posts`. The SEO readers (Rank Math, SEOPress, Yoast), `ewpa/tec-get-event`, `ewpa/ld-get-course` and `ewpa/get-accessibility-snapshot` now check the object or a capability that matches what they return.
* Security: `ewpa/upload-image` checks `edit_post` on the parent before attaching an image or setting a featured image.
* Security: protected meta keys (leading underscore) require `edit_post` on the target plus either `manage_options` or an explicit `register_post_meta()` / `auth_post_meta_{key}` authorization. Writing SEO keys such as `_genesis_title` keeps working for administrators; `ewpa/get-cpt-item` no longer returns protected meta to a user who cannot edit the item.
* Security: creating a term by name requires the taxonomy's `edit_terms` capability, in `ewpa/assign-post-terms`, `ewpa/assign-cpt-terms` and the `taxonomies` field of the CPT abilities.
* Fixed: `ewpa/get-posts` and `ewpa/get-pages` no longer emit "Undefined array key" notices for `status`, `orderby` and `order`, which disclosed the server path on any site rendering PHP notices.
* Fixed: a disabled third-party ability could silently re-enable itself when its plugin happened to be inactive while the settings page rendered, because the denylist was recalculated from whatever that page offered.
* **Behavior change:** asking for `publish`, `future` or `private` without the publish capability of that post type now returns a `forbidden` error, which is what the WordPress REST API does, instead of silently saving a draft. An agent flow that relied on that silent fallback will receive an error from now on.
* **Behavior change:** the listing abilities validate `status` against the statuses actually registered on the site and return `invalid_status` for anything else. Custom statuses such as WooCommerce's `wc-processing` now work, and a typo no longer falls back to `date`/`publish` without saying so.
* Credit: the authorization issues in this release were reported privately by stilwel, who reproduced them on their own test installation and withheld disclosure until a fix was available.

= 2.13.2 =
* New: FAQ entry for the ChatGPT connector failing with "metadata must advertise PKCE support with code_challenge_methods_supported containing S256". Both Claude and ChatGPT fetch the discovery documents server-side with a `python-httpx` User-Agent, so a WAF blocking it breaks the connector while every browser check keeps passing. Reported by @slavaliutkevich.
* Updated: the MCP Adapter links now point to its WordPress.org page instead of its GitHub releases. The plugin was published to the directory on 2026-10-02, so it can be installed from the dashboard like any other plugin.

= 2.13.1 =
* Security fix: turning the "claude.ai OAuth Custom Connector" switch off now ends every existing OAuth connector session on that site. Before, the sessions were only unreachable while the switch was off, so turning it back on let an old connector reconnect on its own for up to 30 days without a new approval.
* Security fix: the OAuth server now stays off while the switch is off even when another plugin boots the same OAuth library. On some multisite networks the MCP endpoint kept answering, and accepting existing tokens, with the switch off.
* Security fix: changing a user's password, or using "Log Out Everywhere" on their profile, now also ends that user's OAuth connector sessions. Application Passwords the user created by hand are never touched.
* Fix: deactivating the plugin ends its OAuth connector sessions, so they cannot come back when it is activated again.
* Improvement: the Application Password behind each OAuth session is now named "MCP OAuth – <client> – <date>" instead of just the client name, and its "Last Used" and "Last IP" columns are filled in, so you can see what you are revoking under Users › Profile › Application Passwords.
* New: Regression suite for session revocation (`tests/oauth-sessions-test.php`). Runs without WordPress: `php tests/oauth-sessions-test.php`.

= 2.13.0 =
* New: the CPT abilities can manage post types that are not public, when they are on an explicit allowlist. Tutor LMS `topics` is on it by default when Tutor LMS is active, so an assistant can finally build a course tree: course, topic, lesson. Every other non-public type is still rejected, WordPress built-ins remain unreachable, and all capability checks are unchanged. Site owners can add their own structural types with the new `ewpa_manageable_private_post_types` filter. Reported from a Tutor LMS site.
* New: `ewpa/update-cpt-item` accepts `post_parent` and `menu_order`, mirroring `ewpa/create-cpt-item`. A hierarchical item created in the wrong place, or a duplicate, can now be re-parented and reordered instead of being stuck. An item cannot be made its own parent, and a missing parent is refused.
* New: Regression suite for the post type guard and the update path (`tests/cpt-abilities-test.php`, 21 checks). Runs without WordPress: `php tests/cpt-abilities-test.php`.

= 2.12.0 =
* New: ability sections in Settings › WP Abilities collapse like an accordion. Click a section header to open or close it; the "All" checkbox keeps working without toggling the section. Sections start collapsed, and the ones you open stay open in that browser.
* New: each ability section shows how many of its abilities are enabled (for example, 2/3), updated as you change the toggles.

= 2.11.1 =
* Fix: the plugin no longer ships its own copy of MCP Adapter. The OAuth library pulled MCP Adapter 0.5.0 into `vendor/` as a dependency. Development builds of MCP Adapter check for a copy loaded before them; because this plugin loads first, they found that copy, showed "Another version of MCP Adapter is already loaded", and ran the bundled 0.5.0 instead of themselves. The MCP Adapter plugin you install is now the only copy this plugin provides. Reported by @donbowman.
* Fix: the "requires the MCP Adapter plugin" notice now detects the MCP Adapter plugin itself, so it no longer appears when the plugin is active but installed in a folder with a different name.

= 2.11.0 =
* New: `ewpa/get-code-snippets` and `ewpa/get-code-snippet` (Code Snippets section, enabled by default) list the snippets stored by the Code Snippets plugin (without their code) and read one snippet by ID, including its code. Requires manage_options.
* New: `ewpa/update-code-snippet` (opt-in) changes a PHP snippet's name, description, code, scope, or tags, leaving every other field untouched. New code goes through the same syntax check and blocked-function list as `ewpa/create-code-snippet`; an active snippet whose code changes is deactivated so the new code is reviewed before it runs.
* New: `ewpa/set-code-snippet-active` (opt-in) deactivates a snippet immediately, but never activates one directly. Asking for activation files a request and returns a wp-admin link where an administrator reads the code and approves or rejects it. The approval form needs a WordPress login nonce, which MCP credentials cannot produce; the request is bound to a hash of the code, expires after 24 hours, and every check runs again at approval time. Pending requests are announced with an admin notice. Suggested by @torontotoph.
* Fix: `ewpa/create-code-snippet` stored snippets created with `scope: frontend` as global, because Code Snippets names that scope `front-end` and silently falls back to `global` for values it does not know, so a snippet meant for the front end also ran in wp-admin. Both spellings are now accepted and stored as `front-end`.
* Fix: abilities marked opt-in (disabled by default) were enabled on fresh installs, because the first activation enabled every ability. New installs now leave them off; existing installs keep their current settings. `ewpa/remove-menu-item`, `ewpa/assign-menu-location`, and `ewpa/delete-menu` are now flagged opt-in too, matching their descriptions.
* Fix: the Code Snippets section showed "plugin is not active" with Code Snippets 3.10 or later, whose API moved into a namespace.
* New: Regression suite for the Code Snippets helpers and the confirmation flow (`tests/code-snippets-test.php`, 33 checks). Runs without WordPress: `php tests/code-snippets-test.php`.
* Updated: Total abilities: 112 in 21 categories.

= 2.10.1 =
* Fix: `ewpa/duplicate-post` copied the internal translation-group taxonomies of Polylang and Linguator AI along with regular terms, so on a multilingual site the duplicate joined the original's translation group and its language switcher and translation links pointed at the original's translations. The duplicate now keeps the source language but gets its own group.
* Fix: `ewpa/link-post-translation` and `ewpa/link-term-translation` reported success on Polylang even when Polylang discarded the link because the translated post or term did not already carry the target language. They now assign that language first, as the WPML and Linguator AI backends already did, and return a `translation_not_linked` error if the link still does not persist.
* Fix: `ewpa/list-languages` returned `term_id` 0 for every Polylang language; it now returns each language's term ID.

= 2.10.0 =
* New: Linguator AI support in the Multilanguage section, alongside Polylang and WPML: detection, languages, post and term languages, and translation groups through Linguator's public API. Contributed by @drbelt27.
* New: `ewpa/create-post-translation` (Multilanguage, enabled by default) creates the translation of a post or page in a target language and links it to the source. The AI supplies the translated title, content, and excerpt; the ability never calls a translation service. Taxonomies, custom fields, and the featured image are copied, with backslashes preserved so JSON meta such as Elementor's `_elementor_data` survives intact; on Linguator AI the plugin's own duplication engine is used. If a translation already exists in that language it is updated instead of duplicated. Contributed by @drbelt27.
* New: `ewpa/create-term-translation` (enabled by default) does the same for categories, tags, and custom taxonomy terms: translated name and description, term meta copied, and the parent remapped to its target-language counterpart. Updating an existing translation changes only the supplied fields. Contributed by @drbelt27.
* New: `ewpa/list-languages`, `ewpa/set-term-language`, `ewpa/link-term-translation`, and `ewpa/get-term-translations` (enabled by default) list the configured languages and manage term languages and translation groups. Contributed by @drbelt27.
* New: Regression suite for the multilanguage backend (`tests/multilanguage-backend-test.php`, 42 cases), with test doubles that unslash like WordPress core and validate languages like Polylang. Runs without WordPress: `php tests/multilanguage-backend-test.php`.
* Updated: Total abilities: 108 in 21 categories.

= 2.9.0 and earlier =
* See [changelog.txt](https://plugins.trac.wordpress.org/browser/enable-abilities-for-mcp/trunk/changelog.txt) for the full history of older versions.

== Upgrade Notice ==

= 2.16.0 =
Security fix: password-protected posts no longer give away their content to users who cannot edit them, and Elementor element edits filter HTML like core. Adds opt-in abilities to upload an image through a temporary, single-use link.

= 2.15.0 =
`create-post` can write custom fields in the same call, and `get-posts` can filter by custom field, so an agent can check whether a post already exists before creating it again. Nothing changes for calls that do not use them.

= 2.14.0 =
Fixes the four WooCommerce read abilities, which asked for a capability to edit instead of one to read, so a role built only to read orders and products was refused. Roles that worked before keep working. Adds the `ewpa_ability_capability` filter, and a Settings link in the plugin's row on the Plugins screen.

= 2.13.7 =
Security release. The Bearer token could be used against the whole REST API instead of only this plugin's MCP routes, giving anyone holding it the administrator access the REST API allows, whatever abilities were enabled. Update if you use the Bearer token. Application Passwords and the OAuth connector were not affected.

= 2.13.6 =
Fixes ten abilities that switched back on by themselves on every page load, which made it impossible to keep them turned off. The selection you have now is preserved, and an install coming from an older release still gets the abilities it never received.

= 2.13.5 =
Maintenance: updates the bundled OAuth library to 2.0 so this plugin is no longer the oldest copy on sites where another plugin bundles the same library. No change to how the connector is set up or used.

= 2.13.4 =
Fixes a misleading notice that told administrators to download MCP Adapter on sites where it was already installed and active, but had been stopped from starting by another plugin's bundled copy of it. The notice now names the plugin responsible.

= 2.13.3 =
Security release, recommended for every site. Closes a privilege escalation: a Contributor could duplicate and publish another author's draft keeping the original author, and a Subscriber could list every draft on the site. Behavior change: requesting `publish`, `future` or `private` without the publish capability now returns an error instead of saving a draft in silence.

= 2.13.2 =
Documentation only: explains the ChatGPT PKCE error caused by a WAF blocking the discovery requests, and points the MCP Adapter links at its new WordPress.org page.

= 2.13.1 =
Security fix: turning the OAuth connector off, changing a password or logging out everywhere now ends existing OAuth connector sessions. Recommended for every site using the claude.ai or ChatGPT connector.

= 2.13.0 =
New: CPT abilities can now manage Tutor LMS topics, so a full course tree can be built over MCP, and `ewpa/update-cpt-item` can re-parent and reorder items.

= 2.11.1 =
Fix: removes the bundled copy of MCP Adapter that triggers "Another version of MCP Adapter is already loaded" on newer MCP Adapter builds. Keep the MCP Adapter plugin installed and active.

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
