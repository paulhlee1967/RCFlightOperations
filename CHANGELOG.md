# Changelog

All notable changes to **RC Flight Operations** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Simplified member contact model** — Single `phone` and single mailing address on `members`; dropped `member_phones`, `member_addresses`, `allow_email`, and `allow_postal`. Idempotent migrations in `schema_full.sql` and `scripts/migrate_single_*.sql` / `migrate_drop_comm_prefs.sql`. Email cohorts and reminders use a non-empty email only; postal/email opt-out is expected in external tools (e.g. Sender.net).
- **WPForms field map** — Application webhook maps form 6569 labels to flat member columns (`phone`, `address_street`, etc.); documented Automator JSON in [WPFORMS_INTEGRATION.md](WPFORMS_INTEGRATION.md).
- **Documentation** — Help center, [TECHNICAL.md](TECHNICAL.md), and [PLAN.md](PLAN.md) updated for simplified contact fields and email behavior.

### Added

- **Sender.net reminder opt-out** — `scripts/send_reminders.php` checks each recipient’s promotional email status via the Sender.net API (`includes/sender_net.php`) before sending AMA/FAA expiry reminders. Skips unsubscribed, bounced, or spam-reported contacts; fails closed on API errors. **Administration → Installation** stores the API token and unsubscribe URL; reminder templates include a compliant footer and `List-Unsubscribe` header when configured.
- **New member wizard** — Guided five-step signup: `member_wizard.php` (contact, compliance, membership), then `member_process.php?wizard=1` (record signup, print & mail). Stepper in `includes/member_wizard_nav.php`; step JS in `js/member_wizard.js`. Shared save logic in `includes/member_save.php`. New members no longer open a blank `member_edit.php` form.
- **Shared club theme** — `includes/club_theme.php` centralizes default palette, WCAG on-primary text, and status color tokens. Used by `includes/header.php`, `docs/docs-theme.php`, and branded email/PDF layouts.
- **Badge designer overhaul** — Tabbed sidebar (card options, add fields, selected field, live preview), undo/redo, fixed-width text boxes for alignment, emergency-contact merge fields, back-side merge-tag buttons with live HTML preview, design rename, and browser session backup for unsaved edits.

### Changed

- **PHP 8.2 minimum** — `composer.json` and CI (PHPUnit 11 requires PHP ≥ 8.2). GitHub Actions matrix updated from 8.1/8.4 to 8.2/8.4.
- **Unified UI/UX** — Consistent sidebar tool panels, card layout, and club-themed CSS variables across dashboard, members, reports, badge designer, configuration, incidents, and help docs (`docs/docs.css`, `docs/docs-theme.php`).
- **Documentation** — `docs/badges.html`, `docs/members.html`, `docs/overview.html`, `docs/renewals.html`, `docs/index.html`, `docs/admin.html`, and `docs/install.html` updated for the wizard, badge designer, and theming.

## [1.5.1] - 2026-07-01

### Added

- **PHPUnit test suite** — `composer test` runs unit tests for dues calculation, membership SQL helpers, badge design/print/member-data helpers, members list query parsing, and AMA response parsing (`tests/`, `phpunit.xml`). GitHub Actions workflow [`.github/workflows/test.yml`](.github/workflows/test.yml) runs the suite on PHP 8.1 and 8.4.
- **AMA verify module** — Shared scraper in [includes/ama_verify.php](includes/ama_verify.php): cookie-jar session, `form_build_id` cache, Drupal AJAX JSON parsing, retries, and distinct error statuses. Health probe: `php scripts/verify_ama_health.php`.

### Changed

- **Dues stored in `dues_rules` only** — Removed legacy `club.dues_adult_*` / `dues_reduced` columns. Each membership type slot (1–4) has its own row in `dues_rules`; fresh installs are seeded with default rates. Existing databases: idempotent migration in [schema_full.sql](schema_full.sql) backfills missing `dues_rules` rows from legacy columns, then drops them.
- **Front-end assets vendored locally** — Bootstrap 5.3.8, Bootstrap Icons 1.11.3, and Fabric.js 7.4.0 ship in `assets/vendor/` (no jsDelivr/Google Fonts at runtime). Refresh with `bash scripts/fetch_vendor_assets.sh`. CSP tightened to `'self'` for scripts, styles, and fonts.
- **Dropped unused board-member columns** — Removed `members.is_board_member` and `badge_templates.is_board_default` (UI removed in 1.5). Idempotent `DROP COLUMN` migration at end of [schema_full.sql](schema_full.sql).
- **Documentation** — Configuration tab label aligned with UI: **Membership & Dues** (was “Dues Rules” in docs).
- **Badge designer split** — API handlers in [includes/badge_design_api.php](includes/badge_design_api.php), PHP helpers in [includes/badge_design_helpers.php](includes/badge_design_helpers.php), UI logic in [js/badge_design.js](js/badge_design.js); [badge_design.php](badge_design.php) is layout + config only.
- **Member list split** — Query/filter logic in [includes/members_list_query.php](includes/members_list_query.php), display helpers in [includes/members_list_helpers.php](includes/members_list_helpers.php), bulk-select and quick-view JS in [js/members_list.js](js/members_list.js); [members.php](members.php) is layout + config only.
- **Badge print split** — Print helpers in [includes/badge_print_helpers.php](includes/badge_print_helpers.php), shared member→badge field map in [includes/badge_member_data.php](includes/badge_member_data.php), Fabric print logic in [js/badge_print.js](js/badge_print.js); [badge_print.php](badge_print.php) is layout + config only. Badge designer preview API reuses `badge_member_data_from_row()`.

## [1.5] - 2026-07-01

### Added

- **Per-year membership history** — [schema_full.sql](schema_full.sql) `member_membership_years` frozen roster (who was a current member each calendar year), recorded on renewal/import/edit and used for accurate year-over-year counts. Helpers in [includes/membership_status.php](includes/membership_status.php); backfill via [scripts/backfill_membership_years.php](scripts/backfill_membership_years.php).
- **Reports module (rebuilt)** — New report engine [includes/run_report.php](includes/run_report.php) powering six reports on [reports.php](reports.php): membership by year, retention & churn, membership type mix, not yet renewed, revenue by year, and AMA/FAA compliance. Counts go through the per-year frozen roster so reports match the dashboard. Help page: [docs/reports.html](docs/reports.html).
- **Branded PDF export** — [includes/report_pdf.php](includes/report_pdf.php) renders reports as a club-branded PDF (logo, theme colors, page-numbered footer) via Dompdf, with a graceful fallback when Dompdf is unavailable.
- **Report email flows** — [report_email.php](report_email.php) emails a branded report snapshot to one or more addresses, and (for "not yet renewed") sends a personalised message to the cohort — only to members with `allow_email` on — via the shared [templates/email/email_layout.php](templates/email/email_layout.php) wrapper.
- **Cached logo thumbnails** — [includes/logo_thumb.php](includes/logo_thumb.php) produces a small, memory-safe raster of the club logo (Imagick → GD fallback) so high-resolution uploads no longer exhaust memory in PDFs/emails.
- **Data-accuracy notice** — Reports flag years before a configurable "complete data" year (`reports_accurate_from_year`, default 2027) on screen, in the PDF/email, and as a CSV footnote.
- **Configurable renewal pre-book day** — `renewal_prebook_start_day` (default 15) added alongside the start month, so the renewal year rolls forward on a specific date (e.g. October 15). Both settable under Administration → Installation.
- **Multiple badge designs** — Save, name, and switch between several CR80 templates; per-design background images; font family picker; Fabric 7 text alignment fixes. Shared Fabric helpers in [js/badge_fabric.js](js/badge_fabric.js).
- **`FLIGHT_OPS_VERSION`** — Defined in [includes/db.php](includes/db.php) (default `1.5`); shown in the app footer.

### Changed

- **Payments are now hard-deleted** — Replaced the soft "void" mechanism with [payment_delete.php](payment_delete.php): erroneous payments are removed outright, the action is recorded in `audit_log`, and the member's frozen membership-year roster is re-synced. Dropped the `voided_at` / `voided_by` columns from `payments` (guarded migration in [schema_full.sql](schema_full.sql)).
- **"Not yet renewed" follows the renewal season** — [includes/run_report.php](includes/run_report.php) and the dashboard ([index.php](index.php)) target the working renewal year (rolls to next year on the configured pre-book date) using the same snapshot-aware filter, so the report and the dashboard card always agree.
- **Branded emails use the cached logo** — [templates/email/email_layout.php](templates/email/email_layout.php) now embeds the cached logo thumbnail and accepts `eyebrow` / `footer_note` overrides so non-member emails (report snapshots) read correctly.
- **Badge code streamlined** — Fabric.js compatibility helpers extracted from [badge_design.php](badge_design.php) and [badge_print.php](badge_print.php) into [js/badge_fabric.js](js/badge_fabric.js).
- **Documentation** — [docs/badges.html](docs/badges.html), [docs/reports.html](docs/reports.html), and related pages updated for v1.5 (multi-design badges, rebuilt reports).

### Removed

- **Legacy reports engine** — `includes/report_helpers.php` and `templates/email/report_list.php` removed; superseded by the rebuilt report engine, PDF, and email flows above.
- **Board member tracking in the app** — Removed the member checkbox and badge auto-selection UI. Orphaned database columns dropped in a later schema migration.
- **Legacy `pics/` photo directory** — Member photos use `uploads/` only; bulk import via [scripts/import_member_photos.php](scripts/import_member_photos.php).

## [1.0.2] - 2026-03-25

### Removed

- **`system_operator/`** — Deprecated redirect stubs, operator-only docs, and CSS. Host-level settings (SMTP, maintenance, health) use **Administration → Installation** in the main app (`installation.php`).
- **`includes/operator_auth.php`** and **`scripts/set_operator_password.php`** — unused after single-club adoption.
- **`tenantHasFeature()`** in [includes/features.php](includes/features.php) — use `featureEnabled()` only.
- **`MIGRATE_TO_CPANEL.md`** — removed; cPanel data-move steps live in [DEPLOY.md](DEPLOY.md) under **Moving local data to cPanel**.
- **`scripts/migrate_login_attempts.php`** — removed; fresh installs get `login_attempts` from [schema_full.sql](schema_full.sql).

### Changed

- **Documentation** — [README.md](README.md), [TECHNICAL.md](TECHNICAL.md), [PLAN.md](PLAN.md), [START_HERE.md](START_HERE.md), [DEPLOY.md](DEPLOY.md), [LOCAL_DEV.md](LOCAL_DEV.md), and [docs/](docs/) HTML/CSS updated for **single-club** deployment; removed legacy `system_operator` references.
- **[config.php.example](config.php.example)** — Dropped legacy comments about an `operator` block.
- **[scripts/send_reminders.php](scripts/send_reminders.php)** — Removed unused `--tenant=` CLI flag.

## [1.0.1] - 2026-03-20

### Security

- **Member photo delete:** [member_delete.php](member_delete.php) only `unlink`s paths that resolve under `uploads/` (blocks `../` traversal).
- **Password reset schema:** [schema_full.sql](schema_full.sql) `password_reset_tokens` stores `token_hash`, `email`, `expires_at`, with index **`email_expires`**, aligned with [forgot_password.php](forgot_password.php) / [reset_password.php](reset_password.php).

### Fixed

- [export.php](export.php): **`filter=current`** always uses the calendar year before applying optional `year` POST (no ambiguous merge with a crafted year).
- [reports.php](reports.php): **`$emailReportError`** initialized so non-POST views do not hit undefined variable notices.
- [member_mailer.php](member_mailer.php): Letter templates get display labels from **`membership_type_slot`** + club membership type labels (removed invalid `membership_type` column reference).
- **CSV import:** Tiered **update-existing** matching (four fields → name+email → unique name only); [docs/import-export.html](docs/import-export.html) and preview checkbox text updated.

### Changed

- Removed the unused legacy **`system_operator_emails`** / **`super_admin_emails`** config hook and **`isSuperAdmin()`** / **`requireSuperAdmin()`** / **`isSystemOperator()`** helpers from [includes/auth.php](includes/auth.php). Host-level settings use **Administration → Installation** (admin login), not a separate operator account.
- [api_verify_ama.php](api_verify_ama.php): Documented session-based rate limit tradeoff.
- [member_process.php](member_process.php): Log + UI info when AMA **life** members carry a stale expiration date.
- [js/flightops_ui.js](js/flightops_ui.js): Color picker sync runs only when `.js-color-sync` exists.
- [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md): **PHPMailer** row; [robots.txt](robots.txt) comment; [TECHNICAL.md](TECHNICAL.md) `header.php` / `auth.php` note.

## [1.0.0] - 2026-03-20

### Added

- End-user documentation page for **Incidents** ([docs/incidents.html](docs/incidents.html)), linked from the docs hub.
- **`password_reset_ip_events`** table in [schema_full.sql](schema_full.sql) (password-reset IP rate limiting; previously created only at runtime).
- [CHANGELOG.md](CHANGELOG.md) for release tracking.

### Fixed

- **Maintenance mode banner:** `system_config.maintenance_mode` is now evaluated **after** `session_start()` in [includes/auth.php](includes/auth.php), so logged-in club users correctly see the banner when the operator enables maintenance.

### Changed

- Documentation aligned with **Administration → Installation**, **audit log viewer**, **report email** behavior, Composer dependencies, and schema/table listings ([README.md](README.md), [START_HERE.md](START_HERE.md), [TECHNICAL.md](TECHNICAL.md), [PLAN.md](PLAN.md)).
- [scripts/verify_db.php](scripts/verify_db.php) expects **`password_reset_ip_events`** and related tables from [schema_full.sql](schema_full.sql).

### Notes

- **Upgrading:** If `php scripts/verify_db.php` reports a missing `password_reset_ip_events` table on an existing database, create it with the `CREATE TABLE` block for that table in [schema_full.sql](schema_full.sql), or let the app create it the first time someone uses **Forgot password** (that flow still uses `CREATE TABLE IF NOT EXISTS`).
