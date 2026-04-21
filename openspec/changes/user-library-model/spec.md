# Spec: Multi-User Library Model (v1.4.0)

## 1. Data Model

### 1.1 `mvb_library_entry` CPT

| Argument | Value |
|---|---|
| `post_type` | `mvb_library_entry` |
| `hierarchical` | `false` |
| `public` | `false` |
| `show_ui` | `true` |
| `show_in_menu` | `false` (added via admin submenu) |
| `show_in_rest` | `false` (library is admin-only, no public REST exposure) |
| `supports` | `['title', 'author', 'custom-fields']` |
| `capability_type` | `['mvb_library_entry', 'mvb_library_entries']` |
| `map_meta_cap` | `true` |

The `post_author` field MUST store the WordPress user ID of the player who owns the entry.
The `post_title` SHOULD be auto-set to the referenced videogame title for readability.

### 1.2 Meta Fields

All fields registered via `register_post_meta` with `show_in_rest => true` (so SCF and
the REST API can read them). All have `single => true`.

| Meta Key | Type | Sanitize | Description |
|---|---|---|---|
| `library_videogame_id` | `integer` | `absint` | Post ID of the referenced `videogame` post |
| `library_completion_date` | `string` | Ymd format validation | Date game was completed (Ymd string, e.g. 20240315) |
| `library_device_id` | `integer` | `absint` | Post ID of the `mvb_device` post used to play |
| `library_rating` | `integer` | `absint`, clamp 0–10 | User rating 1–10; 0 means unset/null |
| `library_status` | `string` | whitelist enum | One of: `backlog`, `playing`, `completed`, `dropped`, `wishlist` |
| `library_notes` | `string` | `wp_kses_post` | Free-form notes, allows basic HTML |

### 1.3 Capabilities Map

#### `mvb_player` role — MUST have

| Capability | Scope |
|---|---|
| `read` | Core WordPress |
| `edit_mvb_library_entries` | Primitive: can edit own entries |
| `edit_own_mvb_library_entries` | Primitive: own entry editing |
| `edit_published_mvb_library_entries` | Primitive: edit after publish |
| `publish_mvb_library_entries` | Primitive: publish own entries |
| `delete_mvb_library_entries` | Primitive: delete own |
| `delete_own_mvb_library_entries` | Primitive: own entry deletion |
| `delete_published_mvb_library_entries` | Primitive: delete own published |
| `edit_mvb_devices` | Can edit own devices |
| `edit_own_mvb_devices` | Own device editing |
| `edit_published_mvb_devices` | Edit after publish |
| `publish_mvb_devices` | Publish own devices |
| `delete_mvb_devices` | Delete own devices |
| `delete_own_mvb_devices` | Own device deletion |
| `delete_published_mvb_devices` | Delete own published devices |

`mvb_player` MUST NOT have any `videogame` caps — catalog management stays admin-only.

#### `administrator` role — MUST additionally have

All `mvb_library_entry` and `mvb_library_entries` primitives (including `others` variants)
plus all existing `mvb_game` / `mvb_games` caps already granted.

#### `map_meta_cap` enforcement

When WordPress resolves `edit_post` or `delete_post` for a `mvb_library_entry`:
- If `post_author == current_user_id` → map to `edit_own_mvb_library_entries`.
- If `post_author != current_user_id` → map to `edit_others_mvb_library_entries` (requires admin).

### 1.4 `mvb_player` Role Summary

```
mvb_player = {
  read: true,
  // library entries
  edit_mvb_library_entries: true,
  edit_own_mvb_library_entries: true,
  edit_published_mvb_library_entries: true,
  publish_mvb_library_entries: true,
  delete_mvb_library_entries: true,
  delete_own_mvb_library_entries: true,
  delete_published_mvb_library_entries: true,
  // devices
  edit_mvb_devices: true,
  edit_own_mvb_devices: true,
  edit_published_mvb_devices: true,
  publish_mvb_devices: true,
  delete_mvb_devices: true,
  delete_own_mvb_devices: true,
  delete_published_mvb_devices: true,
}
```

## 2. Migration Rules

### 2.1 Version Sentinel

- Option key: `mvb_schema_version`.
- Migration target version: `2`.
- If stored value < `2`, migration runs on `admin_init` (priority 20) and ONLY when
  `current_user_can( 'manage_options' )` — unauthenticated frontend hits MUST NOT trigger it.

### 2.2 `migrate_to_v2()` Algorithm

For every `videogame` post where `post_author` is NOT the admin ID (first user with
`manage_options`, typically ID 1):

1. MUST create one `mvb_library_entry` for that `post_author`:
   - `library_videogame_id` = videogame post ID.
   - `library_completion_date` = copy of `videogame_completion_date` meta, normalised to
     `Ymd` (if set and parseable).
   - `library_device_id` = first element of `videogame_devices` meta (the canonical key
     from the devices feature; an array of `mvb_device` post IDs). If `videogame_devices`
     is unset, the singular `videogame_device` meta is accepted as a fallback.
   - `library_status` = `completed` if `library_completion_date` is set, else `backlog`.
2. MUST re-assign `post_author` of the videogame to admin ID.
3. MUST only mark the videogame as migrated AFTER BOTH steps above succeed. If either
   fails, the videogame MUST be left in its current state so the migration can be
   retried on the next admin page load.

For every `videogame` post where `post_author == 0`:
- MUST set `post_author` to admin ID.
- MUST NOT create a library entry (no user to assign it to).
- MUST only mark the videogame as migrated AFTER the reassignment succeeds.

### 2.3 Idempotency

Migration MUST be idempotent:
- Track migrated videogame IDs via `_mvb_migrated_v2` post meta on the videogame.
- If `_mvb_migrated_v2` is set on a videogame, skip that post.
- The meta MUST only be set after all associated writes (library entry + reassignment)
  succeed for that videogame. This lets failed migrations retry on subsequent admin
  page loads without data loss.

### 2.4 Post-Migration Notice

After a successful run, an `admin_notices` callback MUST display a dismissible notice:
- Count of entries created.
- Count of videogames reassigned.
- A warning if any errors occurred.

## 3. Registration Flow

### 3.1 Native Registration Activation

`MVB_Registration` MUST:
- Filter `option_users_can_register` to return `1` (enables WP native registration).
- Filter `pre_option_default_role` to return `mvb_player`.

### 3.2 Login Redirect

Users with the `mvb_player` role MUST be redirected to `admin.php?page=mvb-my-library` on
login. Admins MUST be redirected to the default admin dashboard.

### 3.3 Register Link

An `admin_footer` hook on MVB admin pages SHOULD output a link to the native WP registration
page as informational text for admins.

## 4. My Library Admin Page

### 4.1 Submenu

MUST register a submenu page `mvb-my-library` under `edit.php?post_type=videogame` with:
- Capability: `read` (visible to all logged-in users).
- Render callback: `MVB_Admin::render_my_library_page()`.

### 4.2 Library List

The page MUST display a table of the current user's own `mvb_library_entry` posts.
Query MUST be author-scoped: `author => get_current_user_id()`.

Columns: Title (game name), Status, Completion Date, Device, Rating, Actions (Edit/Delete).

### 4.3 Add Game to Library Form

The page MUST include an "Add Game to My Library" form with:
- Videogame search/select (text input with AJAX search by title, shows cover thumbnail).
- Status select (`backlog`, `playing`, `completed`, `dropped`, `wishlist`).
- Completion Date (date input).
- Device select (from `mvb_device` posts authored by current user + admin devices).
- Rating (0–10 select; 0 = unset).
- Notes textarea.
- Nonce field.

All form fields MUST be sanitized on save. Nonce MUST be verified.

### 4.4 Devices Filter

On the devices admin list page, if current user cannot `manage_options`:
- `pre_get_posts` MUST force `author => get_current_user_id()` to limit visible devices to own.

## 5. Scenarios

### S1 — New player registration
**Given** a visitor reaches the WP registration page
**When** they complete registration
**Then** they are assigned the `mvb_player` role and redirected to My Library on first login.

### S2 — Library entry creation
**Given** a logged-in player on the My Library page
**When** they fill out the Add Game form and submit
**Then** a new `mvb_library_entry` post is created with `post_author = current_user_id` and
all meta fields saved.

### S3 — Own entry editing
**Given** a player with role `mvb_player`
**When** they attempt to edit their own `mvb_library_entry`
**Then** `current_user_can('edit_post', $entry_id)` returns `true`.

### S4 — Cross-user entry denied
**Given** a player with role `mvb_player`
**When** they attempt to edit another player's `mvb_library_entry`
**Then** `current_user_can('edit_post', $entry_id)` returns `false`.

### S5 — Migration idempotency
**Given** migration has already run (schema_version == 2)
**When** the plugin is loaded again
**Then** `migrate_to_v2()` does NOT run again.

### S6 — Orphan videogame migration
**Given** a `videogame` post with `post_author = 0`
**When** migration runs
**Then** `post_author` is set to admin ID and no `mvb_library_entry` is created.
