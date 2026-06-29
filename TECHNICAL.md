# RC Flight Operations — Technical Documentation

This document describes how the application is built and how its parts work together. It is for **developers and maintainers**. End-user help lives in the in-app **Docs** (the `docs/` folder, linked from the header as “Help & Documentation”).

---

## Architecture overview

- **Stack:** PHP 8.x, MySQL/MariaDB, no application framework. Bootstrap 5 for the UI; Composer for dependencies (Dompdf, PHPMailer).
- **Single-club deployment:** One installation serves **one** club. Branding, dues, and settings live in the `club` table (typically `id = 1`).
- **Typical page load:** `config.php` → `includes/db.php` → `includes/auth.php` (require login) → page logic → `includes/header.php` → content → `includes/footer.php`.

---

## Directory structure

| Path | Purpose |
|------|--------|
| **/** (project root) | Entry-point scripts (`index.php`, `login.php`, `members.php`, etc.), `config.php`, `schema_full.sql` |
| **includes/** | Shared PHP: DB, auth, helpers, layout, CSRF, validation, feature flags, mail |
| **docs/** | End-user documentation (HTML). Styled with `docs.css`; `docs-theme.php` injects club colours |
| **scripts/** | CLI maintenance scripts (set password, verify DB, export, reminders) |
| **js/** | `flightops_ui.js` — CSP-safe UI helpers (loaded deferred from `includes/footer.php`). |
| **templates/** | Email and letter templates (PHP/HTML) |
| **uploads/** | Member photos and club branding (written by the app; photos served via `badge_photo.php`, not direct URLs) |
| **vendor/** | Composer dependencies (Dompdf, PHPMailer, etc.) |
| `schema_full.sql` | Full, ready-to-import schema (initial + embedded upgrades) |

---

## Configuration

- **config.php** — Not in version control. Copy from `config.php.example`. Contains:
  - **db** — `host`, `name`, `user`, `password`, `charset` (optional, default `utf8mb4`)
  - **email** (optional) — `driver` (`smtp` or `mail`), `from_address`, `from_name`; if `smtp`, then `smtp` with `host`, `port`, `encryption`, `username`, `password`. Often supplemented or overridden by **Administration → Installation** (values stored in `system_config`).
  - **debug** (optional) — if true, database connection errors show details to the browser (development only; errors are always logged)
  - **trust_forwarded_https** (optional) — if true, treat `X-Forwarded-Proto: https` as HTTPS for session cookies and password-reset link URLs (use only behind a trusted reverse proxy)
  - **trusted_proxies** (optional) — when `trust_forwarded_https` is true, an array of IP addresses or IPv4 CIDRs; forwarded proto is honored only if `REMOTE_ADDR` matches (see `includes/trusted_proxy.php`). If omitted or empty, behavior matches a single trusted hop (weaker; set explicit IPs in production when possible)

Host-level settings (app name, maintenance mode, SMTP, health checks, admin broadcast) are managed in the main app at **Administration → Installation** (`installation.php`, admin login required), not a separate operator area.

---

## The `includes/` directory

Shared code used across the app. Include order matters: `db.php` before `auth.php`; `auth.php` (or session) before `csrf.php`.

| File | Purpose |
|------|--------|
| **db.php** | Loads `config.php`, creates PDO `$pdo`, bootstraps `helpers.php`, `session_ini.php` (session cookie defaults), and `features.php`. Defines `flightops_refresh_maintenance_mode_global()`; **`includes/auth.php` calls it after `session_start()`** so the maintenance banner sees the logged-in session. **Include first** on any page that needs the database. |
| **helpers.php** | Common utilities: `h()` (HTML escape), `checked()`, `selected()`, `formatMoney()`, `formatDate()`, `defaultRenewalYear(?PDO)` (uses `renewal_prebook_start_month` from `system_config` when `$pdo` passed), `memberStatusBadge()`. Required by `db.php` so they are available everywhere. |
| **flash.php** | One-time messages after redirects: `flash($message, $type)`. Messages stored in `$_SESSION['flash']`; `header.php` renders them as Bootstrap toasts and clears them. `getFlash()` returns one message for simple inline use. |
| **auth.php** | Session and permissions. `requireLogin()`, `requireAdmin()`, `currentUserId()`, `isAdmin()`, `canEditMembers()`, `canProcessMemberships()`, `canManagePayments()` (void mistaken payments: editors and treasurers). Uses `safe_redirect.php` for redirect URLs. Include **after** `db.php`. |
| **csrf.php** | CSRF token: `csrf_token()`, `csrf_field()`, `csrf_validate($options)` — use `csrf_validate(['json' => true])` for `fetch` JSON endpoints so errors stay machine-readable. |
| **safe_redirect.php** | `safe_redirect_url($candidate, $default)` — validates redirect URLs to prevent open redirects (only relative paths, no `://`, no `..`). |
| **session_ini.php** | `flightops_apply_session_cookie_params()`, `flightops_is_https_request($config)`, `flightops_request_scheme($config)` — session hardening before `session_start()`. |
| **trusted_proxy.php** | `flightops_should_trust_forwarded_proto($config)` — gate `X-Forwarded-Proto` on optional `trusted_proxies`. |
| **security_headers.php** | `flightops_send_security_headers()` — baseline headers and nonce-based CSP. |
| **csp_nonce.php** | `flightops_csp_nonce()`, `csp_nonce_attr()` — one nonce per response for inline script/style. |
| **cli_only_script.php** | `flightops_require_cli()` — exit if not PHP CLI (used by every file in `scripts/`). |
| **header.php** | Shared layout: HTML head, navbar (with club theme, active nav, user menu), breadcrumbs, flash toasts. Set `$pageTitle`, optional `$noNav`, optional `$breadcrumbs` before including. Loads theme from `club` (`id = 1`) and defines `navActive()`. Nav items use `function_exists('canEditMembers')` (etc.) so including `header.php` without `auth.php` fails quietly — always include `auth.php` on app pages. |
| **footer.php** | Closes container, RC Flight Operations attribution bar (uses theme CSS variables), Bootstrap JS, `flightops_ui.js`. |
| **validation.php** | Server-side validation: `validate_member_input()`, `validate_payment_input()`, `validate_email()`, `validate_date()`, `validate_positive_number()`. Return structured errors for forms and APIs. |
| **features.php** | Optional modules registry. `FEATURES` constant (e.g. `badge_designer`, `csv_import`, `ama_lookup`, `multi_user`). `featureEnabled($slug)`, `requireFeature($slug)`. |
| **audit_log.php** | `audit_log($pdo, $userId, $action, $targetType, $targetId, $detail)`. Writes to `audit_log` table; safe if table is missing. **Admin UI:** `audit_log_viewer.php` (Administration → Audit log). |
| **mail.php** | Email sending via config: `send_mail($to, $subject, $bodyHtml, $bodyText, $emailConfig)`. Uses PHPMailer; SMTP or PHP `mail()` depending on config. |
| **installation_config.php** | Loads/saves `system_config` keys; merges with `config.php` email for Installation screen. |
| **password_policy.php** | Password strength rules and validation. |
| **password_strength_ui.php** | Client-side UI for password strength (used on user edit, reset password). |
| **email_templates.php** | Helpers to load and render email templates from `templates/email/`. |
| **flightops_logo.php** | Default PNG badge (`assets/rc-flight-operations-logo.png`) when the club has no uploaded logo. |
| **run_report.php** | Report engine: `reportRegistry()`, `runReport($pdo, $slug, $params)`, per-report builders, and cohort/year helpers. Returns a uniform `{columns, rows, totals, note}` structure built on `member_membership_years` / `payments` / `member_fulfillments`. |
| **report_pdf.php** | Renders a report structure to a downloadable PDF via Dompdf. `reportPdfAvailable()` guards against a missing `vendor/`. |

---

## Main application entry points

| File | Purpose |
|------|--------|
| **index.php** | Dashboard: stat cards (members, not renewed, badges unprinted, AMA/FAA alerts, gate keys, revenue). Requires login. |
| **login.php** | Club user login. Session: `user_id`, `user_email`, `user_name`, `user_role`. Uses CSRF and safe redirect. |
| **logout.php** | Destroys session, redirects to login. |
| **forgot_password.php** / **reset_password.php** | Password reset flow (tokens in `password_reset_tokens`). |
| **installation.php** | Host/app settings: SMTP, maintenance mode, health, broadcast to admins. **Admin only.** |
| **members.php** | Member list: pagination, filters (status, type, search). Links to add, edit, renew, print badge, export. |
| **member_edit.php** | Add/edit member form (contact, compliance, membership tabs). POST handled in page; validation via `validation.php`. |
| **payment_add.php** | POST: add a payment row for a member (from Payment history tab). |
| **payment_delete.php** | POST: permanently delete an erroneous payment row. Admin, editor, or treasurer. The deletion is recorded in `audit_log` and the member's frozen membership-year roster is re-synced. |
| **member_detail.php** | Read-only member view (optional alternate to edit). |
| **member_process.php** | Renewal workflow: record payment, update `membership_renewal_year`, clear badge-printed flag. Uses `defaultRenewalYear()`, dues from `dues_rules`. |
| **member_delete.php** | Deletes member and related data (phones, addresses, payments); removes photo file from `uploads/`. |
| **users.php** | List app users (Admin only). |
| **user_edit.php** | Add/edit app user, role, active flag, password (Admin only). |
| **config_site.php** | Club configuration: General (name), Design (logo, favicon, colours), Dues rules. Admin only. Writes to `club` and `dues_rules`. |
| **reports.php** | Reports module: report picker, year selector, table render, CSV/PDF export, and email panels. Read access via `canViewReports()`. Report data comes from `includes/run_report.php`. |
| **report_email.php** | POST: email a report. `action=snapshot` sends the rendered table to one or more addresses; `action=members` emails a per-member message to a cohort report (e.g. not-yet-renewed) for members with `allow_email = 1`. The member blast requires editor/treasurer. |
| **incidents.php** / **incident_edit.php** / **incident_delete.php** | Safety / field incident log; editors add/edit, treasurer/viewer can read per nav rules. |
| **badge_design.php** | Badge template designer (Fabric.js). Loads/saves `badge_templates` JSON and back HTML. Editor or Admin. |
| **badge_print.php** | Print view for one member’s badge (front/back). Marks badge as printed. |
| **badge_photo.php** | Securely serves member photo from `uploads/` (no direct URL to uploads). |
| **import.php** | CSV import: upload, column mapping, preview, insert/update members (and optional payment rows). |
| **export.php** / **export_options.php** | CSV export (full, short, email-only); filters by year/renewal status. **`export.php` is POST + CSRF only** (forms in the UI and `export_options.php`). |
| **api_verify_ama.php** | AJAX endpoint: verifies AMA number against AMA lookup; returns JSON. |
| **member_mailer.php** | Sends member-facing emails (e.g. renewal letter). |
| **member_envelope.php** | Envelope/letter view for mailing. |
| **profile.php** | Current user profile (name, password change). |
| **audit_log_viewer.php** | Admin-only, paginated audit log. |

---

## Scripts (`scripts/`) — CLI

Run from project root: `php scripts/script_name.php`.

| Script | Purpose |
|--------|--------|
| **set_password.php** | Interactively set or reset a **club** user password (e.g. first admin from seed data). |
| **verify_db.php** | Checks that the live database schema matches expectations (e.g. after pulling updates). |
| **export_db_for_cpanel.php** | Exports SQL dump suitable for cPanel/phpMyAdmin import. |
| **send_reminders.php** | Sends reminder emails (AMA/FAA expiry). Cron-friendly. `--dry-run`, `--test-email=`. |
| **mark_expired_inactive.php** | Optional maintenance: mark members as inactive based on rules. |
| **import_member_photos.php** | Bulk import member photos (e.g. from a directory keyed by member ID or name). |

---

## Templates

- **templates/email/** — PHP templates for emails (e.g. AMA expiry reminder). Rendered via `email_templates.php` and sent with `mail.php`.
- **templates/letter/** — Renewal letter, new member letter, etc. Used for printable/mail-merge content.

---

## Database (high level)

- **schema_full.sql** — Full, ready-to-import schema. Main tables: `club`, `users`, `members`, `member_phones`, `member_addresses`, `payments`, `dues_rules`, `badge_templates`, `incidents`, `member_fulfillments`, `login_attempts`, `audit_log`, `password_reset_tokens`, `password_reset_ip_events`, `system_config`, `operator_messages`, etc.

- **`members` communication preferences:** `allow_email` (default 1) gates club email: CSV email export and `scripts/send_reminders.php` AMA/FAA notices (and the rebuilt reports' email features once they land). `allow_postal` (default 1) is stored for compliance with postal opt-out; `member_mailer.php` and `member_envelope.php` show a warning when it is 0.

There is a single logical club: queries use `club.id = 1` where a club row is needed.

---

## Email and PDF

- **Email:** Defaults in `config.php` under `email`; **Administration → Installation** can store SMTP and other keys in `system_config` (see `includes/installation_config.php` and `includes/mail.php`).
  - **Scheduled reminders:** `scripts/send_reminders.php` (cron) sends AMA/FAA expiry templates to members with `allow_email = 1`.
  - **CSV:** `export.php` format `email` skips members with `allow_email = 0`.
  - **Report email** (member-cohort and snapshot sends) will return with the rebuilt reports module; recipients must have `allow_email = 1` and a non-empty email.
- **PDF:** Dompdf (Composer) is available for PDF generation; the rebuilt reports module will use it for report export.

---

## Docs (`docs/`)

- **index.html** — Help center hub; links to overview, members, renewals, compliance, badges, incidents, import/export, administration, installation.
- **docs.css** — Styles for all doc pages.
- **docs-theme.php** — Served as CSS: outputs `:root` custom properties with the **logged-in club’s** colours (from `club`), so the docs match the app theme. If no session or DB, falls back to default RC Flight Operations palette.
- Other **.html** files — One per topic (overview, members, renewals, compliance, badges, incidents, import-export, admin, install, about). Static HTML; the theme stylesheet is served by `docs-theme.php`. The reports help page will return when the reports module is rebuilt.

---

## Security notes

- **CSRF:** All state-changing forms use `csrf_field()` and POST handlers call `csrf_validate()`.
- **Redirects:** Redirect targets from query params are validated with `safe_redirect_url()`.
- **Auth:** Club pages use `requireLogin()` / `requireAdmin()`. Passwords hashed with `password_hash()` (bcrypt).
- **Member photos:** Served through `badge_photo.php` with member checks; `uploads/` is not directly exposed.

---

## Quick reference: “Where do I find…?”

| If you want to… | Look at… |
|------------------|----------|
| Change what appears after login | `index.php` (dashboard) |
| Change nav or layout | `includes/header.php`, `includes/footer.php` |
| Add a global helper | `includes/helpers.php` |
| Add a flash message before redirect | `flash()` in `includes/flash.php` |
| Add or change a report | Reports module (being rebuilt — see the report-module plan) |
| Change badge layout / data | `badge_design.php`, `badge_print.php`, `badge_templates` table |
| Change dues or proration logic | `dues_rules` table, `member_process.php`, `config_site.php` |
| Toggle optional modules (registry) | `includes/features.php` (`FEATURES`) |
| Change email content | `templates/email/`, `includes/email_templates.php` |
| Change SMTP / installation behaviour | `installation.php`, `system_config`, `config.php` → `email`, `includes/mail.php` |
| Run one-off or scheduled tasks | `scripts/` |
