# Enable Abilities for MCP

Manage which WordPress Abilities are exposed to MCP (Model Context Protocol) servers. Enable or disable each ability individually from the dashboard.

## Description

WordPress 6.9 introduced the **Abilities API**, allowing external tools to discover and execute actions on your site. **Enable Abilities for MCP** extends that functionality by registering a comprehensive set of content management abilities and providing a simple admin interface to toggle each one on or off.

### Features

- **24 abilities** organized in 5 categories: Core, Read, Write, SEO, and Utility
- **Admin dashboard** with toggle switches for each ability
- **Per-ability control** — expose only what you need
- **Secure by design** — proper capability checks, input sanitization, and per-post permission validation
- **WPCS compliant** — fully passes WordPress Coding Standards (phpcs)
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
- Reply to comments as the authenticated user
- Upload images from external URLs to the media library (with optional auto-assign as featured image)

**SEO — Rank Math:**
- Get full Rank Math metadata for any post/page (title, description, keywords, robots, Open Graph, SEO score)
- Update Rank Math metadata: SEO title, description, focus keyword, canonical URL, robots, Open Graph, primary category, pillar content

**Utility:**
- Search and replace text in post content
- Site statistics overview

## Requirements

- WordPress 6.9 or later (Abilities API)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin installed and configured
- PHP 8.0 or later

## Installation

1. In your WordPress dashboard, go to **Plugins > Add New** and search for **Enable Abilities for MCP**.
2. Click **Install Now**, then **Activate**.
3. Go to **Settings > WP Abilities** to manage which abilities are active.
4. Install and configure the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin to connect with AI assistants.

## Development

### Code Quality

This plugin follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) (WPCS 3.x).

```bash
# Install dev dependencies
composer install

# Run code sniffer
vendor/bin/phpcs --standard=WordPress --extensions=php --exclude=WordPress.Files.FileName .

# Auto-fix formatting issues
vendor/bin/phpcbf --standard=WordPress --extensions=php --exclude=WordPress.Files.FileName .
```

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.
