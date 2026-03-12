# Enable Abilities for MCP

Manage which WordPress Abilities are exposed to MCP (Model Context Protocol) servers. Enable or disable each ability individually from the dashboard.

## Description

WordPress 6.9 introduced the **Abilities API**, allowing external tools to discover and execute actions on your site. **Enable Abilities for MCP** extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

### Features

- **20 abilities** organized in 4 categories: Core, Read, Write, and Utility
- **Admin dashboard** with toggle switches for each ability
- **Per-ability control** — expose only what you need
- **Secure by design** — proper capability checks, input sanitization, and per-post permission validation
- **MCP-ready** — all abilities include `show_in_rest` and `mcp.public` metadata

### Available Abilities

**Read (safe, query-only):**
- Get posts with filters (status, category, tag, search)
- Get single post details (content, SEO meta, featured image)
- Get categories, tags, pages, comments, media, and users

**Write (create & modify):**
- Create, update, and delete posts
- Create categories and tags
- Create pages
- Moderate comments

**Utility:**
- Search and replace text in post content
- Site statistics overview

## Requirements

- WordPress 6.9 or later (Abilities API)
- [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin installed and configured
- PHP 8.0 or later

## Installation

1. Upload the `enable-abilities-for-mcp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings > WP Abilities** to manage which abilities are active.
4. Install and configure the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin to connect with AI assistants.

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.
