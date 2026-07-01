# RC Flight Operations

**Version 1.5** — See [CHANGELOG.md](CHANGELOG.md).

Open-source (**MIT**) LAMP membership app for RC clubs: members, contact info, AMA/FAA compliance, payments, badge design & printing, reports, and an optional incident log. **Single-club** deployment (one installation per club database); themeable (logo, favicon, colors). Home dashboard, system users with roles (admin, editor, treasurer, viewer), and club configuration.

- **New to the project?** → [START_HERE.md](START_HERE.md) for setup (database, config, first login).
- **Local development on a Mac?** → [LOCAL_DEV.md](LOCAL_DEV.md).
- **Deploying to a server?** → [DEPLOY.md](DEPLOY.md) (checklist, cPanel data move, Nginx notes). Apache: `uploads/.htaccess` blocks PHP in uploads; **Nginx** needs an equivalent rule in the server config.
- **Architecture and plan** → [PLAN.md](PLAN.md).
- **How the app is built** → [TECHNICAL.md](TECHNICAL.md) — file layout, includes (e.g. `flash.php`, `helpers.php`), entry points, and how things fit together.

**Tech:** PHP 8.x, MySQL/MariaDB, Bootstrap 5. No framework. Composer: **dompdf** (PDF export), **PHPMailer** (email).

**License:** MIT. See [LICENSE](LICENSE). Third-party licenses in [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md).
