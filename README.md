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

## 👥 Multi-user library (v1.4.0)

Starting from v1.4.0, MVB supports multiple players, each with their own personal game library.

### Architecture

MVB uses a **shared catalog + per-user library** model:

- The `videogame` CPT is the global game catalog (IGDB-sourced, admin-managed).
- Each player has their own `mvb_library_entry` posts (one per game they track).
- Library entries store per-user data: status, completion date, device, rating, and notes.

### Player Registration

1. When the plugin is active, WordPress registration is enabled automatically.
2. New registrants receive the `mvb_player` role by default.
3. After login, players are redirected directly to **My Library** (not the dashboard).
4. Admins can find the registration URL in the footer of any MVB admin page.

### mvb_player Role

The `mvb_player` role has the following capabilities:

| Area | What they can do |
|---|---|
| Library entries | Create, edit, delete **their own** entries |
| Devices | Create, edit, delete **their own** devices |
| Videogame catalog | No access (read-only via My Library page) |
| WordPress admin | Access to My Library page only |

Players cannot edit other players' library entries or access catalog management pages.

### My Library Admin Page

Players see a **My Library** menu item in the WordPress admin:

- Lists all their library entries with status, completion date, device, and rating.
- Provides an **Add Game to My Library** form with:
  - Live search of the shared videogame catalog (by title, with cover thumbnail).
  - Status selector (Backlog / Playing / Completed / Dropped / Wishlist).
  - Completion date picker.
  - Device selector (from their own devices + admin-created devices).
  - Rating (1–10) and free-form notes.

### Data Migration (v2 schema)

The first time an administrator loads the WordPress admin after upgrading to v1.4.0,
the plugin automatically migrates legacy data:

- Any `videogame` post with a non-admin `post_author` gets a `mvb_library_entry` created
  for that author, copying `completion_date` and the first device ID from the
  `videogame_devices` meta (array) into the new entry.
- The `videogame` post is then reassigned to the admin user (catalog stays admin-owned).
- Orphaned posts (`post_author = 0`) are reassigned to admin with no library entry.
- Migration runs under `admin_init` and is gated on `manage_options`, so unauthenticated
  frontend hits never trigger the reassignment.
- Each videogame is only marked migrated **after** its reassignment (and library entry,
  when applicable) completes successfully, so a transient failure can be retried on the
  next admin page load without losing data.
- A dismissible admin notice summarises the result.

> **Important**: Back up your database before upgrading on a live site. The migration
> changes `post_author` on videogame posts, which cannot be automatically reversed.

### Dependencies and opt-outs

- **Devices CPT (`mvb_device`)**: the device picker in the My Library form and the
  device migration both expect the `mvb_device` custom post type and the
  `videogame_devices` meta (array of device IDs) to be available. Both are provided by
  the devices feature (planned for a later patch). Without them, the device dropdown is
  hidden and library entries are created with an empty device field — no crash, no
  data loss.
- **Registration opt-out**: the plugin enables native WordPress registration and sets
  `mvb_player` as the default role. Administrators can disable this behavior by
  returning `false` from the `mvb_enable_registration` filter:
  ```php
  add_filter( 'mvb_enable_registration', '__return_false' );
  ```

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
