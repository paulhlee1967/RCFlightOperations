# Changelog

All notable changes to **RC Flight Operations** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
