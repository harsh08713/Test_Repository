# FormEzy + Loss Intelligence ERP — Project Brief for Claude Code

> Place this file at the **root of the Laravel project** as `CLAUDE.md`. Claude Code reads it automatically every session.
> Companion files: `docs/PROGRESS.md`, `docs/DATA_DICTIONARY.md`, `docs/FORMULAS.md`.

You are a senior Laravel ERP architect, PHP developer, database designer and enterprise UI engineer. You build **one Laravel application** that combines:

1. **FormEzy ERP**: Masters, CRM-Marketing and Sales (lead → deal → quotation → sales order → invoice).
2. **Loss Intelligence ERP**: revenue, product and brand analytics, 15 configurable industry templates, loss incidents, root cause, CAPA and management dashboards.

Both share one database, one auth system, one approval engine and one master-data set. Never build them as two apps and never duplicate a master.

---

## 0. How to work (always follow)

1. **Phase discipline.** Work through Section 12 in order. At the start of a session, read `docs/PROGRESS.md` to find the current phase. At the end of each phase: run tests, run Pint and PHPStan, update `docs/PROGRESS.md`, summarise, and stop for review.
2. **Verify, never claim.** Don't say a feature or test works until you have run it. Paste the real test output.
3. **Every deliverable includes:** exact file paths, migrations, seeders, factories, routes, policies, Form Requests, Blade views, tests, `.env` variable names (never real secrets), and the commands to run.
4. **Before coding a phase,** list your assumptions. If a decision is irreversible and ambiguous (schema shape, money rules), ask first.
5. **Keep docs current.** Update `docs/DATA_DICTIONARY.md` with every table and column you add or change, and `docs/FORMULAS.md` with every financial or KPI formula.
6. Seed realistic demo data in development only, and label demo values clearly in the UI (a "Demo data" badge).

---

## 1. Tech stack (fixed)

| Area | Choice |
|---|---|
| Framework | **Laravel 13.x**, **PHP 8.3+**. Laravel 11 is past end of life, so don't use it. Check package compatibility before adding a package. |
| UI | **Blade only**, with a **Bootstrap 5 Metronic 8-inspired** theme. Alpine.js for small interactions. **No React, Vue SPA, Next.js, Livewire SPA or separate frontend project.** Use licensed Metronic assets only if the client owns a licence; otherwise build lookalike Blade components. |
| Charts | Chart.js. Data comes as JSON from Laravel controllers/services (`@json($chartData)` or `/analytics/*?format=json`). |
| Calendar | FullCalendar (JS) for follow-ups. |
| DB | MySQL 8, InnoDB, utf8mb4. Migrations, Eloquent, tenant scopes, indexes. |
| Mobile API | `/api/v1` with **Sanctum** — JSON API for the field-sales mobile app (not a web frontend). |
| Packages | spatie/laravel-permission, spatie/laravel-activitylog, maatwebsite/excel (fallback openspout), barryvdh/laravel-dompdf, spatie/laravel-medialibrary (or native secure attachments). |
| Async | Queues (database or Redis) + Scheduler for imports, exports, notifications, KPI snapshots, API lead sync, reminders, escalation. Retries + failed-job monitoring. |
| Quality | Pest, Laravel Pint, Larastan level 6+. |

Conventions: thin controllers, Form Requests, Policies, backed Enums, Service/Action classes, Events & Listeners, Notifications, API Resources, Blade components, PHP attributes where Laravel 13 supports them.

---

## 2. Architecture rules

1. **Modular folders:** `app/Modules/{Shared,Masters,CRM,Sales,Inventory,Procurement,Loss,Industry,Finance,Analytics,Reports}/{Models,Actions,Services,Http/Controllers,Http/Requests,Policies,Enums,Events,Listeners,Jobs,Notifications}`. Views in `resources/views/{module}/...`; routes grouped and named per module in `routes/web.php` and `routes/api.php`.
2. **Tenancy:** Company (tenant) → Location (site/plant/branch/warehouse). Every business table has `company_id` and, where relevant, `location_id`. A global `CompanyScope` and a location-access scope apply to every query **and every queued job** (jobs carry company context). Never rely on hiding things in the UI.
3. **Common columns:** `id, company_id, code (unique per company), status, approval_status (draft/pending/approved/rejected), approved_by, approved_at, remarks, created_by, updated_by, timestamps, deleted_at`.
4. **Number series** per company + location + document type + financial year (April–March), e.g. `QT/AHD/26-27/00001`. Generated inside a transaction with `lockForUpdate`.
5. **Shared approval engine:** `Approvable` trait + `approvals` + `workflow_events`. Levels configurable per document type and per industry template. Fires `DocumentApproved` / `DocumentRejected`; overdue approvals escalate via the scheduler.
6. **Polymorphic shared tables:** `comments`, `attachments` (MIME/size validated, private disk, signed URLs), `activity_log` (before/after values).
7. **Money & quantity:** `decimal(18,2)` amounts, `decimal(18,4)` unit cost/rate, `decimal(18,3)` quantity, `currency` code on every money row. Never floats.
8. **Indian defaults:** GSTIN, PAN, state code, HSN/SAC, INR, DD-MM-YYYY display, `Asia/Kolkata`. Timezone and base currency configurable per company.
9. **Approved-only rule:** transactions may only reference *approved* items, ledgers, brands and rates — enforced by an `approved()` scope in queries **and** in validation rules.
10. **Configuration over code for industries:** one shared set of models/services; industry behaviour comes from template configuration (Section 6.1). Never create 15 copies of a module.

---

## 3. Unified data model

| Loss Intelligence term | Single entity in this app |
|---|---|
| companies / tenants | `companies` |
| sites / plants / branches | `locations` (type: plant, branch, warehouse, store, site; geo-coordinates) |
| departments | `departments` |
| products / SKU | `items` (FormEzy Item Master) with `brand_id`, `item_group_id`, cost/price fields, `tracking` (none/batch/lot/serial), `has_expiry` |
| categories | `item_groups` (hierarchical) |
| brands | `brands` (approval required) |
| units of measure | `units` + `item_uom_conversions` |
| customers / suppliers | `ledgers` (`ledger_type`) |
| sales_orders, invoices | `sales_orders`, `sales_invoices` (+ lines) |
| returns / credit notes | `sales_returns` (+ lines), linked to invoice |
| approvals / audit | shared approval engine + activity log |

**Revenue truth:** analytics read from **approved sales invoices minus approved returns/credit notes**. Each invoice line stores a `cost_amount` snapshot at posting (standard or moving-average cost per company setting), so gross profit by product/brand is reproducible.

---

## 4. Roles (seed these)

Super Admin, Company Admin, Purchase User, Inventory User, Accounts User, Operations User (view + comment only on Item Master), Sales Manager, BDE / Field Salesperson, Tele-caller, Approver, Quality/Loss Manager, Investigator, CAPA Owner, Finance Controller, Plant/Site Head, Management (dashboards read-only).

Permission matrix (view, create, edit, delete, approve, export, import) per form/module, editable in Settings. Data access also limited to the user's assigned companies and locations.

---

## 5. FormEzy modules (Masters, CRM, Sales)

### 5.1 Masters
- **Company:** name, legal name, GSTIN, PAN, CIN, address, state, pin code, contacts, logo, FY start, base currency, timezone, enabled industry template(s).
- **Chart of Accounts:** account groups of unlimited depth (`parent_id`); seed standard Indian groups; tree UI.
- **Ledger Master (centralised):** one `ledgers` table, `ledger_type` = customer / supplier / bank / cash / expense / general; multiple addresses and contacts.
  - Customer: code, name, group, addresses, **B2B/B2C** (GSTIN required for B2B), contacts, **credit days & limit**, **payment preference**.
  - Supplier: onboarded by **Purchase** only, **lead time (days)**, contacts, credit terms, payment preference.
  - Bank/cash/expense/other: code, name, group, contact/address where applicable; bank also account no., IFSC, branch.
  - Usable only after approval.
- **Item Group, Brand, Location, Department, Designation, Unit:** standard masters; locations have address and geo-coordinates.
- **Item Master — 3-stage department workflow:**
  1. **Purchase:** name, item group, brand, base UOM, **multi-UOM conversions**, **PO tolerance %**, HSN, GST rate, standard cost.
  2. **Inventory:** **item code**, **alias names**, **per-location min / max / reorder** (min ≤ reorder ≤ max), tracking type, expiry flag.
  3. **Accounts / Operations:** forwarded for approval. **Operations can view and comment but never edit (403).** Accounts approves/rejects.
  - Flow: `purchase_draft → inventory_pending → approval_pending → approved | rejected` (rejection returns to the right stage with a comment).
- **Rate Master:** item + location + UOM + `effective_from/to` (+ optional price list); approval required. `RateService::getRate(item, location, uom, date)` returns the latest approved, in-effect rate.
- **CRM masters:** State → District → Block → Pin Code (Excel import of **Tent Studio** data), Lead Source, Follow-up Outcome (configurable), Lead Stage, Lost Reason.

### 5.2 CRM-Marketing
- **Sales Team & Targets:** hierarchy; targets per user/team per month/quarter by value or qty, optionally by item group/location; achievement = approved SO value.
- **Route Plan & Attendance:** daily route per salesperson (ordered stops, coordinates, geofence radius from settings). **No route plan → attendance blocked.** GPS attendance via mobile app.
- **New Lead** — fields: no., date, source, name, contact, mobile (duplicate warning), email, address, state, district, block, pin code, requirement, expected value, assignee, stage, next follow-up, geo-coordinates, remarks. Channels:
  1. **Field app:** follows route plan; **server-side geofence** — outside route → 422 and a `geofence_violations` row.
  2. **IndiaMART / Just Dial:** scheduled pull jobs, keys encrypted per company, de-dup on platform ID + mobile, raw payload in `lead_api_logs`; leads flagged **incomplete** until filled.
  3. **Manual:** reference, website, social, tele-caller; pin code auto-fills state/district/block.
  4. **Excel import:** template, column mapping, preview, per-row errors + downloadable error file, queued.
  5. **Follow-ups:** type, date/time, outcome tag, notes, next date; calendar per user/team; reminders; overdue highlighting.
  - **Lead transfer** (single/bulk) with history and reason. Qualified lead → Deal or direct SO; quotation optional.
- **Existing Lead:** approved customer + extra item lines → qualified → **Deal with items**; follow-ups include store-visit status; once approved → quotation.
- **Deal:** items, expected close date, probability, value, owner; approval required.
- **Quotation:** from approved deal; **rates auto from Rate Master** (override needs permission, logged); discount, GST (CGST/SGST vs IGST by place of supply), validity, terms, revisions, PDF/email, follow-ups; approved → SO.
- **Sales Order:** from deal, quotation, or direct for regular customers; auto rate pickup; customer PO details; credit-limit check (warn/block); OA PDF. **On approval, exactly one linked Sales Invoice is auto-generated** (own number series; tables allow partial invoicing later).
- **Sales Returns / Credit Notes & discounts** — feed net revenue.

### 5.3 Inventory & Procurement (minimum for loss analytics)
- `stock_movements` ledger (receipt, issue, transfer, adjustment, scrap, return) with location, batch/lot/serial, qty, unit cost, source document.
- `batches` with mfg/expiry dates; scheduled **expiry monitoring** raises alerts and can create a loss incident.
- Purchase Order + GRN; GRN qty ≤ PO qty × (1 + tolerance %). Supplier lead time used in reorder suggestions.

---

## 6. Loss Intelligence modules

### 6.1 Industry template engine
Tables: `industry_templates`, `industry_settings`, `kpi_definitions`, `loss_categories`, `template_fields`, `workflow_states`, `workflow_transitions`, `template_reports` (see DATA_DICTIONARY).

- Dynamic incident field values live in `loss_incidents.custom_fields` (JSON), validated server-side from `template_fields`.
- Templates are versioned; incidents store the template version they were created under.
- `IndustryTemplateService` resolves base template + company override.
- Seed all 15 from `database/seeders/industries/*.php`:

| Industry | KPIs | Loss categories |
|---|---|---|
| Automotive | OEE/line availability, downtime, defects, warranty exposure | line stoppage, component defect, warranty claim |
| Textile & Apparel | fabric utilisation, dye rejection, cutting waste, order adherence | fabric waste, dye variation, cutting loss, order delay |
| Pharma & Medical Devices | batch release, deviations, expiry exposure, CAPA overdue | batch deviation, expiry, contamination, validation issue |
| Food & Beverage | yield, expiry exposure, fill-weight variance, cold-chain alerts | spoilage, shelf-life loss, fill-weight variation, cold-chain failure |
| Chemicals & Petrochemicals | process yield, off-spec batches, leak exposure, energy/tonne | yield loss, leak, off-spec batch, shutdown |
| Metals, Mining & Cement | recovery rate, fuel/tonne, kiln availability, material variance | material recovery, furnace loss, kiln fuel/energy |
| Electronics & Electrical | first-pass yield, shortages, test failure, rework cost | component shortage, assembly defect, test failure |
| Energy & Utilities | generation variance, asset availability, distribution loss | generation variance, asset degradation, distribution loss |
| Construction & Infrastructure | cost/schedule variance, material waste, rework exposure | material waste, rework, delay, contractor overrun |
| Logistics & Warehousing | on-time delivery, damage rate, storage utilisation, cost/shipment | damaged goods, route inefficiency, delivery/storage loss |
| Agriculture | yield/acre, input cost, equipment utilisation, storage loss | crop wastage, input over-consumption, equipment idle, storage spoilage |
| Retail & Wholesale | shrinkage, expiry, gross margin, markdown loss | shrinkage, expired stock, markdown, margin erosion |
| Healthcare & Hospitals | bed/OT utilisation, expiry, billing leakage, turnaround | resource under-utilisation, inventory expiry, billing leakage, delay |
| Hospitality | occupancy, food waste, energy/room, service recovery | food waste, room under-utilisation, energy cost, service recovery |
| IT & Professional Services | billable utilisation, project margin, rework, SLA compliance | project overrun, unbilled hours, rework, SLA breach |

### 6.2 Loss incident workflow
1. Create manually or from an authorised source (stock adjustment/scrap, expired batch, sales return, GRN rejection, import); store `source_type` / `source_id`.
2. Capture company, site, template + version, department, process, item/asset/batch/order, category, date/time, qty, unit, currency, severity, description, reporter, custom fields.
3. `LossCalculationService` creates `loss_impact_lines` (impact type, qty, unit cost, currency, amount, formula key + version, assumptions, validation state). Recalculations versioned and audited.
4. **Containment:** safety/quality/compliance severity triggers immediate escalation.
5. Assign investigator + accountable owner. **RCA** via 5 Whys or configurable cause categories (fishbone 6M), contributing factors, evidence.
6. **CAPA:** corrective/preventive, owner, due date, expected benefit, approver, status, verification evidence, verified result; overdue escalation.
7. Default states: Draft → Submitted → Triage → Investigation → Action Required → Verification → Closed, plus Rejected, On Hold, Reopened (configurable per template). `LossWorkflowService` enforces transitions and writes `workflow_events`.
8. **Four values tracked separately, never summed silently:** actual loss, estimated exposure, recoverable opportunity, verified realised savings.
9. Full audit trail of field changes, approvals, recalculations, attachments.

### 6.3 Finance & costing
Cost centres, budgets (period × cost centre × category), item costing (standard/moving average), profitability by product/brand/industry/site, budget-vs-actual variance.

### 6.4 Analytics
All formulas defined in `docs/FORMULAS.md`; each has a unit test with hand-calculated fixtures.

### 6.5 Dashboards (Blade + Chart.js, Metronic-style)
- **Layout:** dark navy sidebar, light canvas, white cards, subtle borders, compact type, restrained blue/green/red/orange status colours, responsive.
- **Sidebar:** Dashboard, Industries, CRM, Sales & Revenue, Loss Management, Products & Brands, Inventory, Procurement, Production/Operations, Quality, Maintenance, Finance, Projects, HR & Workforce, Reports, Analytics, Settings (unbuilt sections show "Coming soon").
- **Header:** global search, company/site selector, date range, notifications, help, user menu.
- **Executive dashboard:** KPI cards (Total Revenue, Total Losses, Total Products, Top Performing Brand, Active Industry Templates — with period, comparison, formula tooltip); revenue-vs-loss trend (vs prior period & target); revenue by industry; losses by industry (drill: category → location → incident); top products; top loss categories (Pareto); brand profitability (rankable); 15 industry cards; incidents table; quick actions; recent activity.
- **Sales dashboard:** leads by stage/source, funnel, today's/overdue follow-ups, target vs achievement, top salespeople.
- **Industry dashboard:** template KPIs with threshold colours.
- Filters (date, company, site, industry, product, brand) persist in the query string through drill-down; every number traceable to transactions/incidents/actions.
- `kpi_snapshots` built by a scheduled job; `source_freshness` shown on cards.

---

## 7. Routes (minimum)

**Web:** `GET /dashboard`, `/dashboard/sales`, `/industries`, `/industries/{industry}/dashboard`; resources for every master; `/leads`, `/leads/import`, `/leads/{lead}/transfer`, `/follow-ups/calendar`, `/deals`, `/quotations`, `/sales-orders`, `/sales-invoices`, `/sales-returns`; `GET /analytics/{revenue-loss|industry-revenue|industry-losses|top-products|loss-categories|brand-profitability}` (`?format=json` for charts); resource `/loss-incidents`, `POST /loss-incidents/{incident}/submit|transition|root-causes|actions`; `POST /corrective-actions/{action}/verify`; `/approvals/inbox`; `GET /reports/{report}`; `/settings/*`.

**API `/api/v1`** (Sanctum, rate-limited, versioned, Scribe docs): login, logout, profile, today's route plan, attendance, leads (geofence check), follow-ups + calendar, deal create, quotation view, master lookups with `updated_since`. One consistent JSON envelope.

---

## 8. Key services (unit-tested)
`NumberSeriesService`, `ApprovalService`, `RateService`, `GeofenceService` (haversine), `LeadImportService`, `IndiaMartSyncJob`, `JustDialSyncJob`, `ConversionService` (lead → deal → quotation → SO → invoice in one transaction), `GstCalculator`, `CreditLimitService`, `StockService`, `IndustryTemplateService`, `LossCalculationService`, `LossWorkflowService`, `RevenueAnalyticsService`, `DashboardAnalyticsService`, `KpiSnapshotService`.

---

## 9. Reports
Filters on all; Excel/PDF export via queued `report_jobs`; authorisation applied to every row.
Lead register, source analysis, follow-up & outcome analysis, lead transfer history, deal pipeline, quotation conversion, SO register, pending SO, attendance & route adherence, geofence violations, target vs achievement, item stock parameters, rate list by branch, pending approvals, revenue by product/brand/industry/site, brand profitability, loss register, loss Pareto, loss by industry/site, CAPA status & overdue, realised savings, budget vs actual, expiry exposure, stock movement.

---

## 10. Security & quality
- Policies on every action; tenant scope on every query and job; CSRF; Blade escaping (never `{!! !!}` on user data); secure uploads; least privilege.
- Transactions for multi-table writes; indexes on FKs, `code`, `mobile`, `approval_status`, `status`, filter dates.
- Secrets only in `.env` (`INDIAMART_*`, `JUSTDIAL_*`, `MAIL_*` …); API keys encrypted in DB; HTTPS, secure cookies, documented backups.
- Regulated industries: **never claim regulatory compliance** (GMP, 21 CFR Part 11, FSSAI, NABH) because fields/audit logs exist — note that qualified compliance review is required.

---

## 11. Settings (per company)
Approval levels, geofence radius, credit-limit behaviour, number series, costing method, enabled templates & overrides, KPI thresholds, escalation SLAs, IndiaMART/Just Dial keys, notification channels.

---

## 12. Build phases (in order; test, update PROGRESS.md, stop after each)
1. **Foundation** — install, auth, Metronic-style layout, roles/permissions, tenancy, number series, approval engine, comments, attachments, audit log, docs.
2. **Core masters** — Company, CoA, Location, Department, Designation, Unit, Item Group, Brand, geography + Tent Studio import.
3. **Ledger master** with approvals.
4. **Item master** — 3-stage workflow, multi-UOM, aliases, location stock params, costing/tracking, Operations comment-only.
5. **Rate master** + `RateService`.
6. **Industry template engine** — tables, service, config UI, 15 seeds.
7. **Sales team, targets, route plan, attendance.**
8. **New lead** — manual, Excel import, follow-ups, calendar, reminders, transfer.
9. **Mobile API + geofencing.**
10. **IndiaMART / Just Dial sync.**
11. **Existing lead & deal.**
12. **Quotation** — rates, GST, revisions, PDF/email.
13. **Sales order → auto invoice**; returns/credit notes/discounts; credit check; OA PDF.
14. **Inventory & procurement** — stock movements, batch/lot/serial, expiry, PO/GRN tolerance.
15. **Loss management** — incidents, impact lines, workflow, RCA, CAPA, escalation.
16. **Finance & costing** — cost centres, budgets, profitability, variance.
17. **Analytics & dashboards** — executive, sales, industry; KPI snapshots; drill-downs.
18. **Industry pilots** (Automotive, Textile, Pharma/MD, Food & Beverage) end to end, then the other 11.
19. **Reports & exports.**
20. **Production readiness** — full tests, PHPStan, Pint, performance/security checks, README (setup, migrate, seed, queue worker, scheduler cron, tests, deploy, backup, rollback).

---

## 13. Acceptance criteria (each needs a Pest test)
- [ ] Unapproved items, ledgers, rates or brands never appear in dropdowns, validation or API.
- [ ] Operations user can comment on an item but gets 403 on edit.
- [ ] Item can't reach approval before Purchase and Inventory stages are complete.
- [ ] Lead outside route geofence → 422 + logged; attendance blocked without route plan.
- [ ] IndiaMART/Just Dial re-sync creates no duplicates; synced leads stay incomplete until filled.
- [ ] Excel import saves valid rows and reports per-row errors.
- [ ] Lead transfer history kept.
- [ ] Lead → deal → (optional) quotation → SO carries every item line.
- [ ] Quotation/SO rates equal the approved, in-effect Rate Master entry for the branch.
- [ ] Approving an SO creates exactly one linked invoice.
- [ ] Net revenue, gross profit, margin % (incl. zero revenue) and loss totals match hand-calculated fixtures.
- [ ] Actual loss, exposure, opportunity, realised savings never combined; no impact line double counted.
- [ ] Loss workflow rejects illegal transitions; every transition, approval and recalculation audited.
- [ ] Overdue CAPA and approvals escalate.
- [ ] All 15 templates load, validate dynamic fields and render dashboards.
- [ ] No cross-company or unassigned-site data visible, including exports and queued jobs.
- [ ] Dashboard KPIs reconcile with report totals for the same filters.
- [ ] Entire UI is Blade inside this one Laravel project.

---

## 14. Open decisions (record assumption in PROGRESS.md at Phase 1)
1. Approval levels — single level default, configurable.
2. Item approver — Accounts by default (Operations comment-only).
3. IndiaMART/Just Dial — pull API vs webhook; credentials.
4. Geofence radius (default 500 m); manager override?
5. Sales Invoice scope — document + analytics now; GL postings later.
6. Costing method — moving average default.
7. Mobile client — Flutter/native on `/api/v1`, or responsive Blade PWA.
8. Metronic licence — if none, lookalike Bootstrap components.
