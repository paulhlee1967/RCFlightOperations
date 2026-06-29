# How to start – RC Flight Operations (LAMP)

You have a **blank database** (cPanel or local). Follow these steps in order.

**Developing on a Mac?** See [LOCAL_DEV.md](LOCAL_DEV.md) to run the app locally with Homebrew (PHP + MariaDB), then deploy to cPanel when ready.

**Moving a local database to cPanel?** See [DEPLOY.md — Moving local data to cPanel](DEPLOY.md#moving-local-data-to-cpanel).

---

## 1. Create database and user (cPanel)

1. Log in to **cPanel**.
2. Open **MySQL Databases** (or **MySQL® Database Wizard**).
3. Create a new database, e.g. `youruser_amac` (cPanel usually prefixes the username).
4. Create a new user and set a strong password. Note it down (e.g. `youruser_amacuser`).
5. **Add the user to the database** and grant **All Privileges**.
6. Note the full names cPanel shows (e.g. `youruser_amac`, `youruser_amacuser`). The host is usually `localhost`.

---

## 2. Run the schema (SSH or phpMyAdmin)

### Option A: Using SSH

1. SSH into your host.
2. Upload or clone this project into a folder under your account (e.g. `~/amac` or inside `public_html/amac`).
3. From the project directory, run:

   ```bash
   mysql -u youruser_amacuser -p youruser_amac < schema_full.sql
   ```

   Enter the database password when prompted. That creates all tables and inserts the seed **club** row + admin user (with no password set yet).

### Option B: Using phpMyAdmin

1. In cPanel, open **phpMyAdmin**.
2. Select your database in the left sidebar.
3. Go to the **Import** tab.
4. Choose the `schema_full.sql` file from this project and click **Go**.
5. Confirm that tables were created and you see one row in `club` and one in `users`.

---

## 3. Set the admin password

The seed user has email `admin@yourclub.local` and an empty password. Set a real password once.

**From SSH (recommended):**

```bash
cd /path/to/RCFlightOperations
php scripts/set_password.php
```

When prompted, type the new password (at least 8 characters). The script updates the `users` table.

**If you can’t use CLI:** Temporarily add a small web script that accepts a POST with `password` and runs the same update (then delete it after use), or set the hash manually in phpMyAdmin with:

```sql
UPDATE users SET password_hash = '<hash>' WHERE email = 'admin@yourclub.local';
```

To generate `<hash>`, run in PHP: `echo password_hash('YourPassword', PASSWORD_DEFAULT);`

---

## 4. Configure the app

1. Copy the example config:

   ```bash
   cp config.php.example config.php
   ```

2. Edit `config.php` and set your database credentials:

   - `host` – usually `localhost`
   - `name` – full DB name (e.g. `youruser_amac`)
   - `user` – full DB user (e.g. `youruser_amacuser`)
   - `password` – the password you set for that user

3. `config.php` is in `.gitignore`; do not commit it.

---

## 5. Install Composer dependencies (optional but recommended)

If you use PDF export or want to match production:

```bash
composer install
```

---

## 6. Put the app in the web root (or a subfolder)

- **Subfolder (e.g. `public_html/amac`):** Upload or copy the project files into `public_html/amac`. Then open `https://yourdomain.com/amac/` or `https://yourdomain.com/amac/login.php`.
- **Document root:** To serve at `https://yourdomain.com/`, put the project in the directory cPanel uses as the document root (often `public_html`). Then open `https://yourdomain.com/login.php`.

Ensure the web server executes `.php` files (default on cPanel).

---

## 7. Log in

1. Open `login.php` in the browser (e.g. `https://yourdomain.com/amac/login.php`).
2. Email: `admin@yourclub.local`
3. Password: the one you set in step 3.

You should be redirected to **Home** (dashboard). From there you can open Members or Administration (Users, Configuration, Badge design).

---

## 8. Optional: change the admin email

1. Log in, then go to **Administration → Users**.
2. Click **Edit** on the admin user, change **Email (login)** to your address, and **Save**.
3. Use the new email next time you log in (password stays the same).

---

## 9. Optional: Installation settings (SMTP, maintenance, health)

After you log in as an **admin**, open **Administration → Installation** (`installation.php`) to configure outbound email (SMTP or fallback), maintenance mode, database health checks, test email, and broadcast messages to club admins. These settings are stored in the database (`system_config` and related tables), not in a separate “operator” login.

---

## File layout

| Path | Purpose |
|------|---------|
| `schema_full.sql` | Full CREATE TABLEs + seed data (fresh install) |
| `config.php.example` | Template for DB config; copy to `config.php` |
| `config.php` | Your DB credentials (do not commit) |
| `includes/db.php` | PDO connection |
| `includes/auth.php` | Login helpers, roles, permissions |
| `includes/header.php` | Layout, nav, theme CSS |
| `includes/footer.php` | Closing layout |
| `login.php` | Login form and session |
| `logout.php` | Clear session, redirect to login |
| `index.php` | Home / dashboard |
| `members.php` | Member list |
| `member_edit.php` | Add/edit member (Contact, Compliance, Membership, Payment History) |
| `member_delete.php` | Delete member |
| `badge_design.php` | Badge template designer (Administration) |
| `badge_print.php` | Print member card (front/back) |
| `badge_photo.php` | Serves member photo for badge |
| `incidents.php` | Safety / field incident log |
| `import.php` / `export.php` | CSV import/export |
| `users.php` | System users (admin only) |
| `user_edit.php` | Edit user: email, name, role, password (admin only) |
| `config_site.php` | Club config: General, Design (admin only) |
| `audit_log_viewer.php` | Audit log (admin only) |
| `api_verify_ama.php` | AMA verification (Compliance tab) |
| `scripts/set_password.php` | One-time admin password set (CLI) |
| `scripts/verify_db.php` | Check database matches schema (CLI) |
| `installation.php` | Installation / SMTP / maintenance (admin only) |
| `LOCAL_DEV.md` | Local development on Mac |
| `PLAN.md` | Architecture and plan |
