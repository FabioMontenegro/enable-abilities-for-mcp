=== Enable Abilities for MCP ===
Contributors: fabiomontenegro1987
Donate link: https://paypal.me/fabiomontenegroz
Tags: mcp, ai, rest-api, content-management, automation
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage which WordPress Abilities are exposed to MCP (Model Context Protocol) servers. Enable or disable each ability individually from the dashboard.

== Description ==

**Enable Abilities for MCP** gives you full control over which WordPress Abilities are available to AI assistants through the MCP (Model Context Protocol) Adapter.

WordPress 6.9 introduced the Abilities API, allowing external tools to discover and execute actions on your site. This plugin extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

= Features =

* **20 abilities** organized in 4 categories: Core, Read, Write, and Utility
* **Admin dashboard** with toggle switches for each ability
* **Per-ability control** — expose only what you need
* **Secure by design** — proper capability checks, input sanitization, and per-post permission validation
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

**Utility:**

* Search and replace text in post content
* Site statistics overview

= Requirements =

* WordPress 6.9 or later (Abilities API)
* MCP Adapter plugin installed and configured
* PHP 8.0 or later

== Installation ==

1. Upload the `enable-abilities-for-mcp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
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

Yes. The plugin can be network-activated. Each site in the network has its own ability configuration.

= Can I add custom abilities? =

This plugin registers abilities using the standard `wp_register_ability()` API. You can register additional abilities in your own plugin using the `wp_abilities_api_init` hook.

== Screenshots ==

1. Admin settings page showing all abilities organized by category with toggle switches.

== Changelog ==

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

= 1.2.0 =
Security update. Adds input validation, per-post capability checks, and sanitization improvements. Recommended for all users.
