# Proposal: Multi-User Library Model (v1.4.0)

## Intent

Transform MVB from a single-owner plugin into a multi-user system where multiple registered
players each maintain their own game library, while sharing a single catalog of videogames
sourced from IGDB.

## Problem Statement

The current architecture stores all `videogame` posts under a single admin account. Every
videogame post is simultaneously catalog entry and personal library record. This conflates two
distinct concerns — "this game exists" (catalog) vs "this user owns/played this game"
(library) — making it impossible to have multiple users track their own progress against a
shared catalog without duplicating posts.

## Approach: Option A — Shared Catalog + Per-User Library Entries

The catalog (`videogame` CPT) remains globally shared. No per-user duplication of catalog
posts. A new CPT `mvb_library_entry` represents one user's relationship with one videogame. The
`post_author` field is the native WordPress mechanism for ownership — no extra user_id column
needed.

### Rationale for Option A over alternatives

- **Option B (per-user videogame posts)**: Duplicates catalog data, makes IGDB sync a nightmare,
  breaks cover image sharing, explodes post count.
- **Option C (custom table)**: Bypasses the WordPress object cache, requires manual migrations,
  cannot leverage WP_Query, meta API, REST API, or SCF field groups without heavy custom code.
- **Option A wins**: single source of truth for catalog, WP-native for library, REST-native,
  SCF-native, no raw SQL needed.

## Scope

### New

- `mvb_library_entry` CPT — private, no public frontend, admin UI via custom page.
- `mvb_player` role — registered user who can manage their own library entries.
- `MVB_Library` class — CPT registration, meta registration, helper queries.
- `MVB_Registration` class — native WP registration flow tweaks (role default, login redirect).
- `MVB_Migration` class — one-time migration of legacy data, versioned and idempotent.
- "My Library" admin page — per-user filtered list of entries + add-game form.

### Modified

- `MVB_Capabilities` — adds `mvb_player` role, library_entry cap grants, own-vs-others meta cap enforcement.
- `MVB_Admin` — adds "My Library" submenu, per-user devices filter.
- `class-mvb.php` — bootstraps three new classes.
- `mvb.php` — version bump 1.3.2 → 1.4.0, requires new files, activation hooks.
- `scf-config.json` — new field group for `mvb_library_entry`.
- `README.md` — documents multi-user library feature.

### Out of Scope

- Public frontend for library entries.
- REST API write endpoints (entries are managed via admin UI only).
- Email notifications.
- Social/sharing features.

## Rollback Plan

1. Remove or deactivate the plugin.
2. The `mvb_library_entry` posts remain in the DB but are inert without the plugin.
3. The migrated `videogame` posts remain published under admin; original `post_author` was
   changed to admin as part of migration — this is irreversible without a snapshot. Admins
   should back up before first activation on a live site.

## Affected PHP Classes and WordPress Hooks

| Class | Hook(s) |
|---|---|
| `MVB_Library` | `init` (register_post_type, register_post_meta) |
| `MVB_Registration` | `option_users_can_register`, `pre_option_default_role`, `login_redirect` |
| `MVB_Migration` | `init` (version check + migrate), `admin_notices` |
| `MVB_Capabilities` | `register_post_type_args`, `admin_init`, `map_meta_cap` |
| `MVB_Admin` | `admin_menu`, `pre_get_posts`, `wp_ajax_*` |

## SCF Field Dependencies

A new SCF field group `library_entry_details` is added to `scf-config.json` for the
`mvb_library_entry` post type. Fields mirror the registered meta fields.
