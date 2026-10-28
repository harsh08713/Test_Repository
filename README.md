# ZenVanta — Masters + CRM-Marketing

Multi-company, multi-branch ERP foundation with a CRM-Marketing module, built on **Laravel 13**, **Livewire 4**,
Tailwind 4 and MariaDB/MySQL. Every master and transaction goes through an approval workflow; only approved
records can be used in transactions.

> **Status: Phase 15 of 20 — Campaigns, consent and workflow automation (built, awaiting review).** Phase 1 (tenancy, roles/permissions,
> numbering, approvals, comments, audit, settings, admin UI) is signed off; Phase 2 (Company, Branch, Chart of Accounts,
> Department, Designation, Unit, Item Group, State → District → Block → PIN code, and a controlled spreadsheet import for
> Tent Studio's geography data), Phase 3 (the central **Ledger** master), Phase 4 (the **Item** master, filled in by Purchase,
> then Inventory, then approved by Accounts), Phase 5 (the **Rate** master and the `RateService` that quotations and orders
> will ask for a price), Phase 6 (field sales: **no route plan, no attendance**, checked on the server against a geofence),
> Phase 7 (**leads**, follow-ups, a calendar, reminders, hand-overs) and Phase 8 (the **mobile API** with Sanctum device
> tokens; a lead saved from the field outside the geofence is refused with a 422 and logged) are done. Phase 9 added the two
> **lead marketplaces**: IndiaMART (pulled on a schedule, or pushed) and Just Dial (pushed), every delivery written to a log first,
> de-duplicated by the provider's lead id and by mobile number, incomplete leads marked, failures kept for a retry; both are
> built against the providers' documented shapes and **not yet verified on a live account**. Phase 10 adds the **inquiry from an
> existing customer** (an approved customer, the items asked for with the rate expected, through approval) and **deals**: a
> qualified lead is converted — once, with every line it listed — to a numbered deal with its lines, value, stages and approval.
> Phase 11 adds **quotations** from an approved deal or inquiry: priced only from the rate master, GST by place of supply, revisions that
> never change once sent, approval before sending, PDF and e-mail, expiry — see [docs/quotations.md](docs/quotations.md). Phase 12 adds
> **sales orders** (from an accepted quotation, an approved deal or directly for a regular customer, with a credit check) whose approval makes
> **exactly one sales invoice** — see [docs/sales-orders.md](docs/sales-orders.md). Phases 13–14 added dashboards, reports, Customer 360, tasks and SLAs, and Phase 15 **campaigns** (approved templates, consent, a frequency cap, a message log, attribution) and a **workflow builder** — see [docs/marketing.md](docs/marketing.md). See the [roadmap](docs/roadmap.md) and the authoritative
> [brief](FormEzy_ERP_Master_AI_Coding_Prompt.md). All documentation is indexed in [docs/index.md](docs/index.md); inquiries and
> deals are in [docs/deals.md](docs/deals.md) and the marketplaces in [docs/integrations.md](docs/integrations.md).

## Requirements

| | Needed | Notes |
|---|---|---|
| PHP | **8.3 or newer** | Laravel 13 will not boot on 8.2. XAMPP builds older than 8.3 do not qualify — use a separate PHP 8.3 (see below). |
| Database | MySQL 8 / MariaDB 10.4+ | Developed against MariaDB 10.4; production target is MySQL 8 or MariaDB 10.11+. InnoDB, utf8mb4. |
| Node | 20+ (22+ for the browser checks) | Vite 7 / Tailwind 4 build, and the follow-up calendar's bundle (FullCalendar). Not needed at runtime. |
| Composer | 2.5+ | |

PHP extensions: `bcmath curl exif fileinfo gd intl mbstring openssl pdo_mysql sodium zip`.

## Setup

```bash
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate

# create two empty databases: industrial_crm (app) and industrial_crm_test (tests)
# then check DB_* in .env

php artisan migrate --seed      # schema + roles/permissions + demo companies and users
npm install && npm run build

php artisan serve --port=8731   # pick any free port, and keep APP_URL in .env in step with it
```

**Running on Windows with XAMPP.** If XAMPP's PHP is older than 8.3, install a portable PHP 8.3 NTS build from
windows.php.net into its own folder, enable the extensions above in its `php.ini`, and put that folder first on
`PATH` for this project's terminal session. Apache/MariaDB from XAMPP can stay as they are.

**Background work.** Notifications (in-app and e-mail), spreadsheet imports and marketplace leads are queued. Local development can use
`QUEUE_CONNECTION=sync` (the project's local `.env` does); otherwise run `php artisan queue:work`. Follow-up reminders
and escalations, and the IndiaMART pull, are run by the **scheduler**, every five minutes: run `php artisan schedule:work` locally
(production has the cron line under *Deployment*), or send one round by hand with `php artisan crm:followup-reminders` /
`php artisan integrations:pull`.

### Demo accounts

`php artisan db:seed` (non-production only) creates two companies — *FormEzy Demo Industries* (3 branches) and
*Bharat Fasteners* — and one user per role. Every demo password is `password`.

| Email | Role | Sees |
|---|---|---|
| admin@formezy.test | Super Admin | both companies; holds every permission explicitly, cross-company entries are audited |
| companyadmin@formezy.test | Company Admin | demo company, all branches |
| approver@formezy.test | Approver | approve rights on every approvable form |
| purchase@ / inventory@ / accounts@ / operations@formezy.test | Purchase / Inventory / Accounts / Operations | Operations is view + comment only on Items |
| manager@formezy.test | Sales Manager | manages both demo sales teams; sees their leads and the unassigned pool, hands leads over, imports them |
| bde@formezy.test | BDE / Field Salesperson | Ahmedabad branch only; has a published route plan for today, his own leads (one with an overdue follow-up) |
| bde2@formezy.test | BDE / Field Salesperson | Mumbai branch only; has **no** route plan for today (attendance is refused) |
| finance@formezy.test | Finance Controller | approves budgets (Accounts makes them); reads profitability and budget against actual |
| management@formezy.test | Management | read-only: profitability, budgets, loss incidents, dashboards, every employee file (no sensitive fields) |
| hr@formezy.test | HR Manager | the employee files, org chart, notice and exit, papers, HR dashboard |
| quality@formezy.test | Quality/Loss Manager | validates loss figures, approves workflow moves (closing), verifies corrective actions |
| investigator@formezy.test | Investigator | investigates the demo press-line stoppage and owns its preventive action |
| telecaller@formezy.test | Tele-caller | Ahmedabad + Surat; on no team; has her own lead and a call to make on one of Bhavin's (so she can open it) |

The demo data is seeded **relative to the day it is seeded** (today's route plan, yesterday's missed follow-up), so run
`php artisan migrate:fresh --seed` again to see it as intended on another day. To try the duplicate warning, start a new
lead as `bde@formezy.test` and type the mobile number `9000000104`.

The sales roles (manager, BDEs, tele-caller) and the admins can also sign in to the **mobile API** with the same e-mail and
password:

```bash
curl -s -X POST http://127.0.0.1:8731/api/v1/auth/login -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"bde@formezy.test","password":"password","device_name":"curl"}'
```

## Quality gates

```bash
composer test       # Pest (Feature, Unit and the multi-process Concurrency suite) on the industrial_crm_test database
composer lint       # Laravel Pint (--test)
composer analyse    # Larastan, level 6
composer check      # all three
```

Tests need MariaDB/MySQL running (the number-series locking and tenant scoping are database behaviour, so they are
not run on SQLite). Roles and permissions are seeded once per run. `tests/Concurrency` starts several separate PHP
processes that act at the same moment (numbers, rates, targets, attendance, lead hand-overs, follow-ups, reminders); it
commits to the test database and cleans up after itself. Do not start a second Pest run while one is going: each run
rebuilds the test database.

**Browser checks.** Pest drives every screen on the server, so it cannot see a script error, a button that does nothing
in a browser, or a calendar that draws the wrong dates. `tests/Browser` checks those in a real, headless Chrome or Edge:

```bash
php artisan migrate:fresh --seed     # the checks sign in as the demo accounts and use the demo data
php artisan serve --port=8731        # or say where the application is with BROWSER_CHECK_URL
npm run test:browser                 # about a minute; `node tests/Browser/run.mjs calendar` runs the checks whose name matches
```

It needs Node 22+, a Chromium-based browser already on the machine (`BROWSER_BIN` to choose one) and **PHP 8.3 or newer**, which it finds itself
(`PHP_BIN`, then `php` on the PATH, then a portable `php-8.x` under `%USERPROFILE%	ools`) and, if none is new enough, says so and stops — XAMPP's
older PHP on the PATH used to end in a cryptic "Composer detected issues in your platform" error. Nothing is installed. It opens every page looking for script and HTTP errors, then uses the parts that depend on
JavaScript: one success message per action, the follow-up calendar (with a drag), the lead form's live checks, a real
spreadsheet upload with column mapping, and attendance from a position. The checks add a few records, so seed again
afterwards. Run it after any change to a screen's JavaScript, to the layout, or to Livewire itself.

`php artisan formezy:docs` regenerates `docs/permission-matrix.md` and `docs/route-inventory.md` from the code.

## How the foundation works

Code is grouped by module under `app/Modules/{Shared,Masters,CRM,Sales}`. `Shared` holds everything the others build on.

**Tenancy** — `TenantContext` resolves the active company (explicit override → request choice → user default → first
membership) and the branches a user may see. Global scopes (`CompanyScope`, `LocationScope`) filter every query,
and `BelongsToCompany` / `BelongsToLocation` refuse writes into a company or branch the user cannot access, so the
client never chooses the company. No signed-in user on a web request sees nothing (fail closed); console work
(seeders, jobs) is unrestricted unless wrapped in `TenantContext::withCompany($id, fn)`.

**Approvals** — a model implements `ApprovableDocument` and uses the `Approvable` trait. `ApprovalService` handles
submit / approve / reject: configurable levels per document type, decided in order or in parallel, optional
deadlines, one ledger row per level per submission (full history kept), maker–checker control (company setting),
a content fingerprint so a document edited after submission cannot be approved, row locking so a level cannot be
decided twice, and `DocumentApproved` / `DocumentRejected` events that fire after commit. Drop
`<livewire:shared.approval-panel type="…" :id="…" />` on a detail screen for the UI. See [docs/approvals.md](docs/approvals.md).

**Numbering** — `NumberSeriesService::next(DocumentType, Location)` returns e.g. `QT/AHD/26-27/00001`, one locked
counter per company + branch + document type + April–March financial year.

**Permissions** — `{form}.{action}` (view / create / edit / delete / approve / export / import / comment / assign /
configure / view audit). The catalogue and default role grants live in `PermissionRegistry`; the matrix is editable at
*Administration → Roles & permissions* ([generated reference](docs/permission-matrix.md)). There is **no implicit
bypass**: Super Admin is an ordinary role that the seeder keeps granted with every permission, and its reach beyond a
user's own companies and approval levels comes from the named permissions `tenant.access_all` and `approval.override`,
whose use is recorded. Re-running `RolePermissionSeeder` adds new permissions but never overwrites a matrix you edited
(Super Admin excepted: it is always re-synced).

**Also** — polymorphic comments (`<livewire:shared.comments …/>`; comment rights are separate from edit rights, and each
comment is `internal` or `customer`-visible), an append-only audit log with old/new values stamped per company,
per-company settings (secrets encrypted), in-app + e-mail notifications, tenant-aware queued jobs
(`RunsInCompanyContext`), a single password policy (10+ characters, mixed case, a number; breached-password check in
production), and Indian formatting (`Format::inr`, DD-MM-YYYY, Asia/Kolkata).

### Masters (Phase 2)

*Masters* in the sidebar: **Companies** and **Branches** (GSTIN with check-digit verification, PAN/CIN, a private logo,
branch codes that lock once numbering starts), **Chart of accounts** (a tree seeded with the standard Indian groups, which
are locked; your own groups slot in beneath them, same-nature moves only), **Item groups** (a free tree),
**Departments**, **Designations**, **Units of measure** and **Geography** (States → Districts → Blocks → PIN codes).
Creating a company seeds its 36 states/UTs, 19 standard units and the standard chart of accounts. A PIN code fills a
branch's state and district when the answer is unambiguous. None of these are approval documents; ledgers and items
(Phases 3–4) are.

**Ledgers (Phase 3)** — *Masters → Ledgers*: one master for customers, suppliers, banks, cash, expense and general
accounts, each filed under the right part of the chart of accounts, with billing/shipping addresses, contacts, credit terms,
payment preferences (*Masters → Payment methods*) and bank details. Ledgers start as drafts, are submitted for approval and
**only approved, active ledgers can be used in transactions** (`LedgerLookup`). Purchase onboards suppliers; Accounts handles
every type; bank account numbers are encrypted, masked and visible only to those with `ledger.view_sensitive`. An approved
ledger is locked, but can be re-opened (with a reason) or switched off. See [docs/ledgers.md](docs/ledgers.md).

**Items (Phase 4)** — *Masters → Items*: each item passes three departments. **Purchase** enters the name, item group, base
unit, other units ("1 Box = 12 Nos"), PO tolerance, HSN/SAC and GST rate; **Inventory** assigns the code, alias names and
minimum / reorder / maximum stock per branch; the finished item is submitted and **Accounts** approves it (the role items
are routed to is the company setting `approvals.item_approver`). Each department edits only its own stage; Operations can
view and comment but not change anything. **Only approved, active items can be used in transactions** (`ItemLookup`).
Conversions between units are exact (`UomConverter`, never floats). Once approved, an item's code, base unit and existing
conversions are frozen; it can be re-opened (with a reason) or switched off. Every move between stages is recorded.
See [docs/items.md](docs/items.md).

**Rates (Phase 5)** — *Masters → Rates* and *Price lists*: what one unit of an item costs at one branch for a period (inclusive
at both ends; an empty end means until further notice), in the item's base unit or one of its conversions, optionally in a price
list. Accounts enters rates and a second person approves them. **Only approved, active rates in force on the day are ever
returned to a transaction** (`RateService::getRate()`); when there is none it says why (not yet effective, ended, not approved,
switched off…) instead of guessing, and it never converts between units. Live rates for the same item, branch, unit and price
list cannot overlap. An approved rate can be ended on a date without re-approval; changing the price itself means a re-open or a
new rate. Overriding a looked-up rate on a document needs the `rate.override` permission and leaves an audit event with the
original and new value and the reason. See [docs/rates.md](docs/rates.md).

**Field sales (Phase 6)** — the *Field sales* menu. **Sales teams**: a manager and the salespeople under them (a person is on
one team at a time; history is kept). **Targets**: per salesperson or team, per month or financial-year quarter, by value
and/or quantity, optionally per item group and branch; two targets for the same person and scope cannot cover the same days.
**Route plans**: one per salesperson per day — ordered stops (area, block, PIN code or customer) with a place on the map —
drafted, then published. **Attendance**: a salesperson marks it from *My day*; the browser only reports a position, and the
server refuses it unless there is a **published route plan for today** and the position is real, accurate enough and inside
the geofence of one of its stops. Every refused attempt goes to the **geofence violation log**; where the company allows it
(off by default), a manager holding `geofence.override` can accept one with a reason. A manager sees and plans only their
teams; seeing everyone is the explicit `sales.scope_all` permission. See [docs/field-sales.md](docs/field-sales.md).

**Leads and follow-ups (Phase 7)** — the *CRM* menu. **Leads** are numbered per branch (`LD/AHD/26-27/00001`) and belong to
the person they are assigned to; a salesperson sees their own, a manager their teams' and the unassigned pool, and seeing
everyone's is `sales.scope_all`. Typing a mobile number that is already on an open lead — in any branch — **warns** and asks
whether it is a separate inquiry; it does not block. A PIN code fills the state, district and block. A lead moves between
*New*, *Contacted* and *Qualified* by hand; *Lost* needs a reason; *Deal*, *Quotation* and *Won* are set only by a conversion
(later phases). **Follow-ups** (call, visit, e-mail, WhatsApp, meeting) are planned, then done with an outcome — an outcome
such as *Call Back Later* cannot be recorded without the next date — or called off; the person is reminded shortly before
one is due and their manager is told when one is left overdue. **Calendar** shows them by day, week or month and lets a
planned one be dragged to another time. **Hand-overs**, one lead or many, are all-or-nothing, need a reason, and leave a
permanent ownership history. **Import from Excel** accepts a file with its own headings (map the columns on screen), imports
the valid rows, reports the rest row by row, and skips numbers that are already leads. See [docs/leads.md](docs/leads.md).

**Phones, tablets and the installable app (Phase 17, first slice)** — every screen fits a phone (drawer menu, tables as one card per row,
16 px fields), a tablet and a desktop; touch screens get bigger buttons and a hover-only control is always shown without a mouse. The
app can be **installed** (account menu → *Install app*; on iPhone, Share → Add to Home Screen) and opens in its own window; it needs
**HTTPS** (or `localhost`). Offline it says so and shows an offline page — it keeps only its own build files and icons, **never a page
of data**, and **nothing can be saved offline yet** (the next slice). `node tests/Browser/responsive.mjs` checks every page at 375 /
768 / 1024 px; `npm run test:browser` (*installable app*) checks the worker. See [docs/pwa.md](docs/pwa.md).

**Mobile API (Phase 8)** — `/api/v1`, for the field app (the PWA first). A device signs in with e-mail and password and gets a
revocable **Sanctum token** (14 days; listed per person; signing out, a password change or deactivation removes it). Only
accounts holding the `api.access` permission may sign in. The app sends a position and what the person typed; the **server**
decides the company, the branch, whose leads they see and whether the position is inside the geofence of a stop on **today's
published route plan** — a lead saved from the field outside it is refused with **422** and written to the violation log
(*Field sales → Geofence log*, shown as "Lead saved in the field"). Every answer has one JSON envelope and a request id; creates
need an `Idempotency-Key`, so a retry never makes a second lead; lists page within bounds and sync with `updated_since`. Leads,
follow-ups and reference data use the same services as the screens, so the app can never do what the screens refuse. Guide:
[docs/api.md](docs/api.md); contract: [docs/api/openapi.yaml](docs/api/openapi.yaml). Settings: `API_TOKEN_TTL_MINUTES`,
`API_MAX_DEVICES`, `API_CORS_ORIGINS` (empty = no browser origin allowed). The access log is `storage/logs/api.log`.
**IndiaMART and Just Dial (Phase 9)** — *Administration → Integrations*. A marketplace lead is **written to the inbound lead log
first** (secrets removed, one row per provider lead id, however often it is delivered), then becomes a lead in the branch you choose
under the provider's standard source — in the pool or given to a person — or, when an open lead already has that mobile number, a
note on that lead. A lead without a mobile number, or whose buyer gave no name, is kept and marked **incomplete**. IndiaMART is
pulled within its limits (one call per 5 minutes, 7 days a call, a watermark with an overlap) and/or pushed; Just Dial only pushes, to
an unguessable webhook address you give its account manager (optionally signed). A delivery that cannot become a lead stays **Failed**
with a plain reason and can be retried from the log. Needs the scheduler and a queue worker. API keys and signing secrets live in
Company settings, encrypted and write-only. See [docs/integrations.md](docs/integrations.md).
**Inquiries from customers and deals (Phase 10)** — *CRM → Leads → Add an inquiry from a customer*, and *CRM → Deals*. An
**inquiry** is a lead for a customer who is already in the ledger master (chosen by code, name or GSTIN; approved and active only):
its name, contact and address come from the ledger, every line is an approved item with a quantity and the **rate expected**, and
the value is worked out from the lines. It is sent for **approval** and is locked while it waits and once approved (re-open it with a
reason to correct it). A **qualified** lead that has an owner is **converted to a deal** from its page — once, however many people
try: every line the lead listed comes across (adjust it, add more, but not drop it or swap its item), the deal takes the lead's branch
and owner, is numbered `DL/AHD/26-27/00001`, and the lead moves to *Deal*. A deal's **value is the server's sum of its lines**; it
moves Discovery ⇄ Negotiation, is marked **Lost** with a reason (the lead with it; bringing it back is a right of its own,
`deal.reopen`), goes through approval (which locks its customer and lines, not where it stands), and is handed over with its lead.
Quotation and Won are set only by the documents that follow. The mobile API has deals, conversion and customer search. See
[docs/deals.md](docs/deals.md).
**Importing geography** — *Masters → Geography → Import*: download the template, fill State / District / Block /
PIN Code / Locality, upload, review the check (nothing is saved yet), confirm. Rejected rows come back in an error report
with their row numbers; re-uploading is always safe. Needs the `geography.import` permission and a running queue worker
(or `QUEUE_CONNECTION=sync` locally). Rules and internals: [docs/imports.md](docs/imports.md).

### Adding a module record

1. Migration: `$table->masterColumns(); $table->locationColumn();` (macros in `BlueprintMacros`).
2. Model extends `TenantModel`; add `Approvable` (+ `implements ApprovableDocument`) and `BelongsToLocation` as needed;
   keep `approval_status` out of `$fillable`.
3. Register its morph alias in `SharedServiceProvider`, a policy extending `BaseDocumentPolicy`, and its forms in
   `PermissionRegistry` / `DocumentType`.

## Deployment

1. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`, database credentials, `QUEUE_CONNECTION=database` (or
   redis), a real `MAIL_*` transport, `APP_TIMEZONE=Asia/Kolkata`.
3. `php artisan migrate --force` then `php artisan db:seed --class=RolePermissionSeeder --force`
   (**not** the full seeder — the demo seeder creates accounts with a known password).
4. Create the first Super Admin and company (tinker or a one-off seeder) and assign the `Super Admin` role.
5. `php artisan optimize` (config, route, view, event caches); run `php artisan storage:link`.
6. Keep a worker alive (`php artisan queue:work --tries=3`, under Supervisor / a Windows service) and add the
   scheduler cron: `* * * * * php /path/artisan schedule:run`.
7. Point the web root at `public/`; serve over HTTPS. Set `BACKUP_ENABLED=true` (nightly `formezy:backup`, and a weekly `--verify` that restores it into a scratch database to prove it) and copy the files in `storage/app/backups` off the server — see [docs/operations.md](docs/operations.md) for backups, the restore procedure and the security checklist.
8. Marketplaces: use a cache every process shares (`CACHE_STORE=database` or `redis`) — the one-lead-per-buyer lock lives in it — keep the
   queue worker and the scheduler running, and save the IndiaMART key and any signing secrets under Company settings → Integrations.
9. Mobile API: set `API_CORS_ORIGINS` only if a browser client on another host will call it; the scheduler's daily jobs
   (`api:prune`, `sanctum:prune-expired`) clear expired idempotency keys and tokens; watch `storage/logs/api.log`.

Rolling out a release that adds permissions: deploy, `migrate --force`, re-run `RolePermissionSeeder`, `php artisan optimize`.

## Known limits (Phase 15)

Campaigns, consent and workflows ([docs/marketing.md](docs/marketing.md)): `marketing:run` and `automation:run` run every five minutes from the scheduler, and
a queue worker hands the e-mails to the mail server outside local development (**System health**, `/admin/health`, shows whether they are running).

- **E-mail only.** WhatsApp/SMS/telephony arrive in Phase 16; a message on those channels is kept as `not_connected`, never "sent".
- "Sent" means the mail server accepted it. There is no delivery, bounce, open or click tracking yet.
- Consent is whatever has been recorded (form, manual, import, unsubscribe link); the system refuses opted-out addresses but cannot prove an old opt-in.
- Attribution is a credit (manual, link or inferred within 30 days), not proof of cause. No A/B variants, HTML designer or multi-touch.

## Known limits (Phase 14)

Customer 360, tasks and SLAs ([docs/workspace.md](docs/workspace.md)): the scheduler must run every minute (`php artisan schedule:run`) — `sla:check` and
`tasks:remind` run every five minutes, and a queue worker sends their e-mails outside local development.

- Nothing is reassigned automatically on an SLA breach: the person and their manager are told and the breach is logged.
- Closed with Phase 15: SLA pause (`sla.pause`), SLAs for quotations and approvals, the breach log for salespeople (their own), task attachments and a record picker, Customer 360 suggestions by phone/e-mail, and the scheduler heartbeat / System health page ([docs/workspace.md](docs/workspace.md)).

## Known limits (Phase 13)

Dashboards and reports ([docs/reports.md](docs/reports.md)): sixteen baseline reports and a role-aware sales dashboard, all read through the same access
rules as the screens; Excel and PDF exports are made in the background (a queue worker must be running outside local development) and kept for 7 days.

- Of the planned reports only AI activity is not built (Phase 18); campaign performance and SLA compliance now exist.
- Excel exports stop at 50,000 rows (narrow the filters), PDF prints the first 1,000; nothing is cached; no scheduled or e-mailed reports.
- Pending approvals shows a non-administrator only what waits for them.

## Known limits (Phase 12)

Sales orders and invoices ([docs/sales-orders.md](docs/sales-orders.md)): approving an order makes its invoice once (a retry, a replayed event
or two people cannot make a second one; `sales:generate-invoices` runs every 15 minutes to catch an approval whose invoice could not be made,
so keep `php artisan schedule:run` going every minute). Targets are now achieved by approved orders.

- **The credit check cannot know what a customer has paid** (accounting is not in scope): it counts the customer's other orders still inside their
  credit days as unpaid. A customer with no credit limit is never blocked. The company chooses warn, block or ignore (Company settings ▸ Sales).
- An invoice posts nothing to stock or to accounts, is invoiced in full (no partial invoicing, delivery notes or returns yet) and is not e-mailed.
- A won deal is not put back when its order is cancelled; the quotation and deal can be ordered again.
- No API for orders and invoices; the PDFs have no logo, letterhead or amount in words.

## Known limits (Phase 11)

Quotations ([docs/quotations.md](docs/quotations.md)): one row per **revision**, never edited once sent; priced only from the rate master
(a changed rate needs `rate.override`, a reason and leaves an audit entry), GST by place of supply, approved before it is sent, PDF and
e-mail with a dispatch log. For e-mail to leave the building set `MAIL_MAILER` (and `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`) in `.env`; locally `MAIL_MAILER=log` only writes the message to the log. The scheduler also runs
`sales:quotation-expiry` daily (expires unanswered quotations and warns their owners), so keep `php artisan schedule:run` going every minute.

- The PDF is a plain A4 layout (no logo, letterhead or amount in words); "sent" means the mail server accepted it.
- A customer's price list is chosen **on the quotation**, not stored on the customer. A lost deal does not withdraw its quotations.
- Quotations are read-only in the mobile API; making, sending and revising them, and the PDF download, are on the web.
- A sales order from a quotation goes through `QuotationConversionGuard`: accepted, approved, latest and in date, or an audited exception
  by someone holding `quotation.convert_exception`.

## Known limits (Phase 10)

- An inquiry's approval gates a quotation made **directly** from it, not converting it to a deal (which has its own approval): the brief's
  wording is open to both readings — say if you want a deal to need an approved inquiry.
- Deal **stages are fixed** (Discovery, Negotiation, Quotation, Won, Lost) and there is no direct deal entry: a deal is always made from a lead.
- **Follow-ups stay on the lead** (which stays open while its deal is); a deal has none of its own until tasks (Phase 14). No attachments (Phase 19).
- **The expected rate is typed**, not looked up: it is an expectation, not a price. Approving or rejecting is done in the web inbox, not the API.
- A manager who converts someone's lead does not send them a notice; the owner finds the deal in their list.

## Known limits (Phase 9)

- **Not verified against a live IndiaMART or Just Dial account** (credentials and the Just Dial payload come from the owner and the
  providers): the adapters are covered by contract tests built from IndiaMART's published Pull API guide and the layout Just Dial
  integrations commonly receive. Fields beyond the documented ones are marked as such in [docs/integrations.md](docs/integrations.md).
- The providers cannot sign their pushes, so by default the secret webhook address is the only protection; signing needs a relay you run.
- Leads go to one person or the pool; there is no round-robin or rule-based routing, and no export of the log.
- The log holds buyers' phone numbers and e-mail addresses: it is pruned after 90 days by default (Company settings). Receiving a lead is not consent to market to the buyer.
- The marketplace locks (one lead per buyer) need a cache every process shares: use `database` or `redis`, not `array`.
## Known limits (Phase 8)

- No attachment upload yet: there is no attachment store until documents (Phase 19). Quotations can be read (Phase 11), not made. (Deals came in Phase 10.) No offline conflict resolution beyond `updated_since`, `Idempotency-Key` and
  `expected_updated_at` — the PWA's sync is Phase 17. No lists of states/districts/blocks (a PIN code fills them).
- The geofence applies to the **mobile channel**. A salesperson can still enter a lead on the *web* form from anywhere; if
  the owner wants field staff kept to the app, say so and that rule can be added. Editing an existing lead is not geofenced.
- A faked GPS on the device cannot be detected by the server (as for attendance). A lead moved to someone else disappears from
  the previous owner's `updated_since` feed without a notice: the app should refresh its list now and then ([docs/api.md](docs/api.md) §7).
- Existing installations keep the permission matrix they edited: grant `api.access` to the roles that should use the app
  (a fresh `migrate:fresh --seed` already does). Live verification with a real phone is pending; the contract is covered
  by 100+ server-side tests and a race test of the idempotency key.
## Known limits (Phase 7)

- Leads cannot be exported yet and duplicate leads cannot be merged (reports are Phase 13). A lead's branch cannot be changed
  after it is entered. Follow-ups have no attachments (document management, Phase 19).
- Reminders and the escalation to the manager are sent only while the scheduler runs, and e-mail only with a real `MAIL_*`
  transport (local development writes mail to the log). The escalation is one rule (still planned N hours after it was due →
  the team's manager, once); SLA policies arrive in Phase 14.
- The screens enter a lead at a desk; entry from the field, with the position checked against the route plan, is the mobile API
  (Phase 8). IndiaMART / Just Dial leads are Phase 9.
- The calendar needs JavaScript (the *Follow-ups* list shows the same without it); dragging moves a follow-up within the
  visible period only.
- A leading `0` followed by ten digits is read as a mobile number, so a landline written with its STD code can pass as one
  when its first digit is 6–9.

## Known limits (Phase 6)

- Attendance is one check-in per day (no check-out, no per-stop visit confirmation yet). *My day* uses the browser's
  location until the mobile API arrives in Phase 8; a desktop browser is usually less accurate than the 150 m the rule asks
  for, so try it from a phone, or raise *Company settings → Field sales → worst GPS accuracy accepted* while testing.
- To try attendance locally, sign in as `bde@formezy.test`: the demo plan's first stop is in the centre of Ahmedabad, so from
  anywhere else you will be refused (and see the attempt in the violation log). Edit the plan's first stop to your own
  coordinates as `manager@formezy.test` to see it accepted.
- Target achievement is read from approved sales orders (Phase 12); the policy for what counts is in
  [docs/field-sales.md](docs/field-sales.md). Stop coordinates are typed in (no map picker); route-plan import is not built.
- A faked GPS on the device cannot be detected by the server.

## Known limits (Phase 5)

- One branch per rate (no company-wide rate), no bulk entry or import of rates yet, and customers are not linked to a price list
  (the caller of `RateService` chooses it; quotations choose it per quotation). Quotations use the override hook; orders will (Phase 12).
- Changing an approved price means a re-open (a gap until it is approved again) or ending the rate and adding a new one.
- The demo seeder creates two price lists and eleven fictitious rates covering every state.

## Known limits (Phase 4)

- Correcting an approved item means re-opening it, so it drops out of the pickers until it is approved again; there is no
  "pending revision" that keeps the approved version in use meanwhile (a decision for the owner).
- No item import/export yet. Items are not yet used by any transaction (Rates are Phase 5, Quotations Phase 11), so "in
  use" checks rest on the freeze of an approved item's identity.
- The demo seeder creates seven fictitious items for the demo company, one in every workflow position.

## Known limits (Phase 3)

- One bank account per ledger; no ledger export/import yet; the credit limit is checked on sales orders (Phase 12, see
  [docs/sales-orders.md](docs/sales-orders.md)). "In use" checks on ledgers can only cover what exists so far.
- The demo seeder creates ten fictitious ledgers for the demo company (every type, every approval state); the bank account
  number in it is made up.

## Known limits (Phase 2)

- Tent Studio's own file layout has not been supplied: the import template (State, District, Block, PIN Code, Locality) is
  ours. The demo seeder loads a *sample* of six PIN rows through the same importer; it is not authoritative data.
- Geography is per company, so each company imports its own copy; there is no shared national master.
- Imported files are kept indefinitely (no retention job yet) and a full national PIN directory (~150,000 rows) has not
  been benchmarked; use CSV for very large files.
- Company logos support PNG/JPG/WebP only (no SVG, deliberately), up to 1 MB.
- Deleted geography rows are soft-deleted and there is no restore screen yet (an import restores them).

## Known limits (Phase 1)

- Sign-in and sign-out only: no self-service registration or password-reset e-mail yet (admins set passwords).
- Number allocation is proven under real concurrency (six processes racing, including the very first number, and — since
  Phase 7 — issued inside the document's own transaction, which is where it used to deadlock) on MariaDB 10.4; re-run
  `tests/Concurrency` on the production database version before go-live.
- Attachments on comments are not implemented (arrive with document management, Phase 19); `visibility` is already stored.
- Overdue detection and escalation for approval deadlines arrive with SLA management (Phase 14).
- `spatie/laravel-activitylog` is on 4.12 because 5.x requires PHP 8.4; upgrade together with the PHP version.
