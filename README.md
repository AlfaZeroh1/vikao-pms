# Vikao PMS

The back office for Vikao Properties: property, billing, finance, communication, sales, CRM, short stay, the marketplace (vikao.io) and reports.

Stack: vanilla PHP 8.1+ and MySQL 8 / MariaDB 10.6+. The front end loads from CDNs (Tailwind CDN, jQuery, Font Awesome, SweetAlert2, Chart.js, Quill), so there is no build step.

The tenant/owner **self-service portal** is a separate app (`vikao-portal`). Both apps share **one database, one `app.key` and one uploads folder**, and each ships an identical copy of `/core`.

---

## 1. Install

```bash
# 1. put this folder on your web server, e.g. /var/www/vikao-pms
cp config/config.sample.php config/config.php     # edit db, urls, app.key, cron_token
# 2. create the database, schema, base data (roles, permissions, settings, bill types, admin) + demo portfolio
php database/install.php                # creates the DB if missing; includes demo data (≈45 s)
php database/install.php --no-demo      # production: base data + admin only
php database/install.php --fresh        # DROP the database and reinstall from scratch
```

- The web server must be able to write to `uploads/` and `storage/`.
- Apache: the shipped `.htaccess` files deny direct access to `config/`, `core/`, `layout/`, `modules/`, `database/` and `uploads/`. On **Nginx**, add the equivalent `deny all` rules for those folders.
- Uploaded files are only served through `file.php`, which requires a staff login (the portal has its own `file.php` with tenant/owner checks).

### Config keys (`config/config.php`)

| Key | What it does |
|---|---|
| `app.pms_url` / `app.portal_url` | Absolute URLs used in emails, activation links and receipts. No trailing slash. |
| `app.key` | Secret of at least 32 characters. Encrypts integration credentials. **Must be identical in the portal.** |
| `db` | The PMS database (also used by the portal). Tables are prefixed by module: `sys_ cfg_ prpt_ bln_ fin_ com_ sls_ crm_ sst_ rpt_ prt_ api_`. |
| `ecom_db` | The vikao.io ecommerce database, which the Ecommerce module reads and writes. `site_url` and `uploads_dir` point at the marketplace install. |
| `uploads.dir` | Shared uploads folder. Point the portal at the same path. |
| `cron_token` | Lets an HTTP scheduler call `cron.php?token=…`. |

## 2. Cron

```cron
*/5 * * * *  php /var/www/vikao-pms/cron.php            # runs every job that is due
# or one job at a time: php cron.php billing | overdue | escalation | leases | penalties | reminders |
#                         campaigns | coop | ical | maintenance | insights | cleanup
# or over HTTP: https://pms.example.com/cron.php?token=CRON_TOKEN&job=billing
```

| Job | What it does |
|---|---|
| `billing` | Auto-invoices each account on its invoice day, metered bills from approved readings. |
| `overdue` | Marks invoices overdue. |
| `escalation` | Applies rent escalation. |
| `leases` | Expiry and notice handling. |
| `penalties` | Raises late-payment penalties. |
| `reminders` | Sends payment and lease reminders. |
| `campaigns` | Sends scheduled campaigns. |
| `coop` | Pulls Co-op Bank transactions. |
| `ical` | Imports OTA calendars. |
| `maintenance` | Creates planned maintenance tickets. |
| `insights` | Builds the 6 am AI briefing. |
| `cleanup` | Clears sessions and rate limits. |

## 3. Payments (Finance → M-Pesa · Co-op · Stripe)

The master accounts are the Vikao M-Pesa paybill, Co-op Bank and Stripe. A property can also collect into its own account (Finance → Collection accounts).

**Every integration starts in _simulate_ mode.** The gateway page has a "Simulate" button that pushes a fake transaction through the real matching path, so you can test with no credentials. When you're ready, add the keys in Configuration → Integrations and switch simulate off.

Callback URLs to register:

| Provider | URL |
|---|---|
| M-Pesa C2B validation | `{pms_url}/webhooks/mpesa.php?action=validate&i=mpesa_vikao` |
| M-Pesa C2B confirmation | `{pms_url}/webhooks/mpesa.php?action=confirm&i=mpesa_vikao` |
| M-Pesa STK result | `{pms_url}/webhooks/mpesa.php?action=stk&i=mpesa_vikao` |
| Co-op Bank IPN | `{pms_url}/webhooks/coop.php?k=SHARED_SECRET` |
| Stripe | `{pms_url}/webhooks/stripe.php?i=stripe_vikao` (signing secret goes in the integration) |
| iCal export (per listing) | shown on Short stay → Listing → Channels |

Incoming money is matched on the bill reference: unit number, account format, lease number or the payer's phone. Matched payments are consumed automatically, oldest bill first. Anything unmatched lands in **Finance → Consume payment**, where you pick the payer and split the amount across bills by hand. An overpayment is kept as credit.

The marketplace (vikao.io) has **its own** M-Pesa and Stripe integrations under Ecommerce → Marketplace API. These are separate from rent collection.

## 4. AI insights

Reports → Insights works out of the box with rule-based insights. To add LLM-written briefings, activate the `anthropic` integration in Configuration → Integrations with an API key and switch simulate off.

## 5. Module map

| Menu | Route prefix |
|---|---|
| Property | `property/*` — structure, ownership, parties, meters, readings, reader app, assignments, lease templates, documents |
| Billing | `billing/*` — accounts and leases, wizard, invoices, billing runs, arrears, deposits |
| Finance | `finance/*` — payments, consume, gateways, expenses, remittances, statements, collection accounts, QuickBooks export |
| Communication | `comms/*` · **Sales** `sales/*` · **CRM** `crm/*` · **Short stay** `shortstay/*` · **Ecommerce** `ecommerce/*` |
| Reports | `reports/*` · **Administration** `admin/*` · **Configuration** `config/*` |

How routing works:

- Pages: `index.php?r=module/page` loads `modules/<module>/<page>.php`.
- AJAX: `api.php?r=module/action` dispatches to `modules/<module>/_api.php`. Each action declares the permission it needs, and RBAC is enforced there.
- Shared logic lives in `core/engines/*.php`.

**Keep `/core` identical in both repos.** After changing anything in `core/`, copy it into `vikao-portal/core`.

## 6. Demo logins (default install, i.e. without `--no-demo`)

| User | Password | Role |
|---|---|---|
| admin | Admin@2026 | Super admin |
| grace | Vikao@2026 | Property manager |
| daniel | Vikao@2026 | Accounts |
| joseph | Vikao@2026 | Meter reader (try the Reader app on a phone) |
| faith | Vikao@2026 | Customer care |
| brian | Vikao@2026 | Sales |
| lucy | Vikao@2026 | Short stay |
| kevin | Vikao@2026 | Marketplace |

Portal demo users are listed in the portal README.

**Change or remove the demo users before going live.**
