# Contributing to RC Flight Operations

- **Setup:** Follow [START_HERE.md](START_HERE.md) (or [LOCAL_DEV.md](LOCAL_DEV.md) on a Mac). Run `php scripts/verify_db.php` to confirm the database matches `schema_full.sql`.
- **Tests:** After `composer install`, run `composer test` before opening a pull request (CI runs the same suite on PHP 8.2 and 8.4).
- **Database:** `schema_full.sql` is the source of truth for **fresh installs**. For schema changes that existing clubs must apply:
  1. Update `schema_full.sql` so new installs get the final shape.
  2. Add an idempotent `scripts/migrate_*.sql` (safe to re-run) and document it in [DEPLOY.md](DEPLOY.md).
  3. Extend `scripts/verify_db.php` when you add expected tables or columns.
- **Code style:** Plain PHP, PDO, Bootstrap 5. Keep pages simple and server-rendered where possible.
- **Errors & UX:** Prefer one pattern per page type. For form pages that re-render on failure, set `$error` (or field errors) and show them inline. For actions that should not double-submit, use `flash()` plus `redirect` (POST-redirect-GET). Avoid mixing both on the same handler without a clear reason.
- **License:** By contributing, you agree that your contributions will be licensed under the same [MIT License](LICENSE) that covers this project.
