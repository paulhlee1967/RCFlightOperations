# RC Flight Operations – Plan & architecture

LAMP app for club membership. **Source of truth:** `schema_full.sql` and the codebase.

---

## What the app does

- **Single-club deployment:** One database per installation; branding and settings live in the **`club`** table (one row, typically `id = 1`).
- **Members:** CRUD with Contact (single phone, single mailing address, photo, emergency contact), Compliance (AMA/FAA, verify AMA), Membership (type slot, renewal year, gate key, life/free/inactive/suspended), Payment history.
- **Record signup/renewal:** On-time, late, or new prorated; configurable dues; complementary option; optional free membership / life member flag.
- **Badge design & print:** CR80 card designer (Fabric.js): front (background, text fields, photo) and back (HTML). Multiple named designs per club; per-design backgrounds. Print front and/or back as separate jobs; front/back orientation independent. Shared Fabric helpers in `js/badge_fabric.js`.
- **Reports:** Seven built-in reports — membership by year, retention & churn, type mix, not yet renewed, revenue, AMA/FAA compliance, and missing member data — built on per-year membership history (`member_membership_years`, `payments`, `member_fulfillments`). Export CSV/PDF; email members with an address on file or a snapshot to a board address.
- **Incidents:** Safety / field incident log (optional workflow for AMA or club records).
- **Import/export:** CSV import for members; CSV/PDF export.
- **Admin:** Users (roles: admin, editor, treasurer, viewer), club config (General, Design: logo, favicon, colors), audit log viewer, **Installation** (SMTP, maintenance, health).

---

## Data model

- **club** – Single row for the installation: `name`, `logo_path`, `favicon_path`, `color_*`, membership type labels, etc. Dues amounts live in **`dues_rules`** (per slot).
- **users** – App logins. `email` (unique), `password_hash`, `name`, `role`, `active`.
- **members** – One per person. Identity, contact (`phone`, `address_*`, emergency contact), `date_joined`, `membership_type_slot`, `membership_renewal_year`, `gate_key_number`, `badge_printed_at`, AMA/FAA, flags (inactive, suspended, life_member, free_membership).
- **payments** – paid_at, year, amount_dues, amount_initiation, amount_late_fee, comp. Erroneous rows are hard-deleted (the action is recorded in `audit_log`).
- **member_fulfillments** – One row per member per year: `renewal_type` (new/on_time/late/complementary), processed_at/by, card/mailer printed timestamps.
- **member_membership_years** – Frozen per-year roster: who was a current member each calendar year. Append-only history that survives renewal-year overwrites; the basis for accurate year-over-year reporting.
- **dues_rules** – Per membership type slot (1–4): `annual_dues`, `prorated_dues`, `initiation_fee`, prorate window months. Configured under Administration → Configuration → **Membership & Dues**.
- **badge_templates** – JSON (canvas, background, orientation, backOrientation, backHtml).
- **incidents** – Date, location, type, severity, status, optional linked member, description, AMA reporting fields.

---

## Tech stack

- PHP 8.x, MySQL/MariaDB, Apache (or PHP built-in server for local). PDO.
- UI: HTML, Bootstrap 5 (vendored in `assets/vendor/`), server-rendered; Fabric.js for badge designer; no front-end framework.
- Composer: [dompdf](https://github.com/dompdf/dompdf) (PDF export), [PHPMailer](https://github.com/PHPMailer/PHPMailer) (SMTP / club and report email). Dev: PHPUnit (`composer test`).

---

## Suggested order for new contributors

1. Run through [START_HERE.md](START_HERE.md) to get a working install.
2. Use [LOCAL_DEV.md](LOCAL_DEV.md) for Mac development.
3. Run `php scripts/verify_db.php` to confirm database matches `schema_full.sql`.
4. Run `composer test` after `composer install` when changing PHP helpers.
