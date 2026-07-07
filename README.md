# RC Flight Operations

**Version 1.5.2** — See [CHANGELOG.md](CHANGELOG.md).

Open-source (**MIT**) membership management for AMA-affiliated RC flying clubs. One installation serves **one club** (single database, single `config.php`). Officers get a shared dashboard, role-based logins, and club branding (logo, favicon, colors) that carries through the app and help docs.

## Features

- **Members** — Searchable roster, CSV import/export, photos, emergency contacts
- **New member wizard** — Guided five-step signup: contact → compliance → membership → record first payment → print & mail
- **Renewals & dues** — Record cash/check payments (the app is a ledger, not a payment processor); prorated, late, and complimentary renewals
- **AMA & FAA compliance** — Track numbers and expirations; live AMA lookup before renewal
- **Badge designer & printing** — Multiple CR80 templates, Fabric.js canvas editor, live member preview, undo/redo, personalized print at renewal
- **Reports** — Membership, retention, revenue, compliance, and more — on screen, CSV, branded PDF, or email
- **Incident log** — Optional dated safety/field incident records
- **Website applications** — WPForms submissions via webhook; review queue; badge photo copied to member on approve ([WPFORMS_INTEGRATION.md](WPFORMS_INTEGRATION.md), [docs/applications.html](docs/applications.html))
- **Administration** — Users & roles (administrator, membership manager, club staff, report viewer), club configuration, audit log

End-user help lives in **[docs/](docs/)** (also linked from the app as **Help & Documentation**).

## Getting started

- **New to the project?** → [START_HERE.md](START_HERE.md) — database, config, first login
- **Local development on a Mac?** → [LOCAL_DEV.md](LOCAL_DEV.md)
- **Deploying to a server?** → [DEPLOY.md](DEPLOY.md) — checklist, cPanel data move, Nginx notes. Apache: `uploads/.htaccess` blocks PHP in uploads; **Nginx** needs an equivalent rule in the server config.
- **WPForms / website applications** → [WPFORMS_INTEGRATION.md](WPFORMS_INTEGRATION.md) — Uncanny Automator webhook setup
- **Architecture and plan** → [PLAN.md](PLAN.md)
- **How the app is built** → [TECHNICAL.md](TECHNICAL.md) — file layout, `includes/`, entry points, scripts

## Tech stack

PHP 8.x, MySQL/MariaDB, Bootstrap 5 (vendored under `assets/vendor/`). No application framework. Composer: **dompdf** (PDF export), **PHPMailer** (email), **PHPUnit** (dev tests — `composer test`).

## License

MIT. See [LICENSE](LICENSE). Third-party licenses in [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md).
