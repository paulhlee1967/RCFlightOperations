# Local development (Mac)

Use **Homebrew** to run the app on your Mac with a local database. No Docker. The cPanel frameworks (Laravel, CodeIgniter, etc.) are unrelated—this app is plain PHP + MySQL/MariaDB and runs on cPanel without any of those.

---

## What you’ll have when done

- **PHP** (8.x; you may already have it) and **MariaDB** (or MySQL) installed via Homebrew
- A **local database** with the same tables as production (from [schema_full.sql](schema_full.sql))
- The app running at **http://localhost:8000** (PHP built-in server)
- A **local `config.php`** pointing at the local DB; use a separate production `config.php` on cPanel when you deploy

---

## Step 1: Install Homebrew (if needed)

If you don’t have Homebrew:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

Follow the “Next steps” it prints (adding `brew` to your PATH). Then:

```bash
brew update
```

---

## Step 2: Install PHP and MariaDB

- **PHP:** If you already have PHP 8.x (e.g. `php -v` shows 8.5+), skip this. Otherwise: `brew install php`
- **MariaDB:** Use MariaDB to match typical cPanel production. Install and start:

```bash
brew install mariadb
brew services start mariadb
```

After install, confirm:

```bash
php -v
mariadb --version
```

---

## Step 3: Create the database and load the schema

Connect as root. On Homebrew MariaDB, root often uses socket auth, so use `sudo`:

```bash
sudo mariadb -u root
```

(Enter your Mac login password if prompted.)

In the MariaDB prompt:

```sql
CREATE DATABASE amac_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'amac'@'localhost' IDENTIFIED BY 'localdev';
GRANT ALL ON amac_local.* TO 'amac'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Load the schema (use your actual project path; **database name must come before the redirect**):

```bash
cd /path/to/RCFlightOperations
mariadb -u amac -plocaldev amac_local < schema_full.sql
```

No space between `-p` and the password (e.g. `-plocaldev`). Verify the database:

```bash
php scripts/verify_db.php
```

---

## Step 4: Configure the app for local

In the project folder:

1. Copy the example config: `cp config.php.example config.php`
2. Edit `config.php` with **local** settings:

```php
return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'amac_local',
        'user'     => 'amac',
        'password' => 'localdev',
        'charset'  => 'utf8mb4',
    ],
];
```

Keep this `config.php` only on your Mac (it’s in `.gitignore`). When you deploy to cPanel you’ll create a **new** `config.php` there with the cPanel database credentials.

---

## Step 5: Set the admin password locally

From the project directory:

```bash
php scripts/set_password.php
```

When prompted, enter a password (at least 8 characters). The seed user is `admin@yourclub.local`.

---

## Step 6: Run the app

From the project directory:

```bash
php -S localhost:8000
```

Then open in your browser: **http://localhost:8000**

- You should see a redirect to the login page.
- Log in with email `admin@yourclub.local` and the password you set in Step 5.
- You’ll land on the Members list (empty until you add members or import data).

To stop the server: `Ctrl+C` in the terminal.

---

## Day-to-day workflow

| Action | Command / step |
|--------|-----------------|
| Start MariaDB (if not already running) | `brew services start mariadb` |
| Start the app | `cd /path/to/RCFlightOperations && php -S localhost:8000` |
| Run unit tests | `composer test` (after `composer install`) |
| Refresh vendored UI assets | `bash scripts/fetch_vendor_assets.sh` |
| Stop the app | `Ctrl+C` in the terminal where the server is running |
| Stop MariaDB when done for the day (optional) | `brew services stop mariadb` |

You edit the PHP/HTML/CSS/JS files in your project as usual; the built-in server serves them. The app reads/writes to the local database.

---

## When you’re ready for cPanel

**Blank install:** Follow [START_HERE.md](START_HERE.md) (create DB, import [schema_full.sql](schema_full.sql), create config, set password).

**Copy your local database to cPanel:** [DEPLOY.md — Moving local data to cPanel](DEPLOY.md#moving-local-data-to-cpanel) (export with `php scripts/export_db_for_cpanel.php`, upload project without `config.php`, import SQL, create `config.php` on the server, `composer install` / `set_password.php` as needed).

Your Mac setup is only for development; production uses the cPanel database and credentials.

---

## Email and scheduled reminders (optional)

The app can send email (e.g. AMA/FAA expiry reminders) via config. In `config.php` you can add an `email` block (see `config.php.example`):

- **`driver` => `'mail'`** – Use PHP `mail()` (no SMTP). Works on many hosts with no extra setup.
- **`driver` => `'smtp'`** – Use SMTP (e.g. Brevo, cPanel “Email Deliverability”). Set `email.smtp` with host, port, username, password.

Templates live in **`templates/email/`** (e.g. `ama_expiry_60.php`, `ama_expiry_30.php`, `faa_expiry_60.php`). You can add or edit these.

To send reminder emails on a schedule, run from cron (e.g. daily):

```bash
php /path/to/RCFlightOperations/scripts/send_reminders.php
```

Use `--dry-run` to see who would get an email without sending.
