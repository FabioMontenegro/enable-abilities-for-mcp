=== Enable Abilities for MCP ===
Contributors: fabiomontenegro1987
Donate link: https://paypal.me/fabiomontenegroz
Tags: mcp, ai, rest-api, content-management, woocommerce
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.9.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage which WordPress Abilities are exposed to MCP servers. Supports WooCommerce, The Events Calendar, and any custom post type.

== Description ==

**Enable Abilities for MCP** gives you full control over which WordPress Abilities are available to AI assistants through the MCP (Model Context Protocol) Adapter.

WordPress 6.9 introduced the Abilities API, allowing external tools to discover and execute actions on your site. This plugin extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

= Features =

* **40 abilities** organized in 8 categories: Core, Read, Write, SEO, Utility, Custom Post Types, WooCommerce, and The Events Calendar
* **WooCommerce integration** — dedicated abilities to manage products, orders, and customers using the native WooCommerce API (HPOS-compatible, formally declared)
* **The Events Calendar integration** — list, get, create, and update events with venue, organizer, and date filters
* **Admin dashboard** with toggle switches for each ability
* **Per-ability control** — expose only what you need
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

**Utility:**

* Search and replace text in post content
* Site statistics overview (includes custom post type counts)

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

Yes. The plugin can be network-activated. Each site in the network has its own ability configuration.

= Does it work with WooCommerce? =

Yes. The Custom Post Types section automatically detects WooCommerce products, orders, coupons, and any other registered post type. You can list, create, update, and delete items with full access to WooCommerce meta fields like `_price`, `_sku`, `_stock_status`, `_regular_price`, and more.

= Can I add custom abilities? =

This plugin registers abilities using the standard `wp_register_ability()` API. You can register additional abilities in your own plugin using the `wp_abilities_api_init` hook.

== Screenshots ==

1. Admin settings page showing all abilities organized by category with toggle switches.

== Changelog ==

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
