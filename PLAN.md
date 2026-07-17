# RC Flight Operations – Plan & architecture

LAMP app for club membership. **Source of truth:** `schema_full.sql` and the codebase. For file-level detail see [TECHNICAL.md](TECHNICAL.md); for release history see [CHANGELOG.md](CHANGELOG.md).

---

## What the app does

- **Single-club deployment:** One database per installation; branding and settings live in the **`club`** table (one row, typically `id = 1`).
- **Members:** CRUD with Contact (phone, mailing address, photo, emergency contact), Compliance (AMA/FAA, verify AMA), Membership (type slot, renewal year, gate key, life/free/inactive/suspended), Payment history, Sender.net email preference status when configured.
- **New member wizard:** Guided signup (contact → compliance → membership → first payment → print & mail).
- **Record signup/renewal:** On-time, late, or new prorated; configurable dues; complimentary option; optional free membership / life member flag. Staff ledger is for cash/check; online applicants pay via Stripe on `/apply.php`.
- **Online applications:** Public form at `/apply.php` (AMA gate, club-record prefill, Stripe, email opt-in, complimentary invites); staff review in **Applications** with status emails.
- **Member self-service:** Passwordless `/membership` magic-link profile for contact, AMA/FAA, uploads, and email prefs.
- **Badge design & print:** CR80 card designer (Fabric.js): front (background, text fields, photo) and back (HTML). Multiple named designs; print front and/or back as separate jobs.
- **Reports:** Built-in membership, retention, revenue, compliance, and related reports — on screen, CSV, branded PDF, or email. Monthly **board packet** (HTML/PDF) via Installation settings or cron.
- **Incidents:** Safety / field incident log with optional photo attachments.
- **Import/export:** CSV import for members; filter-aware CSV/PDF export.
- **Admin:** Users (roles: administrator, membership manager, club staff, report viewer), club config, complimentary invites, audit log, **Installation** (SMTP, Stripe, Sender, applications, board packet, maintenance, health).

---

## Data model (core)

- **club** – Single row: name, branding, membership type labels. Dues live in **`dues_rules`** (per slot).
- **users** – App logins: email, password_hash, name, role, active.
- **members** – Identity, contact, membership fields, AMA/FAA, flags, email opt-in columns.
- **payments** / **member_fulfillments** / **member_membership_years** – Ledger, per-year fulfillment, frozen year roster for reporting.
- **member_applications** / **membership_comp_invites** – Public apply queue and complimentary invites.
- **member_magic_links** – One-time tokens for member self-service.
- **badge_templates** – JSON canvas designs.
- **incidents** / **incident_photos** – Incident log and attachments.
- **board_packet_deliveries** – Board packet send log (plus related `system_config` keys).
- **rate_limit_events** – IP rate-limit counters for public/API endpoints.

---

## Tech stack

- PHP 8.2+, MySQL/MariaDB, Apache (or PHP built-in server for local). PDO.
- UI: HTML, Bootstrap 5 (vendored in `assets/vendor/`), server-rendered; Fabric.js for badge designer; no front-end framework.
- Composer: [dompdf](https://github.com/dompdf/dompdf) (PDF), [PHPMailer](https://github.com/PHPMailer/PHPMailer) (email), [stripe/stripe-php](https://github.com/stripe/stripe-php) (apply payments). Dev: PHPUnit (`composer test`).

---

## Schema changes for existing installs

Update `schema_full.sql`, add an idempotent `scripts/migrate_*.sql`, document it in [DEPLOY.md](DEPLOY.md), and extend `scripts/verify_db.php`. See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Suggested order for new contributors

1. Run through [START_HERE.md](START_HERE.md) to get a working install.
2. Use [LOCAL_DEV.md](LOCAL_DEV.md) for Mac development.
3. Run `php scripts/verify_db.php` to confirm database matches `schema_full.sql`.
4. Run `composer test` after `composer install` when changing PHP helpers.
