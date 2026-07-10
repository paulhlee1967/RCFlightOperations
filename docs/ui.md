# UI conventions

Shared patterns for in-app pages (staff/admin UI). Public pages (`login.php`, `apply.php`) may differ.

## Page header

Use `render_page_header()` from `includes/page_header.php` after `includes/header.php`.

```php
render_page_header([
    'title'         => 'Members',
    'subtitle'      => 'Plain-text subtitle (escaped automatically)',
    'subtitle_html' => '<em>HTML subtitle</em> (caller must escape)',
    'actions'       => '<a class="btn btn-primary btn-sm">Action</a>', // trusted HTML
    'border'        => false, // true → bottom border (list/filter pages)
    'class'         => 'mb-3', // wrapper margin when border is false
]);
```

Build `actions` with `ob_start()` / `ob_get_clean()` when the markup is non-trivial.

**Migrated pages:** Members, Users, Import, Reports, Applications, Incidents, Configuration, Badge Designer, Audit log, User edit.

## Breadcrumbs

Set `$breadcrumbs` before including `header.php`:

```php
$breadcrumbs = [
    ['label' => 'Members', 'url' => 'members.php'],
    ['label' => 'Import', 'url' => ''],
];
```

The last crumb is usually the current page (`url` => `''`). Prefer breadcrumbs over inline “← Back to …” links on standard list/edit pages.

**Do not duplicate** breadcrumbs with a separate back button in the page body. Workflow print pages (`badge_print.php`, `member_envelope.php`) may keep contextual back links.

## Button hierarchy

| Role | Class | When |
|------|-------|------|
| Primary action | `btn btn-primary` (often `btn-sm` in toolbars) | Save, Submit, Log incident, Add user |
| Secondary / toolbar | `btn btn-outline-primary btn-sm` | Export CSV/PDF, Email, Import, Applications, Comp invites |
| Neutral / cancel | `btn btn-outline-secondary` | Cancel, Reset filters |
| Filter submit | `btn btn-outline-secondary btn-sm` | Filter bars on list pages |

Avoid solid `btn-secondary` in toolbars and filter bars — it reads as low-contrast on the club theme.

Destructive actions use `btn btn-outline-danger` or `btn btn-danger` with `data-confirm-submit` (see CSP-safe confirms in `js/flightops_ui.js`).

## Filter bars

List pages with search/filters use a `card shadow-sm mb-4` wrapping a `row g-2 align-items-end` form:

- Labels: `form-label small fw-semibold mb-1`
- Inputs: `form-control-sm` / `form-select-sm`
- Submit: `btn btn-outline-secondary btn-sm` labeled **Filter**
- Optional reset: `btn btn-outline-secondary btn-sm` **Reset**

Year or sort `<select>` elements that auto-submit on change use class `js-submit-on-change` (handled in `js/flightops_ui.js`).

## Tabs

Status or section tabs on club-themed pages use `nav nav-tabs nav-tabs-club` (defined in `includes/header.php`):

- Applications (Pending / Approved / …)
- Comp invites

Configuration uses default Bootstrap tabs inside the form (General / Design / Dues).

## Navbar dropdowns

Administration, Help, and User menus use `data-bs-theme="light"` on the dropdown menu so active/hover states use club primary colors with readable contrast.

## Flash messages

Global success/error toasts are rendered from session flash in `header.php`. Prefer those over duplicate inline alerts on list pages. Form pages may still show inline `alert` blocks for validation errors.

## Terminology

| Context | Label |
|---------|--------|
| Member list / quick-view / table action | **Process** |
| Workflow page title & breadcrumb | **Process Signup / Renewal** |
| Approve application CTA | **Approve & process** |
| Data fields, reports, sort columns | **Renewal year** (domain term) |

“Renew” is reserved for member-facing copy (public apply form, renewal season messaging, email templates).

## Exports

Toolbar export buttons: `btn btn-outline-primary btn-sm`, labels **Export CSV** and **Export PDF** (not “Download …”). Export year defaults use `defaultRenewalYear()` / `membershipStatusYear()` so behavior matches the dashboard and reports.

## Theme tokens

Club colors are CSS variables (`--club-primary`, `--club-muted`, etc.) from `includes/club_theme.php`. New UI should use these or Bootstrap utilities tied to the theme, not hard-coded Bootstrap primary blue.
