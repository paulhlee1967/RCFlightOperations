# Deployment checklist – RC Flight Operations

Short reference for deploying RC Flight Operations (new install or copying a local database to a host). For full steps, see the linked docs.

---

## Which guide to use

| Scenario | Doc |
|----------|-----|
| **Fresh install** (blank DB on cPanel or local) | [START_HERE.md](START_HERE.md) |
| **Move local app + database to cPanel** | [Moving local data to cPanel](#moving-local-data-to-cpanel) (below) |
| **Local development (Mac)** | [LOCAL_DEV.md](LOCAL_DEV.md) |

---

## Moving local data to cPanel

Use this when you already have a working local database and want the same data on a shared host (e.g. for demos or staging).

1. **Export locally** — from the project root, with MySQL/MariaDB running and `config.php` pointing at your local DB:
   ```bash
   php scripts/export_db_for_cpanel.php
   ```
   This writes **`export_for_cpanel.sql`** in the project root (do not commit it; it is in `.gitignore`).

2. **Upload** the project to the server (e.g. `public_html/flightops/`). Include `uploads/` if you want photos and logos. **Do not** upload your local `config.php`.

3. **In cPanel** — create a MySQL database and user, add the user to the database with **All Privileges**. Note the full names (often prefixed with your cPanel username). Host is usually `localhost`.

4. **Import** `export_for_cpanel.sql` via phpMyAdmin (Import tab) or SSH:
   ```bash
   mysql -u youruser_dbuser -p youruser_dbname < export_for_cpanel.sql
   ```

5. **Create `config.php` on the server** — copy from `config.php.example` and set **only** the cPanel database credentials (and optional `email`). Do not copy your Mac `config.php`.

6. **Composer** — if you did not upload `vendor/`, run `composer install` on the server via SSH.

7. **Admin password** — if the imported user has no password yet:
   ```bash
   php scripts/set_password.php
   ```

8. Open `https://yourdomain.com/yourfolder/login.php` and sign in.

---

## Upgrading an existing production install

Use this when the app is **already live** and you are pulling a code update (e.g. `git pull` on `main`) that changes the database schema.

### 1. Back up first

- **Database:** cPanel → phpMyAdmin → Export, or SSH:
  ```bash
  php scripts/export_db_for_cpanel.php
  ```
- **Files:** keep a copy of `config.php` and `uploads/` (photos, logos).

### 2. Optional maintenance window

**Administration → Installation** → enable **Maintenance mode** so members do not hit half-upgraded pages while you run SQL.

### 3. Deploy the new PHP files

Upload or `git pull` the latest `main`. Do **not** overwrite your server `config.php`.

If `vendor/` is not on the server:

```bash
composer install --no-dev
```

### 4. Run database migrations (in order)

Each script is **idempotent** (safe to re-run). Use phpMyAdmin → SQL tab, or SSH:

```bash
cd /path/to/RCFlightOperations
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_single_phone.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_single_address.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_drop_comm_prefs.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_member_applications.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_email_opt_in.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_application_emails.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_board_packet.sql
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < scripts/migrate_incident_photos.sql
```

| Script | What it does |
|--------|----------------|
| `migrate_single_phone.sql` | Adds `members.phone`, copies one number per member from `member_phones` (Cell → Home → Work → Other), drops `member_phones` |
| `migrate_single_address.sql` | Adds `address_*` columns on `members`, copies Home address from `member_addresses`, drops `member_addresses` |
| `migrate_drop_comm_prefs.sql` | Drops `allow_email` and `allow_postal` (opt-out lives in Sender.net or similar) |
| `migrate_member_applications.sql` | Creates `member_applications` queue table |
| `migrate_email_opt_in.sql` | Adds `email_opt_in_club_events` and `email_opt_in_expiry_reminders` to `member_applications` and `members` |
| `migrate_application_emails.sql` | Adds applicant email delivery tracking and staff information-request history |
| `migrate_board_packet.sql` | Creates `board_packet_deliveries` log table and board packet `system_config` keys |
| `migrate_incident_photos.sql` | Creates `incident_photos` table for incident photo attachments |

**Note:** If a member had multiple phones or addresses, only the preferred one is kept (same rules as local dev). Extra rows in the old tables are discarded when those tables are dropped — back up before migrating if you need to audit them.

### 5. Verify

```bash
php scripts/verify_db.php
```

Expected output: `Database OK: all expected tables and columns present.`

### 6. Online applications (optional)

1. **Administration → Installation → Membership application (Stripe)** — publishable/secret keys, Stripe webhook secret, and **Application signing secret**.
2. Point Stripe `payment_intent.succeeded` webhooks at `https://your-domain/api_stripe_webhook.php`.
3. Link members to `https://your-domain/apply.php` from your club website.

### 7. Smoke test

- Open **Members** — edit a member; confirm single phone and mailing address fields.
- Open **Applications**; submit a test via `/apply.php` (try both email opt-in checkboxes) and approve it — badge photo and FAA card should copy to the member record; email preferences should appear in the detail panel.
- Spot-check a badge print / envelope (address still renders).
- Turn off maintenance mode.

---

## Post-deploy checklist

After uploading files and importing the database:

1. **Create `config.php`**  
   Copy from `config.php.example`. Set `db` (host, name, user, password). Optionally set `email` as a fallback; finer SMTP control is often set in **Administration → Installation** in the app (see [TECHNICAL.md](TECHNICAL.md#configuration)).

2. **Set club admin password**  
   ```bash
   php scripts/set_password.php
   ```  
   Seed user: `admin@yourclub.local`.

3. **Verify database**  
   ```bash
   php scripts/verify_db.php
   ```  
   Confirms schema matches expectations. Run after pulling code that changes `schema_full.sql`.

4. **Composer**  
   If `vendor/` wasn’t uploaded: `composer install` (needed for PDF export and email). Dev dependencies include PHPUnit (`composer test`).

5. **Front-end vendor assets**  
   Bootstrap, Bootstrap Icons, and Fabric.js are served from **`assets/vendor/`** (committed to the repo). If missing, run `bash scripts/fetch_vendor_assets.sh` from the project root.

6. **HTTPS**  
   Use HTTPS in production. Sessions and passwords should not be sent over plain HTTP.

7. **`js/` and `assets/vendor/`**  
   Deploy all files under `js/` (including `badge_design.js`, `badge_print.js`, `members_list.js`, `badge_fabric.js`, `flightops_ui.js`) and **`assets/vendor/`** (Bootstrap, Bootstrap Icons, Fabric). If `assets/vendor/` is missing, run `bash scripts/fetch_vendor_assets.sh`.

8. **Writable `uploads/`**  
   Ensure the web server can write to `uploads/` (and `uploads/member_photos/` if used). Required for member photos, club logos, favicons.

9. **Uploads directory on Nginx**  
   `uploads/.htaccess` only affects Apache. On Nginx, block execution of PHP (and other scripts) under `uploads/` in your server config — e.g. `location ^~ /uploads/ { location ~ \.php$ { return 403; } }` — so uploaded files cannot be executed as code.

10. **`scripts/` on Nginx**  
   The repo includes `scripts/.htaccess` to deny web access on Apache/LiteSpeed. On Nginx, deny the whole path, e.g. `location ^~ /scripts/ { return 403; }` — these files are **CLI-only** (password setup, DB verify, cron, etc.).

11. **Reverse proxy / HTTPS**  
    If TLS terminates in front of PHP (load balancer, Cloudflare, etc.), set `'trust_forwarded_https' => true` in `config.php` so session cookies use the `Secure` flag and password-reset emails use `https://` links. Add `'trusted_proxies' => ['127.0.0.1']` (or your proxy’s IP/CIDR as seen by PHP in `REMOTE_ADDR`) so `X-Forwarded-Proto` is not applied for arbitrary clients. Only enable `trust_forwarded_https` when the edge sets forwarded headers correctly.

12. **Canonical host (www vs apex)**
    The app should answer on a single hostname so session cookies and absolute URLs
    stay consistent (mismatched hosts cause repeated login prompts and broken
    redirects). `includes/canonical_host.php` enforces this in PHP on any server,
    defaulting to stripping a leading `www.` (apex wins); override with
    `'canonical_host' => 'rcflightops.example.com'` in `config.php`. The root
    `.htaccess` adds the same www→apex redirect at the edge on Apache/LiteSpeed.
    On Nginx, add the redirect in your server config, e.g.
    `if ($host ~* ^www\.(.+)$) { return 301 $scheme://$1$request_uri; }`.

13. **Scheduled reminders (cron, optional)**  
   If you send reminder emails, configure a cron job to run:
   ```bash
   php /path/to/RCFlightOperations/scripts/send_reminders.php
   ```
   Use `--dry-run` to preview sends and opt-out skips. Use `--test-email=you@example.com` with optional `--test-limit=3` to sample templates. Use `--dump-sender-payload` to write the first Sender API body to `logs/sender_payload_dump.json` (token redacted).

   **Sender.net (recommended):** In **Administration → Installation**, set the Sender API token and **members group ID**. Set `canonical_host` (or `public_base_url`) in `config.php` so reminder emails include working logo and unsubscribe links. Reminders check **transactional** opt-out only — newsletter unsubscribes in Sender do not block reminders. Each reminder includes a signed link to `unsubscribe.php` for reminder-only opt-out.

14. **Monthly board packet (cron, optional)**
   After running `scripts/migrate_board_packet.sql`, enable automatic delivery in **Administration → Installation → Monthly board packet** (recipients, send day 1–28). Configure a **daily** cron job:
   ```bash
   php /path/to/RCFlightOperations/scripts/send_board_packet.php
   ```
   The script sends once per calendar month on the configured day. Use `--dry-run` to preview. Use `--test-email=you@example.com` to verify content without consuming the month's send slot. Use `--force` to bypass send-day and idempotency checks (still requires enabled + recipients unless testing).

---

## Quick reference

- **Login URL:** `https://yourdomain.com/yourfolder/login.php` (or document root).
- **Installation (admin):** after logging in as admin, **Administration → Installation** — SMTP, Sender.net reminder opt-out, maintenance mode, health, etc.
- **Do not commit:** `config.php`, `export_for_cpanel.sql`, `.env`, or uploaded files under `uploads/` (see [.gitignore](.gitignore)). Note: `uploads/.htaccess` is included for hardening.
