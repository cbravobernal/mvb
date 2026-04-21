# Design: Multi-User Library Model (v1.4.0)

## 1. Why `post_author` over a separate `user_id` meta column

WordPress CPTs natively carry `post_author` as a first-class field. Using it means:

- `WP_Query`'s `author` parameter works out of the box — no custom meta joins needed.
- WordPress's `map_meta_cap` machinery already knows how to resolve `edit_post` → author check.
- The author column is indexed in `wp_posts`, making per-user queries fast.
- Admin list screens automatically filter by author when user lacks `edit_others_*` cap,
  giving us free UI scoping.

A separate `library_user_id` meta would require a custom `meta_query` on every list fetch,
manual cap resolution in a `map_meta_cap` filter anyway, and duplicate data (post_author + meta).

**Decision**: use `post_author` as the canonical owner field. No user_id meta.

## 2. Why CPT over a custom DB table

Custom table pros: schema control, potentially leaner queries.
Custom table cons (decisive for this project):
- No WP_Query support — every list/filter/sort must be hand-built SQL.
- No WP-native meta API — `get_post_meta`, `update_post_meta`, `register_post_meta` all gone.
- No REST API exposure via `register_post_type` — separate endpoints needed.
- No SCF field group support — SCF binds field groups to post types, not raw tables.
- No admin UI scaffolding — list tables, bulk actions, row actions must all be written from scratch.
- No WP object cache integration.
- Migration complexity: custom activation/deactivation SQL.

CPT gives all of the above for free. The slight overhead of `wp_posts` columns (content, excerpt,
modified) is negligible for a library that stores data in meta anyway.

**Decision**: CPT wins on every axis for this plugin's scale and constraints.

## 3. How migration preserves existing data

The existing state before v1.4.0:
- All `videogame` posts may have non-admin `post_author` values from legacy single-user use.
- `videogame_completion_date` meta carries the completion date.
- `videogame_devices` meta (array of `mvb_device` post IDs) carries the device link.
  Migration takes the first element as `library_device_id`. Singular `videogame_device`
  is accepted as a fallback for forward compatibility.
- The `mvb_device` CPT is provided by the devices feature (separate change). Without it,
  the device dropdown is hidden and library entries store `library_device_id = 0`; no
  crash, no data loss.

Migration approach:
1. Detect admin via `get_users(['role' => 'administrator', 'number' => 1])`, take first result.
   Fallback to user ID 1 if no admin found (fresh install, unlikely).
2. Query all `videogame` posts excluding already-migrated ones (no `_mvb_migrated_v2` meta).
3. For each non-admin-authored post: create `mvb_library_entry` copying the meta, then
   reassign `post_author` to admin.
4. Mark the videogame as migrated via `_mvb_migrated_v2 = 1` post meta.

This is safe to re-run (idempotent via the `_mvb_migrated_v2` sentinel) and does not alter
any data not needed for the migration.

### Why we copy rather than reference date/device

`videogame_completion_date` is a catalog-level meta in the old schema (single user assumed).
Post-migration, library entries are the authoritative source for per-user completion dates.
We copy rather than reference so the library entry is self-contained and the videogame post can
have its own completion data cleared or updated independently in the future.

## 4. Hook Flow for Key Operations

### 4.1 Plugin Bootstrap (`init` priority 20)

```
MVB::init()
  └── MVB_Library::init()        → register_post_type('mvb_library_entry')
                                  → register_post_meta(6 fields)
  └── MVB_Registration::init()   → add_filter('option_users_can_register')
                                  → add_filter('pre_option_default_role')
                                  → add_action('login_redirect')
  └── MVB_Migration::init()      → checks mvb_schema_version option
                                  → runs migrate_to_v2() if version < 2
  └── MVB_Capabilities::init()   → register_post_type_args filter (existing)
                                  → admin_init → sync_roles (includes player role)
                                  → map_meta_cap filter (new)
  └── MVB_Admin::init()          → admin_menu → adds My Library submenu (new)
                                  → pre_get_posts → devices author scope (new)
                                  → wp_ajax_mvb_add_library_entry (new)
                                  → wp_ajax_mvb_search_catalog (new)
                                  → wp_ajax_mvb_delete_library_entry (new)
```

### 4.2 Library Entry Save (AJAX)

```
POST admin-ajax.php action=mvb_add_library_entry
  └── check_ajax_referer('mvb_add_library_nonce', 'nonce')
  └── current_user_can('publish_mvb_library_entries')
  └── wp_insert_post([post_type=>'mvb_library_entry', post_author=>get_current_user_id()])
  └── update_post_meta (6 meta fields)
  └── wp_send_json_success
```

### 4.3 Cap Resolution (`map_meta_cap`)

```
current_user_can('edit_post', $entry_id)
  └── map_meta_cap filter fires for mvb_library_entry
      └── if post_author == current_user_id → ['edit_own_mvb_library_entries']
      └── else                              → ['edit_others_mvb_library_entries']
```

## 5. `mvb_library_entry` vs `mvb_device` Ownership

`mvb_device` posts are also per-user (presumably). The devices filter in the My Library page
and admin device list uses the same `pre_get_posts` author-scoping pattern. `mvb_player`
role gets device caps for own devices only. The `map_meta_cap` filter covers device CPT too
via the same author-check logic, extended to cover `mvb_device` cap types.

## 6. No Public Frontend

`public => false` on `mvb_library_entry` means WordPress will not register rewrite rules for
it, will not include it in REST API post collections, and will not show it in frontend queries.
This is intentional. The plugin is an admin tool, not a public-facing site feature.

## 7. SCF Integration

SCF binds field groups to post types via `scf-config.json` location rules. We add a new
field group entry using the same JSON structure as the existing `Videogames Fields` group,
targeting `post_type == mvb_library_entry`. The field keys are prefixed `field_lib_` to avoid
collision. SCF reads this file on activation/import; no code changes to SCF itself.
