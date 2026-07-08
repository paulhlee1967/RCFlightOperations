# WPForms → RC Flight Operations

This guide connects your **WPForms Membership Application** (form 6569) on pvmac.com to RC Flight Operations via **Uncanny Automator**. Submissions land in **Applications** as pending until staff approve them.

## 1. RC Flight Operations setup

1. Run the latest `schema_full.sql` migration on your database (or deploy the app update so the `member_applications` table exists).
2. Log in as admin → **Administration → Installation**.
3. Under **WPForms integration**, set a long random **Webhook secret** and save.
4. Note the endpoint URL shown on that page, e.g.  
   `https://your-rcflightops-domain/api_webhook_application.php`

Optional: you can also set `application_webhook_secret` in `config.php` if you prefer file-based config.

## 2. Uncanny Automator recipe

Create a **new recipe** (your existing Sender.net recipe can stay — both run on the same form submission).

| Setting | Value |
|---------|--------|
| **Trigger** | WPForms → User submits a form → Membership Application (6569) |
| **Action** | Webhooks → Send data to webhook (or outgoing HTTP POST) |
| **URL** | Your `api_webhook_application.php` URL |
| **Method** | POST |
| **Data format** | JSON |
| **Authorization** | `Bearer <your secret from Installation>` (or header `X-Webhook-Secret`) |

### Request body

Send form fields as JSON key/value pairs. Use WPForms field **labels** as keys (e.g. `Name: First`, `AMA #`, `Address: Zip`). Map values using Automator tokens from the form trigger.

**Recommended Automator JSON** (form 6569 — use each value as the matching Automator token):

```json
{
    "Name: First": "{{10641:ANONWPFFORMS:6569|14|name|first}}",
    "Name: Middle": "{{10641:ANONWPFFORMS:6569|14|name|middle}}",
    "Name: Last": "{{10641:ANONWPFFORMS:6569|14|name|last}}",
    "Address: Address Line 1": "{{10641:ANONWPFFORMS:6569|19|address|address1}}",
    "Address: Address Line 2": "{{10641:ANONWPFFORMS:6569|19|address|address2}}",
    "Address: City": "{{10641:ANONWPFFORMS:6569|19|address|city}}",
    "Address: State": "{{10641:ANONWPFFORMS:6569|19|address|state}}",
    "Address: Zip": "{{10641:ANONWPFFORMS:6569|19|address|postal}}",
    "Phone": "{{10641:ANONWPFFORMS:6569|21}}",
    "Emergency Contact": "{{10641:ANONWPFFORMS:6569|177}}",
    "Emergency Phone": "{{10641:ANONWPFFORMS:6569|179}}",
    "Email": "{{10641:ANONWPFFORMS:6569|23}}",
    "Relationship": "{{10641:ANONWPFFORMS:6569|178}}",
    "Date of Birth": "{{10641:ANONWPFFORMS:6569|157}}",
    "New Member or Renewal": "{{10641:ANONWPFFORMS:6569|30|label}}",
    "New Member (Renewal Period Closed)": "{{10641:ANONWPFFORMS:6569|113|label}}",
    "Initiation Fee": "{{10641:ANONWPFFORMS:6569|187}}",
    "Membership Type": "{{10641:ANONWPFFORMS:6569|47|label}}",
    "Membership Type (Renewal)": "{{10641:ANONWPFFORMS:6569|48|label}}",
    "Membership Type (Prorated)": "{{10641:ANONWPFFORMS:6569|49|label}}",
    "Processing Fee": "{{10641:ANONWPFFORMS:6569|165}}",
    "Total (Membership + Fees)": "{{10641:ANONWPFFORMS:6569|120}}",
    "FAA Registration Number": "{{10641:ANONWPFFORMS:6569|28}}",
    "FAA Registration Expiration": "{{10641:ANONWPFFORMS:6569|137}}",
    "AMA #": "{{10641:ANONWPFFORMS:6569|27}}",
    "AMA Expiration": "{{10641:ANONWPFFORMS:6569|156}}",
    "Entry ID": "{{10641:WPFENTRYTOKENS:WPFENTRYID}}",
    "Application Submission Date": "{{10641:ANONWPFFORMS:6569|162}}",
    "Badge Photo (.jpg, .jpeg, .png), 5Mb Max": "{{10641:ANONWPFFORMS:6569|71}}",
    "FAA Registration (.jpg, .pdf, .png, .doc), 5Mb Max": "{{10641:ANONWPFFORMS:6569|70}}"
}
```

JSON keys must match each WPForms field **label** exactly. If you rename a field label in WPForms, update the matching key in Automator (the app also accepts common aliases such as `Badge Photo` — see `wpforms_application_field_aliases()` in code).

`Address: Country` is sent by WPForms but is not stored on the member record (US club addresses only need city/state/zip).

**Important — use `|label` on choice fields:** For radio/select fields (membership type, new vs renewal), pick the Automator token that ends in **`|label`**, not the raw option value. Without it, Automator may send `1` instead of `Adult - $80.00`, which breaks membership dues on the review screen. Hidden conditional fields can also leak ghost values (e.g. `New Member` in the renewal-closed field during prorated season); the app ignores those when a prorated membership choice is present.

Minimum useful payload example:

```json
{
  "Entry ID": "335",
  "Name: First": "Brent",
  "Name: Last": "Vartanian",
  "Email": "member@example.com",
  "Date of Birth": "06/22/1954",
  "Phone": "+19098999814",
  "New Member (Renewal Period Closed)": "New Member",
  "Membership Type": "Adult - $160.00",
  "AMA #": "931788",
  "AMA Expiration": "12/31/2026",
  "Total (Membership + Fees)": "216.58",
  "Application Submission Date": "06/29/2026"
}
```

WPForms payment gateway metadata (transaction IDs) is often **not** available as Automator tokens; `Total`, `Initiation Fee`, `Processing Fee`, and `Special Code (If you have one)` are usually enough for staff review.

**Currency encoding:** Uncanny Automator sometimes sends dollar amounts with `$` as HTML entities (`&#36;50.00` or `&amp;#36;50.00`). The app decodes these before parsing fees and totals.

Map payment fields using Automator tokens from the **field labels** in the dropdown — not guessed field IDs. Example keys:

- `Initiation Fee` → `$50.00`
- `Processing Fee` → `$4.19`
- `Total (Membership + Fees)` → final amount charged (e.g. `$0.00` when a coupon zeroes the cart)
- `Membership Type (Prorated)` / `Membership Type` → label with dues, e.g. `Adult - $80.00`

## 3. Staff workflow

1. Applicant submits WPForms on the website (payment collected there).
2. Application appears in **Applications** (navbar badge shows **total pending across all years**).
3. Staff review details, suggested member match, payment breakdown, and uploaded file links.
4. **Approve** → member is created or updated → **badge photo** (if submitted) is downloaded from the website and saved on the member record → redirects to **Signup/Renewal recording** (`member_process.php`) with suggested renewal type/year.
5. Staff record payment/fulfillment and print badge/letter as usual (website payment is not auto-posted to the ledger).

For day-to-day review UI details (filters, pagination, payment display), see **[docs/applications.html](docs/applications.html)** in the in-app Help center.

### Review screen

Open **Applications** from the top nav (also linked from **Members** and the dashboard “Needs attention” card when pending items exist).

| Area | What you see |
|------|----------------|
| **Status tabs** | Pending (default), Approved, Rejected, or All |
| **Renewal year** | Defaults to the **current renewal year** so older seasons stay out of the way; choose **All years** for history |
| **Search** | Name, email, or WPForms entry # |
| **List** | Newest first; **50 per page** with pagination when there are more |
| **Detail panel** | Applicant info, address, AMA/FAA, uploaded file links, payment breakdown, suggested member match, diff vs existing member |

If pending applications exist in **other renewal years** while you are filtered to the current year, a yellow banner offers **Show all years** so nothing is missed.

### Payment display

The **Payment (from website)** section shows:

- Membership dues (parsed from the membership type label, e.g. `$80.00` from `Adult - $80.00`)
- Initiation and processing fees
- **Subtotal** when all three line items are present
- **Special code** and “(coupon applied)” when the total paid is less than the subtotal
- **Total paid** from `Total (Membership + Fees)`

This is for **review only** — approving an application does not post a payment row. Continue on **Process Signup / Renewal** to record what the club actually received.

### Approve, reject, and cleanup

- **Approve & continue to recording** — creates a new member or updates the matched member, then opens renewal recording with suggested type/year (staff can override before approving).
- **Badge photo on approve** — when the application includes a badge photo URL from WPForms, the app downloads the image from your WordPress site (JPEG or PNG; GIF also accepted) and sets the member's `photo_path` so **Print card** works immediately on Process Signup / Renewal. FAA registration uploads (if collected on the form) stay as external links on the review screen only. If the download fails (wrong format, unreachable URL, file too large), approval still succeeds and a warning asks staff to upload the photo manually on the member record.
- **Reject** — marks the application rejected and removes it from Pending; the row is kept for audit under the **Rejected** tab.
- **Delete test data** — there is no in-app delete yet. To remove test submissions entirely (e.g. so the same WPForms entry ID can be re-sent), delete rows from `member_applications` in the database. Rejecting alone does not free the entry ID for webhook replay.

## 4. Seasonal form behavior

The app infers application type from your conditional fields:

| Season | Signal field | Kind |
|--------|--------------|------|
| Oct 15 – Dec 31 | `New Member or Renewal` = Renewal | Renewal |
| Oct 15 – Dec 31 | `New Member or Renewal` = New Member | New (renewal season) |
| Jan 1 – Jun 30 | `New Member (Renewal Period Closed)` = New Member | New (regular) |
| Jul 1 – Oct 14 | `Membership Type (Prorated)` filled | New (prorated) |

## 5. Field map reference

Full label → app field mapping lives in `includes/wpforms_application.php` (`wpforms_application_field_aliases()`). Key fields:

- Identity: `Name: First`, `Name: Last`, `Email`, `Date of Birth`
- Phone: `Phone` (member); `Emergency Phone`, `Emergency Contact`, `Relationship`
- Address: `Address: Address Line 1` … `Address: Zip` (line 2 promoted if line 1 empty)
- Compliance: `AMA #`, `AMA Expiration`, `FAA Registration Number`, `FAA Registration Expiration`
- Membership: `Membership Type`, `Membership Type (Renewal)`, or `Membership Type (Prorated)`
- Payment: `Total (Membership + Fees)`, `Initiation Fee`, `Processing Fee`, `Special Code (If you have one)`
- Files: **Badge photo** — JPEG/PNG/GIF URL from WPForms; copied to the member record on approve for badge printing. **FAA registration** (if still on your form) — URL only (linked on review screen; not stored locally). AMA card photos are no longer collected on the website form; staff verify AMA via the member record **Verify AMA membership** action after approval. Limit the badge photo field on WPForms to `.jpg`, `.jpeg`, `.png` so mobile camera uploads work reliably.

Optional in `config.php`: `wpforms_media_hosts` — array of allowed hostnames for badge photo download (default `pvmac.com`, `www.pvmac.com`). The server must have **cURL** enabled and be able to reach those upload URLs over HTTPS.

## 6. Testing

Test with curl (replace URL and secret):

```bash
curl -X POST 'https://your-domain/api_webhook_application.php' \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: YOUR_SECRET' \
  -d '{"Entry ID":"test-1","Name: First":"Test","Name: Last":"Applicant","Email":"test@example.com","Membership Type":"Adult - $160.00","New Member (Renewal Period Closed)":"New Member"}'
```

Expected response: `{"ok":true,"application_id":1,"duplicate":false}`

## 7. Email notifications

When **Support email** is set in Installation, a notification is sent for each new pending application.

## 8. Sender.net and reminder opt-out

Website applicants are typically added to your Sender.net **newsletter/members** list via a separate Uncanny Automator recipe on the same form. Applicant emails are stored **lowercase** in RC Flight Operations to match Sender.

AMA/FAA expiry reminders use Sender’s API when configured under **Administration → Installation → Sender.net (reminder opt-out)**:

- Set the **API token** and **members group ID** (required for auto-added reminder recipients).
- Set **`canonical_host`** or **`public_base_url`** in `config.php` so reminder emails include logo and unsubscribe URLs when cron runs.
- Reminders check **transactional** (`temail`) opt-out — unsubscribing from newsletters does **not** block reminders.
- Each reminder includes a signed link to **`unsubscribe.php`** on this app (reminder-only opt-out).

See [docs/admin.html](docs/admin.html#sender-opt-out) in the Help center.
