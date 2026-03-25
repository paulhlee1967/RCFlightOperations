# Contributing to RC Flight Operations

- **Setup:** Follow [START_HERE.md](START_HERE.md) (or [LOCAL_DEV.md](LOCAL_DEV.md) on a Mac). Run `php scripts/verify_db.php` to confirm the database matches `schema_full.sql`.
- **Database:** All schema is in `schema_full.sql`. Do not add new migration scripts; change `schema_full.sql` and document any manual steps for existing installs if needed.
- **Code style:** Plain PHP, PDO, Bootstrap 5. Keep pages simple and server-rendered where possible.
- **License:** By contributing, you agree that your contributions will be licensed under the same [MIT License](LICENSE) that covers this project.
