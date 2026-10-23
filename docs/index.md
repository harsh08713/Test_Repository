# FormEzy ERP — documentation index

| Document | What it covers | Kept in sync |
|---|---|---|
| [../README.md](../README.md) | Setup, demo accounts, quality gates, deployment | by hand |
| [../CLAUDE.md](../CLAUDE.md) | Conventions, commands, gotchas, phase tracker for the next working session | by hand |
| [roadmap.md](roadmap.md) | The 20 phases, where each new CRM skill lands, owner decisions still open | by hand |
| [loss-intelligence.md](loss-intelligence.md) | The Loss Intelligence ERP brief compared with what exists: what was skipped as already built, what is missing, conflicts and decisions, the L1–L8 phase plan | by hand |
| [returns-and-costs.md](returns-and-costs.md) | Brand and standard cost on items, the cost copied onto an invoice line, sales returns and credit notes (how much can come back, approval, locking), and the net revenue / profit figures they feed, known gaps | by hand |
| [operations.md](operations.md) | What must be running, backups, proving a backup restores, restoring for real, the security review (verified vs advice vs not done), what is not done before launch | by hand |
| [maintenance.md](maintenance.md) | Assets, preventive schedules (generated once per due date), work orders (breakdown / preventive / corrective, tasks, spare parts from stock, hours, downtime), figures worked out not stored, safety, who may do what, what is not built | by hand |
| [quality.md](quality.md) | Inspection plans (approval, lock), inspections (batch hold and release, pass / fail worked out from the limits, reject or deviation), non-conformance reports (disposition, links to loss incidents and corrective actions), safety, who may do what, what is not built | by hand |
| [production.md](production.md) | Bills of materials (approval, lock, multi-level, loop check), production orders (plan, release, issue, output, rejects, complete, cancel), material-only costing, yield and variance, safety, who may do what, what is not built | by hand |
| [inventory-and-procurement.md](inventory-and-procurement.md) | Batch tracking, the stock ledger and balances (moving average, no negative stock), stock entries, purchase orders, goods receipts with the PO tolerance, posting to stock once, expiry watch, reorder suggestions, who may do what, known gaps | by hand |
| [loss-management.md](loss-management.md) | Loss incidents: reporting (and from a record), escalation, the workflow and its conditions, the four values kept apart, figures and their validation, root cause, CAPA, roles, known gaps | by hand |
| [radar.md](radar.md) | Profit Leakage Radar: daily sweep, three detectors, findings as proposals (report as incident / dismiss), settings, rules, open items, the owner answers for the 2026 brief | by hand |
| [savings.md](savings.md) | Verified Savings Ledger: a measurement per corrective action (baseline and window from the loss records, volume or day normalisation, cost), Finance verification (not the maker or the action owner), no double counting, recurrence, what is not built | by hand |
| [hr.md](hr.md) | HR & workforce: phases H1–H4, the employee file, history, notice and exit, access rules, what is not stored | by hand |
| [analytics.md](analytics.md) | Executive dashboard, the six analytics pages, KPI snapshots, header search, filters, what is not measured yet | by hand |
| [finance.md](finance.md) | Cost centres, monthly budgets (approved), budget against actual loss, the item cost history, profitability by product / brand / site, roles, known gaps | by hand |
| [FORMULAS.md](FORMULAS.md) | Every financial, loss and KPI formula, with a change log; implementation notes for what is built | by hand — each formula has a test |
| [DATA_DICTIONARY.md](DATA_DICTIONARY.md) | The new brief's starting schema for Loss Intelligence (not all built — [schema.md](schema.md) is the truth for built tables) | by hand |
| [schema.md](schema.md) | Phase 1–15 tables, relationships (ER diagrams), integrity rules, conventions | by hand — extend each phase |
| [integrations.md](integrations.md) | IndiaMART and Just Dial: how a delivery becomes a lead (or a note), what the providers require and limit, setting a connection up, the webhook contract and signing, failures and retries, privacy and retention, what is still unverified | by hand |
| [api.md](api.md) | The mobile API (`/api/v1`): signing in and devices, the envelope and every error code, the field day and the geofence, leads, follow-ups, reference data, syncing with `updated_since`, idempotency keys, logging, what is not built yet | by hand; a test keeps it in step with the code |
| [api/openapi.yaml](api/openapi.yaml) | The API as OpenAPI 3.0: every operation, parameter, request body and response | by hand; `tests/Feature/Api/ApiSpecTest.php` fails if it differs from the routes |
| [sales-orders.md](sales-orders.md) | Sales orders (from a quotation, a deal or directly), the credit check, approval that makes exactly one invoice (and how a retry or a race cannot make two), cancelling, targets, who sees what, settings, known gaps | by hand |
| [workspace.md](workspace.md) | Customer 360, tasks (list/board/calendar, repeats, reminders, assignment history) and SLAs (policies, business hours, the sweep, the breach log, escalation), settings, permissions, known gaps | by hand |
| [channels.md](channels.md) | WhatsApp (Meta Cloud API) and SMS (Twilio): off / sandbox / live, consent, delivery reports, STOP replies, the webhook, what is unverified and what the owner must arrange | by hand |
| [marketing.md](marketing.md) | Campaigns, message templates, consent and the frequency cap, the message log, attribution, the workflow builder, what is not built (WhatsApp/SMS until Phase 16) | by hand |
| [reports.md](reports.md) | Dashboards and reports: the sixteen baseline reports and the sales dashboard, who sees what, actual vs forecast, the queued Excel/PDF exports and how a download is authorised, known gaps | by hand |
| [quotations.md](quotations.md) | Quotations: revisions that never change once sent, pricing from the rate master and the audited override, GST by place of supply, approval, PDF and e-mail with the dispatch log, answers, expiry, the deal following its quotations, the conversion guard for orders, settings and permissions, known gaps | by hand |
| [deals.md](deals.md) | Inquiries from existing customers (a lead of a second kind, with approval), converting a qualified lead to a deal (once, every line across), a deal's lines, value, stages, approval and hand-over, who sees what, what is not built yet | by hand |
| [leads.md](leads.md) | Leads and follow-ups: fields, the duplicate warning, stages, who sees which lead, follow-ups and outcomes, the calendar, reminders and escalation, hand-overs, importing leads | by hand |
| [field-sales.md](field-sales.md) | Sales teams, targets and what counts as achieved, route plans, attendance ("no route plan, no attendance"), the geofence rules and the violation log | by hand |
| [rates.md](rates.md) | Rate master: what a rate is, overlap rules, how `RateService` picks one, overrides, using rates from other modules | by hand |
| [items.md](items.md) | Item master: the three stages, who may do what, unit conversions, routing, what is recorded, using items from other modules | by hand |
| [ledgers.md](ledgers.md) | Ledger master: types and fields, who may do what, approval and re-opening, the look-up, bank-data handling | by hand |
| [imports.md](imports.md) | Spreadsheet import framework: using it, mapping a file's own columns, the geography (Tent Studio) rules, adding an importer | by hand |
| [approvals.md](approvals.md) | Approval state machine, configuration guide, events, using it in a module | by hand |
| [permission-matrix.md](permission-matrix.md) | Every form × action, special permissions, default role grants | `php artisan formezy:docs` |
| [route-inventory.md](route-inventory.md) | Every application route with its handler and middleware | `php artisan formezy:docs` |

## Architecture in one paragraph

Code is organised by module under `app/Modules/{Shared,Masters,CRM,Sales}`; `Shared` is the platform every module
builds on. **Tenancy** (`TenantContext` + global scopes + write guards) isolates companies and branches at query,
model and policy level, and queued jobs carry their company through `RunsInCompanyContext`. **Approvals**
(`ApprovalService`) are a reusable state machine with sequential or parallel levels, a content fingerprint and
explicit, audited overrides. **Numbering** (`NumberSeriesService`) issues locked, gap-free
`PREFIX/BRANCH/FY/00001` numbers. **Permissions** are `{form}.{action}` with no implicit bypass. **Audit** is an
append-only, company-stamped trail with old/new values and no secrets. In the CRM module, **whose records a person may
touch** is always answered by `SalesScope` (their own, their teams', or everyone's by an explicit permission) and, for
leads, `LeadAccess`. The **mobile API** (`app/Modules/Api`) owns no business rule: it authenticates a device (Sanctum),
settles the company, throttles and de-duplicates (`Idempotency-Key`), and then calls the same services the screens use —
`FieldPositionGate` decides whether a position is inside today's geofence for both attendance and a lead saved from the field.

## What the tests cannot see

The Pest suite drives every screen on the server. It cannot see what only happens in a browser — a script error, a
button that does nothing, a calendar that draws the wrong dates. Phase 7 therefore also checked the screens in a real
(headless) browser: every page opened without script or HTTP errors, and the flows that depend on JavaScript were walked
through (the follow-up calendar with a drag, the lead form's live checks, a real spreadsheet upload, attendance from a
position). Two faults that had passed every test since Phase 2 were found and fixed this way; they are now guarded by
`tests/Feature/LivewireConventionsTest.php` and `tests/Feature/NoticesTest.php`. Repeat that check after any change to a
screen's JavaScript or to the layout.

## Regenerating the generated docs

```powershell
$env:Path = "C:\Users\Sagar\tools\php-8.3.35;" + $env:Path
php artisan formezy:docs
```
