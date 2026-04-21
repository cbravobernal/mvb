# Skill Registry — mvb

Generated: 2026-04-20
Project: mvb (My Videogame Boilerplate — WordPress plugin)

## User Skills

| Skill | Trigger |
|-------|---------|
| `branch-pr` | When creating a pull request, opening a PR, or preparing changes for review |
| `go-testing` | When writing Go tests, using teatest, or adding test coverage |
| `issue-creation` | When creating a GitHub issue, reporting a bug, or requesting a feature |
| `judgment-day` | Parallel adversarial review — when reviewing PRs, code, or proposals with high stakes |
| `sdd-apply` | When the orchestrator launches implementation of tasks from a change |
| `sdd-archive` | When the orchestrator archives a completed change |
| `sdd-design` | When the orchestrator creates a technical design document |
| `sdd-explore` | When the orchestrator investigates a feature or clarifies requirements |
| `sdd-init` | When initializing SDD in a project |
| `sdd-onboard` | Guided end-to-end SDD walkthrough |
| `sdd-propose` | When the orchestrator creates a change proposal |
| `sdd-spec` | When the orchestrator writes specifications for a change |
| `sdd-tasks` | When the orchestrator creates the task breakdown for a change |
| `sdd-verify` | When the orchestrator verifies a completed change |
| `skill-creator` | When creating a new agent skill |
| `skill-registry` | When updating the skill registry |

## Project Conventions

| File | Purpose |
|------|---------|
| `.cursorrules` | WordPress/PHP coding principles: OOP, hooks, sanitization, nonces, wp-env |
| `.phpcs.xml.dist` | WPCS 3.x ruleset — WordPress-Core, WordPress-Docs, WordPress-Extra, PHPCompatibilityWP; testVersion 7.4; text_domain=mvb |
| `README.md` | Plugin overview, SCF field schema, Block Bindings API contract |

## Compact Rules

### PHP/WordPress (applies to all .php files)

- Follow WordPress Coding Standards (WPCS): snake_case functions, `class-*.php` filenames, tab indentation
- Sanitize ALL inputs with `sanitize_*()` functions; escape ALL outputs with `esc_*()` / `wp_kses_post()`
- Verify nonces on every form/AJAX action; use `check_admin_referer()` or `wp_verify_nonce()`
- Use `wpdb->prepare()` for ALL custom SQL queries — no raw interpolation
- Enqueue assets via `wp_enqueue_script()` / `wp_enqueue_style()` — never print inline scripts
- Register hooks in class constructors; use `add_action`/`add_filter` exclusively for extension points
- Text domain is `mvb` — use `__()`, `esc_html__()`, etc. for all user-facing strings
- Short array syntax `[]` is allowed (WPCS override in .phpcs.xml.dist)
- PHPCompatibility target: 7.4+ (use typed properties, arrow functions freely; avoid 8.0+ syntax)

### Block Bindings (applies to class-mvb-block-bindings.php and related)

- Source identifier: `mvb/videogame` — keep stable, it's part of the public API
- Supported keys: `videogame_completion_date`, `videogame_release_date`, `hltb_main_story`, `igdb_id`
- Return value from `get_value` callback MUST be a scalar or null — never an array/object

### SCF / Meta (applies to scf-config.json and meta-reading code)

- Meta keys: `videogame_cover`, `videogame_status`, `videogame_release_date`
- Custom post type: `videogame`; taxonomies: `company`, `release-platform`
- Always use `get_post_meta()` with single=true; sanitize on write, escape on output

### Linting

- Run `composer run lint` to check; `composer run fix` to auto-fix
- No test runner configured — manual WP testing only
