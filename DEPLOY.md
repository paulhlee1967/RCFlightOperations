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
   If `vendor/` wasn’t uploaded: `composer install` (needed for PDF export and email).

5. **HTTPS**  
   Use HTTPS in production. Sessions and passwords should not be sent over plain HTTP.

6. **`js/` static file**  
   Deploy `js/flightops_ui.js` with the app (confirm dialogs, colour pickers, etc.). It is loaded from `includes/footer.php`.

7. **Writable `uploads/`**  
   Ensure the web server can write to `uploads/` (and `uploads/member_photos/` if used). Required for member photos, club logos, favicons.

8. **Uploads directory on Nginx**  
   `uploads/.htaccess` only affects Apache. On Nginx, block execution of PHP (and other scripts) under `uploads/` in your server config — e.g. `location ^~ /uploads/ { location ~ \.php$ { return 403; } }` — so uploaded files cannot be executed as code.

9. **`scripts/` on Nginx**  
   The repo includes `scripts/.htaccess` to deny web access on Apache/LiteSpeed. On Nginx, deny the whole path, e.g. `location ^~ /scripts/ { return 403; }` — these files are **CLI-only** (password setup, DB verify, cron, etc.).

10. **Reverse proxy / HTTPS**  
    If TLS terminates in front of PHP (load balancer, Cloudflare, etc.), set `'trust_forwarded_https' => true` in `config.php` so session cookies use the `Secure` flag and password-reset emails use `https://` links. Add `'trusted_proxies' => ['127.0.0.1']` (or your proxy’s IP/CIDR as seen by PHP in `REMOTE_ADDR`) so `X-Forwarded-Proto` is not applied for arbitrary clients. Only enable `trust_forwarded_https` when the edge sets forwarded headers correctly.

11. **Scheduled reminders (cron, optional)**  
   If you send reminder emails, configure a cron job to run:
   ```bash
   php /path/to/RCFlightOperations/scripts/send_reminders.php
   ```
   Use `--dry-run` to preview.

---

## Quick reference

- **Login URL:** `https://yourdomain.com/yourfolder/login.php` (or document root).
- **Installation (admin):** after logging in as admin, **Administration → Installation** — SMTP, maintenance mode, health, etc.
- **Do not commit:** `config.php`, `export_for_cpanel.sql`, `.env`, or uploaded files under `uploads/` (see [.gitignore](.gitignore)). Note: `uploads/.htaccess` is included for hardening.
