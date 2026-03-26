# Contributing to RC Flight Operations

- **Setup:** Follow [START_HERE.md](START_HERE.md) (or [LOCAL_DEV.md](LOCAL_DEV.md) on a Mac). Run `php scripts/verify_db.php` to confirm the database matches `schema_full.sql`.
- **Database:** All schema is in `schema_full.sql`. Do not add new migration scripts; change `schema_full.sql` and document any manual steps for existing installs if needed.
- **Code style:** Plain PHP, PDO, Bootstrap 5. Keep pages simple and server-rendered where possible.
- **Errors & UX:** Prefer one pattern per page type. For form pages that re-render on failure, set `$error` (or field errors) and show them inline. For actions that should not double-submit, use `flash()` plus `redirect` (POST-redirect-GET). Avoid mixing both on the same handler without a clear reason.
- **License:** By contributing, you agree that your contributions will be licensed under the same [MIT License](LICENSE) that covers this project.
