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
| **includes/** | Shared PHP: DB, auth, helpers, layout, CSRF, validation, mail |
| **docs/** | End-user documentation (HTML). Styled with `docs.css`; `docs-theme.php` injects club colors |
| **scripts/** | CLI maintenance scripts (set password, verify DB, export, reminders) |
| **assets/** | Club logo (`rc-flight-operations-logo.png`) and **vendored front-end** libraries under `assets/vendor/` (Bootstrap, Bootstrap Icons, Fabric.js). URLs via `includes/vendor_assets.php`. Refresh with `scripts/fetch_vendor_assets.sh`. |
| **js/** | `flightops_ui.js` (global UI helpers, deferred from footer). `badge_fabric.js` — shared Fabric.js helpers. `badge_design.js`, `badge_print.js`, `members_list.js`, `member_wizard.js` — page-specific logic extracted from large PHP entry points. |
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
| **db.php** | Loads `config.php`, defines `FLIGHT_OPS_VERSION` and `FLIGHT_OPS_COPYRIGHT_YEAR_START`, creates PDO `$pdo`, bootstraps `helpers.php`, `session_ini.php` (session cookie defaults). Defines `flightops_refresh_maintenance_mode_global()`; **`includes/auth.php` calls it after `session_start()`** so the maintenance banner sees the logged-in session. **Include first** on any page that needs the database. |
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
| **header.php** | Shared layout: HTML head, navbar (with club theme, active nav, user menu), breadcrumbs, flash toasts. Set `$pageTitle`, optional `$noNav`, optional `$breadcrumbs` before including. Loads theme from `club` (`id = 1`) via `club_theme.php` and defines `navActive()`. Nav items use `function_exists('canEditMembers')` (etc.) so including `header.php` without `auth.php` fails quietly — always include `auth.php` on app pages. |
| **club_theme.php** | Shared club color defaults (`flightops_club_theme_defaults()`), hex→RGB, on-primary contrast (`flightops_on_primary_for()`), and harmonized status tokens. Used by `header.php`, `docs-theme.php`, and email/PDF layouts. |
| **footer.php** | Closes container, RC Flight Operations attribution bar (uses theme CSS variables), Bootstrap JS, `flightops_ui.js`. |
| **validation.php** | Server-side validation: `validate_member_input()`, `validate_payment_input()`, `validate_email()`, `validate_date()`, `validate_positive_number()`. Return structured errors for forms and APIs. |
| **audit_log.php** | `audit_log($pdo, $userId, $action, $targetType, $targetId, $detail)`. Writes to `audit_log` table; safe if table is missing. **Admin UI:** `audit_log_viewer.php` (Administration → Audit log). |
| **mail.php** | Email sending via config: `send_mail($to, $subject, $bodyHtml, $bodyText, $emailConfig, $options)`. Uses PHPMailer; SMTP or PHP `mail()`. Optional `list_unsubscribe_url` in `$options`. |
| **sender_net.php** | Sender.net API: promotional opt-out check (`sender_net_may_email_recipient()`), unsubscribe URL builder. Used by `send_reminders.php`. |
| **installation_config.php** | Loads/saves `system_config` keys; merges with `config.php` email and Sender settings for Installation screen. |
| **password_policy.php** | Password strength rules and validation. |
| **password_strength_ui.php** | Client-side UI for password strength (used on user edit, reset password). |
| **email_templates.php** | Helpers to load and render email templates from `templates/email/`. |
| **flightops_logo.php** | Default PNG badge (`assets/rc-flight-operations-logo.png`) when the club has no uploaded logo. |
| **run_report.php** | Report engine: `reportRegistry()`, `runReport($pdo, $slug, $params)`, per-report builders, and cohort/year helpers. Returns a uniform `{columns, rows, totals, note}` structure built on `member_membership_years` / `payments` / `member_fulfillments`. |
| **report_pdf.php** | Renders a report structure to a downloadable PDF via Dompdf. `reportPdfAvailable()` guards against a missing `vendor/`. |
| **vendor_assets.php** | URL helpers for pinned assets in `assets/vendor/` (Bootstrap CSS/JS, Bootstrap Icons, Fabric.js). |
| **dues_helpers.php** | Membership type labels, `dues_rules` fetch, and renewal amount calculation (`calculateDues()`). |
| **membership_status.php** | Current-member SQL (`currentMemberWhereSql`), per-year roster helpers, status counts for lists and reports. |
| **ama_verify.php** | AMA number lookup scraper (cookie session, Drupal AJAX parsing, retries). Used by `api_verify_ama.php`. |
| **badge_design_helpers.php** | Badge designer paths, background file handling, design list helpers. |
| **badge_design_api.php** | JSON/AJAX handlers for `badge_design.php` (save, load, upload, member preview). |
| **badge_member_data.php** | Shared member→badge field map (`badge_member_data_from_row()`), CR80 dimensions, member+address SQL. |
| **badge_print_helpers.php** | Design selection, mark-printed POST, member load for `badge_print.php`. |
| **members_list_query.php** | Filter/pagination query builder for `members.php`. |
| **members_list_helpers.php** | URL builder and list display badges (type, year, initials color). |
| **member_save.php** | Shared member create/update from POST (`member_edit.php`, `member_wizard.php`). |
| **member_wizard_nav.php** | Wizard step definitions, stepper render, and URL helpers for wizard ↔ process handoff. |
| **member_wizard_styles.php** | Inline CSS for wizard stepper (included by `member_wizard.php` and `member_process.php?wizard=1`). |
| **member_match.php** | Duplicate member detection (AMA + tiered name/email/birthday). Used by CSV import and WPForms applications. |
| **member_import_helpers.php** | Shared `parseDateForDb()`, `normalizeMembershipTypeSlot()`, `normalizeBool()` for import and WPForms. |
| **wpforms_application.php** | WPForms payload parsing, payment breakdown, list filters (renewal year, search, pagination), pending queue, approve/reject, email notification. |
| **application_webhook_config.php** | Loads `application_webhook_secret` from `system_config` or `config.php`. |

---

## Main application entry points

| File | Purpose |
|------|--------|
| **index.php** | Dashboard: stat cards (members, not renewed, badges unprinted, AMA/FAA alerts, gate keys, revenue). Requires login. |
| **login.php** | Club user login. Session: `user_id`, `user_email`, `user_name`, `user_role`. Uses CSRF and safe redirect. |
| **logout.php** | Destroys session, redirects to login. |
| **forgot_password.php** / **reset_password.php** | Password reset flow (tokens in `password_reset_tokens`). |
| **installation.php** | Host/app settings: SMTP, Sender.net (reminder opt-out), maintenance mode, health, broadcast to admins. **Admin only.** |
| **members.php** | Member list: pagination, filters (status, type, search). Links to new member wizard, edit, renew, print badge, export. |
| **member_wizard.php** | Guided new-member workflow (steps 1–3: contact, compliance, membership). POST saves via `member_save.php`, then redirects to `member_process.php?wizard=1`. Editor or Admin. |
| **member_edit.php** | Edit existing member (contact, compliance, membership tabs). New members are redirected to `member_wizard.php`. POST handled in page; validation via `validation.php`. |
| **payment_add.php** | POST: add a payment row for a member (from Payment history tab). |
| **payment_delete.php** | POST: permanently delete an erroneous payment row. Admin, editor, or treasurer. The deletion is recorded in `audit_log` and the member's frozen membership-year roster is re-synced. |
| **member_detail.php** | Read-only member view (optional alternate to edit). |
| **member_process.php** | Renewal workflow: record payment, update `membership_renewal_year`, clear badge-printed flag. With `?wizard=1`, continues the new-member wizard (steps 4–5: record signup, print & mail). Uses `defaultRenewalYear()`, dues from `dues_rules`. |
| **member_delete.php** | Deletes member and related data (payments, fulfillments, etc.); removes photo file from `uploads/`. |
| **users.php** | List app users (Admin only). |
| **user_edit.php** | Add/edit app user, role, active flag, password (Admin only). |
| **config_site.php** | Club configuration: General (name), Design (logo, favicon, colors), membership type labels, and **dues_rules** per slot. Admin only. |
| **reports.php** | Reports module: report picker, year selector, table render, CSV/PDF export, and email panels. Read access via `canViewReports()`. Report data comes from `includes/run_report.php`. |
| **report_email.php** | POST: email a report. `action=snapshot` sends the rendered table to one or more addresses; `action=members` emails a per-member message to a cohort report (e.g. not-yet-renewed) for members with a non-empty email. The member blast requires editor/treasurer. |
| **incidents.php** / **incident_edit.php** / **incident_delete.php** | Safety / field incident log; editors add/edit, treasurer/viewer can read per nav rules. |
| **badge_design.php** | Badge template designer page (layout + Fabric config). Tabbed sidebar, undo/redo, live member preview. JSON API in `includes/badge_design_api.php`; UI in `js/badge_design.js`. Editor or Admin. |
| **badge_print.php** | Print view for one member’s badge (front/back). Marks badge as printed. |
| **badge_photo.php** | Securely serves member photo from `uploads/` (no direct URL to uploads). |
| **import.php** | CSV import: upload, column mapping, preview, insert/update members (and optional payment rows). |
| **applications.php** | WPForms membership application review queue: status tabs, renewal-year filter (defaults to `defaultRenewalYear()`), search, pagination (50/page), detail/diff, payment breakdown, approve (upsert member → `member_process.php`), reject. |
| **export.php** / **export_options.php** | CSV export (full, short, email-only); filters by year/renewal status. **`export.php` is POST + CSRF only** (forms in the UI and `export_options.php`). |
| **api_verify_ama.php** | AJAX endpoint: verifies AMA number against AMA lookup via `includes/ama_verify.php`; returns JSON. |
| **api_webhook_application.php** | Machine-to-machine webhook: receives WPForms submissions (JSON + `X-Webhook-Secret`); stores pending applications. |
| **member_mailer.php** | Sends member-facing emails (e.g. renewal letter). |
| **member_envelope.php** | Envelope/letter view for mailing. |
| **profile.php** | Current user profile (name, password change). |
| **about.php** | App information: version (`FLIGHT_OPS_VERSION`), club name, role, license, links to docs. Linked from navbar Help → About. |
| **audit_log_viewer.php** | Admin-only, paginated audit log. |

---

## Scripts (`scripts/`) — CLI

Run from project root: `php scripts/script_name.php`.

| Script | Purpose |
|--------|--------|
| **set_password.php** | Interactively set or reset a **club** user password (e.g. first admin from seed data). |
| **verify_db.php** | Checks that the live database schema matches expectations (e.g. after pulling updates). |
| **verify_ama_health.php** | Probes AMA verify page for `form_build_id` (exit 0/1). Cron-friendly health check. |
| **fetch_vendor_assets.sh** | Downloads pinned Bootstrap, Bootstrap Icons, and Fabric.js into `assets/vendor/`. |
| **export_db_for_cpanel.php** | Exports SQL dump suitable for cPanel/phpMyAdmin import. |
| **send_reminders.php** | Sends reminder emails (AMA/FAA expiry). Cron-friendly. Checks Sender.net promotional opt-out when API token is set. `--dry-run`, `--test-email=`. |
| **mark_expired_inactive.php** | Optional maintenance: mark members as inactive based on rules. |
| **import_member_photos.php** | Bulk import member photos (e.g. from a directory keyed by member ID or name). |

**Tests:** After `composer install` (includes dev deps), run `composer test` for PHPUnit unit tests (`tests/`, `phpunit.xml`). CI runs the same suite on push/PR to `main` (PHP 8.2 and 8.4 via [`.github/workflows/test.yml`](.github/workflows/test.yml)). Production deploys can skip dev dependencies; tests cover pure PHP helpers without a live database.

---

## Templates

- **templates/email/** — PHP templates for emails (e.g. AMA expiry reminder). Rendered via `email_templates.php` and sent with `mail.php`.
- **templates/letter/** — Renewal letter, new member letter, etc. Used for printable/mail-merge content.

---

## Database (high level)

- **schema_full.sql** — Full, ready-to-import schema. Main tables: `club`, `users`, `members`, `payments`, `dues_rules`, `member_membership_years`, `member_applications`, `badge_templates`, `incidents`, `member_fulfillments`, `login_attempts`, `audit_log`, `password_reset_tokens`, `password_reset_ip_events`, `system_config`, `operator_messages`, etc.

- **`dues_rules`:** One row per membership type slot (1–4). Each enabled type (Adult, Youth, Spouse, etc.) has its own annual, prorated, and initiation amounts — slots are independent even when rates match.

- **`members` contact fields:** Single `phone`, single mailing address (`address_street`, `address_street2`, `address_city`, `address_state`, `address_postal_code`), and emergency contact columns on the member row. Legacy `member_phones` / `member_addresses` tables are migrated away by idempotent blocks in `schema_full.sql`.

There is a single logical club: queries use `club.id = 1` where a club row is needed.

---

## Email and PDF

- **Email:** Defaults in `config.php` under `email`; **Administration → Installation** can store SMTP and other keys in `system_config` (see `includes/installation_config.php` and `includes/mail.php`).
  - **Scheduled reminders:** `scripts/send_reminders.php` (cron) sends AMA/FAA expiry templates to members with a non-empty email. When a **Sender.net API token** is configured in Installation, each recipient is checked via `GET /v2/subscribers/{email}`; sends are skipped unless `status.email` is `active`. Reminder footers include an unsubscribe link when **Sender unsubscribe URL** is set (e.g. `https://stats.sender.net/unsubscribe/…`). Members are typically added to Sender.net via your website form (Uncanny Automator); SMTP delivery may use Sender’s transactional relay.
  - **CSV:** `export.php` format `email` includes members with a non-empty email (opt-out/unsubscribe is expected to be managed in your external mailing tool, e.g. Sender.net).
  - **Report email:** `report_email.php` — snapshot to arbitrary addresses; member cohort blasts require a non-empty email on file.
- **PDF:** Dompdf (Composer) powers report PDF export via `includes/report_pdf.php`. Run `composer install` on the server; `reportPdfAvailable()` guards when `vendor/` is missing.

---

## Docs (`docs/`)

- **index.html** — Help center hub; links to overview, members, renewals, applications, compliance, badges, reports, incidents, import/export, administration, installation.
- **docs.css** — Styles for all doc pages.
- **docs-theme.php** — Served as CSS: outputs `:root` custom properties with the **logged-in club’s** colors (from `club` via `club_theme.php`), including derived card/border/accent and Bootstrap link overrides, so the docs match the app theme. If no session or DB, falls back to default RC Flight Operations palette.
- Other **.html** files — One per topic. Footers use a consistent copyright line; version details live on **`about.php`** in the app (linked from Help → About). `docs/about.html` redirects readers there.

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
| Change nav or layout | `includes/header.php`, `includes/footer.php`, `includes/club_theme.php` |
| Add a new member / wizard flow | `member_wizard.php`, `includes/member_wizard_nav.php`, `includes/member_wizard_styles.php`, `js/member_wizard.js`, `includes/member_save.php`, `member_process.php` |
| Add a global helper | `includes/helpers.php` |
| Add a flash message before redirect | `flash()` in `includes/flash.php` |
| Add or change a report | `includes/run_report.php`, `reports.php`, `report_email.php`, `includes/report_pdf.php` |
| Change badge layout / data | `badge_design.php`, `includes/badge_design_api.php`, `includes/badge_design_helpers.php`, `includes/badge_member_data.php`, `badge_print.php`, `includes/badge_print_helpers.php`, `js/badge_fabric.js`, `js/badge_design.js`, `js/badge_print.js`, `badge_templates` table |
| Change member list filters / UI | `members.php`, `includes/members_list_query.php`, `includes/members_list_helpers.php`, `js/members_list.js` |
| Change website application review / webhook | `applications.php`, `api_webhook_application.php`, `includes/wpforms_application.php`, [WPFORMS_INTEGRATION.md](WPFORMS_INTEGRATION.md), [docs/applications.html](docs/applications.html) |
| Change dues or proration logic | `dues_rules` table, `member_process.php`, `config_site.php`, `includes/dues_helpers.php` |
| Change email content | `templates/email/`, `includes/email_templates.php` |
| Change SMTP / installation behaviour | `installation.php`, `system_config`, `config.php` → `email` / `sender`, `includes/mail.php`, `includes/sender_net.php` |
| Run one-off or scheduled tasks | `scripts/` |
