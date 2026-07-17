# Security

- **Credentials:** Never commit `config.php` (it contains database credentials). It is listed in `.gitignore`.
- **Passwords:** Admin password is set via `php scripts/set_password.php` (CLI only; or manually in the database). User passwords are hashed with PHP’s `password_hash()` (bcrypt).
- **Sessions:** Login state is stored in PHP sessions with `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS, `use_strict_mode`, and `use_only_cookies` (see `includes/session_ini.php`). Use HTTPS in production. Behind a TLS-terminating reverse proxy, set `trust_forwarded_https` => `true` and preferably `trusted_proxies` => `[...]` in `config.php` so `X-Forwarded-Proto` is only honored when `REMOTE_ADDR` is your edge proxy ([OWASP](https://cheatsheetseries.owasp.org/) guidance: do not trust forwarded headers from untrusted clients).
- **`scripts/`:** Maintenance utilities are **CLI-only** (`flightops_require_cli()`). Apache/LiteSpeed should deny web access via `scripts/.htaccess`; on **Nginx**, deny the `/scripts/` location (see [DEPLOY.md](DEPLOY.md)). Nothing in `scripts/` is required for normal web requests (including `verify_db.php` — run it from the shell after deploy or upgrades).
- **HTTP headers:** Responses send baseline headers and a **nonce-based** Content-Security-Policy (see `includes/security_headers.php` and `includes/csp_nonce.php`). Scripts and styles load from `'self'` (app `js/`, `assets/vendor/`). Inline `<script>` and `<style>` blocks must include the per-request `nonce` attribute. **Inline event handlers are not used** — behaviors live in `js/*.js` (confirm dialogs, badge designer, member list quick-view, etc.). **`style-src-attr 'unsafe-inline'`** remains for `style=""` on elements (Bootstrap/utilities). On typical **cPanel + Apache** HTTPS, PHP sees `HTTPS=on` — you usually **do not** need `trust_forwarded_https` unless a proxy terminates TLS. Prefer **Strict-Transport-Security** at the server or CDN when you are fully on HTTPS.
- **Uploads:** `uploads/.htaccess` blocks PHP execution under uploads on Apache/LiteSpeed; configure an equivalent rule on Nginx ([DEPLOY.md](DEPLOY.md)). Member photos and FAA cards are served through app endpoints, not as arbitrary executables.
- **CSV export:** `export.php` accepts **POST** only with a valid CSRF token; use the app UI or `export_options.php`.
- **Rate limiting (DoS hardening):** Shared helpers in `includes/rate_limit.php` (table `rate_limit_events`, trusted-proxy-aware client IP). Notable limits:
  - Stripe webhook (`api_stripe_webhook.php`): 100/min per IP; payload size capped
  - Application submit (`api_membership_submit.php`): 5/hour per IP
  - Application quote (`api_membership_quote.php`): 30/15min per IP
  - AMA verify (public / member portal): separate per-endpoint caps
  - Member magic-link request (`/membership`): capped per IP
  - Login / password-reset: existing attempt lockouts
  - Presets also cover file upload, PDF export, and CSV import/export where those code paths apply
- **Reporting:** If you believe you’ve found a security issue, please report it privately (e.g. open a GitHub Security Advisory or contact the maintainers directly) rather than in a public issue.
