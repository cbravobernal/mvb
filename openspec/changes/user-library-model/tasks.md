# Tasks: Multi-User Library Model (v1.4.0)

## Phase 1 — OpenSpec Artifacts

- [x] 1.1 Create `openspec/changes/user-library-model/proposal.md`
- [x] 1.2 Create `openspec/changes/user-library-model/spec.md`
- [x] 1.3 Create `openspec/changes/user-library-model/design.md`
- [x] 1.4 Create `openspec/changes/user-library-model/tasks.md`

## Phase 2 — New Files

- [x] 2.1 Create `includes/class-mvb-library.php`
  - [x] 2.1.1 `MVB_Library::init()` — hooks registration
  - [x] 2.1.2 `register_library_entry_cpt()` — registers `mvb_library_entry`
  - [x] 2.1.3 `register_library_meta()` — registers 6 meta fields via `register_post_meta`
  - [x] 2.1.4 `get_user_library($user_id, $args)` — WP_Query helper
  - [x] 2.1.5 `get_entry_videogame($entry_id)` — returns referenced videogame post
  - [x] 2.1.6 `user_has_game($user_id, $videogame_id)` — existence check

- [x] 2.2 Create `includes/class-mvb-registration.php`
  - [x] 2.2.1 Filter `option_users_can_register` → 1
  - [x] 2.2.2 Filter `pre_option_default_role` → `mvb_player`
  - [x] 2.2.3 `login_redirect` hook — redirect `mvb_player` to My Library
  - [x] 2.2.4 `admin_footer` link to registration page

- [x] 2.3 Create `includes/class-mvb-migration.php`
  - [x] 2.3.1 Version sentinel check on `init`
  - [x] 2.3.2 `migrate_to_v2()` — non-admin videogames → library entries
  - [x] 2.3.3 Orphan handling (post_author == 0 → reassign to admin)
  - [x] 2.3.4 Idempotency via `_mvb_migrated_v2` post meta
  - [x] 2.3.5 `admin_notices` callback with count summary

## Phase 3 — Modified Files

- [x] 3.1 Modify `includes/class-mvb-capabilities.php`
  - [x] 3.1.1 Add `player_caps()` static method
  - [x] 3.1.2 Add library_entry and device admin caps to `admin_caps()`
  - [x] 3.1.3 Add `register_mvb_player_role()` called from `sync_roles()`
  - [x] 3.1.4 Add `map_meta_cap` filter for `mvb_library_entry` own-vs-others enforcement
  - [x] 3.1.5 Bump `ROLE_VERSION` to `3`

- [x] 3.2 Modify `includes/class-mvb-admin.php`
  - [x] 3.2.1 Add `mvb-my-library` submenu registration
  - [x] 3.2.2 Add `render_my_library_page()` method
  - [x] 3.2.3 Add AJAX handler `mvb_add_library_entry`
  - [x] 3.2.4 Add AJAX handler `mvb_search_catalog`
  - [x] 3.2.5 Add AJAX handler `mvb_delete_library_entry`
  - [x] 3.2.6 Add `pre_get_posts` filter for devices author-scoping

- [x] 3.3 Modify `includes/class-mvb.php`
  - [x] 3.3.1 Instantiate `MVB_Library::init()`
  - [x] 3.3.2 Instantiate `MVB_Registration::init()`
  - [x] 3.3.3 Instantiate `MVB_Migration::init()`
  - [x] 3.3.4 Activation: call `MVB_Capabilities::register_mvb_player_role()`

- [x] 3.4 Modify `mvb.php`
  - [x] 3.4.1 Bump `Version:` header 1.3.2 → 1.4.0
  - [x] 3.4.2 Bump `MVB_VERSION` constant
  - [x] 3.4.3 Add `require_once` for 3 new class files

- [x] 3.5 Modify `scf-config.json`
  - [x] 3.5.1 Add `library_entry_details` field group with 5 fields

- [x] 3.6 Modify `README.md`
  - [x] 3.6.1 Add "Multi-user library (v1.4.0)" section

## Phase 4 — Quality

- [x] 4.1 Run `composer run lint` and fix any violations
- [x] 4.2 Save apply-progress to engram
