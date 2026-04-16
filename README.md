# My Videogames Backlog (MVB)

A WordPress plugin to manage your video game collection using the IGDB API. This plugin has been developed entirely using AI assistance.

## 🤖 AI-First Development

- This plugin has been written entirely using AI (Claude)
- All Pull Requests should preferably be written using AI assistance
- The commit history and development process demonstrates AI-human collaboration

## 📋 Requirements

### Secure Custom Fields Plugin
This plugin requires the [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/) plugin with the following configuration:

⚠️ Note: You can import the configuration from the `secure-custom-fields.json` file by using the `Secure Custom Fields` → `Import` → `Import from File` option.
You may skip the three steps below.

1. Custom Post Type Setup
Create a custom post type called `videogame` with the following settings:

```php
public => true,
has_archive => true,
supports => array('title', 'editor', 'thumbnail'),
menu_icon => 'dashicons-games'
```

2. Custom Fields Setup

Create the following custom fields:

- `videogame_cover` (Image field)
- `videogame_status` (Select field with options: backlog, playing, completed, abandoned)
- `videogame_release_date` (Date field)

3. Custom Taxonomies Setup
Create the following taxonomies (both should only be registered for the `videogame` post type):

**Companies**

- Taxonomy Key: `company`
- Hierarchical: true (like categories)
- Public: true
- Post Types: `videogame` only

**Release Platforms**

- Taxonomy Key: `release-platform`
- Hierarchical: true (like categories)
- Public: true
- Post Types: `videogame` only

## 🔧 Installation

1. Install and activate the Secure Custom Fields plugin
2. Configure the required custom post type, fields, and taxonomies as described above
3. Install and activate this plugin
4. Go to Settings → MVB to configure your IGDB API credentials

## 🔑 IGDB API Setup

1. Create a Twitch Developer account at https://dev.twitch.tv/
2. Register your application to get Client ID and Client Secret
3. Enter these credentials in the plugin settings page

## 🎮 Features

- Search games using IGDB database
- Auto-import game information including:
  - Title
  - Cover image
  - Release date
  - Description
  - Platform information
- Manage your game collection with status tracking
- Organize games by companies and platforms
- Native Gutenberg Block Bindings (`mvb/videogame`) so core blocks can display videogame meta without custom blocks

## 🧩 Block Bindings

MVB registers a Block Bindings source named `mvb/videogame`. Any core block that supports bindings (Paragraph, Heading, Image, Button) can read videogame meta directly.

**Available keys**

| Key | Description |
| --- | --- |
| `videogame_completion_date` | Date the game was completed (normalized to the site's date format) |
| `videogame_release_date` | Game release date |
| `hltb_main_story` | Main story length in hours (HowLongToBeat) |
| `igdb_id` | IGDB identifier |

**Example — Paragraph bound to completion date**

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"mvb/videogame","args":{"key":"videogame_completion_date"}}}}} -->
<p>placeholder</p>
<!-- /wp:paragraph -->
```

Requires WordPress 6.5+ (Block Bindings API).

## 🤝 Contributing

Since this is an AI-first project:

1. Use AI assistance (like GitHub Copilot, ChatGPT, Cursor, etc.) when possible.
2. Include the AI tool used in PR descriptions
3. Keep the AI-human collaboration transparent
4. Follow WordPress coding standards

## 📝 License

GPL v2 or later

## 👥 Credits

- Developed with AI assistance (Claude)
- IGDB API for game data
- WordPress and Secure Custom Fields plugin

## ⚠️ Note

This is an experimental project showcasing AI-human collaboration in WordPress plugin development. Use in production at your own discretion.
