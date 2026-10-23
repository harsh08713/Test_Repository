# Loss Intelligence ERP — gap analysis and phase plan

Sources analysed (2026-10-18): the new `CLAUDE (1).md` brief, `DATA_DICTIONARY.md`, `FORMULAS.md` and
`Loss_Intelligence_ERP_Laravel_Only_Development_Specification.docx`. This file compares them with what the repository
already holds and says what to build, in what order, and what is deliberately **not** repeated.

The repository's own rules still win where they differ ([CLAUDE.md](../CLAUDE.md): Livewire 4 screens, Tailwind, an explicit
Super Admin, `app/Modules/*`). The new brief says "Blade only / Bootstrap / Laravel 11"; the app is Laravel 13 with Livewire 4 and
Tailwind, and all 15 finished phases are built that way, so **the new modules follow the existing stack**. The brief's real
requirement — one Laravel application, server-rendered, no separate frontend — is met.

## 1. Already built — skipped, not rebuilt

| New-brief item | Where it already is |
|---|---|
| Foundation: auth, tenancy (company → branch), roles/permission matrix, number series, approval engine, comments, attachments-ready morphs, append-only audit | Phase 1 |
| Company, Chart of Accounts, Location (`locations`), Department, Designation, Unit, Item Group, geography + Tent Studio import | Phase 2 |
| Ledger master (customer / supplier / bank…), `LedgerLookup` (approved only) | Phase 3 |
| Item master: 3-stage workflow, multi-UOM, aliases, per-branch stock parameters, Operations comment-only | Phase 4 |
| Rate master + `RateService`, price lists | Phase 5 |
| Sales team, targets, route plan, attendance, geofence | Phase 6 |
| Leads (manual / Excel / follow-ups / calendar / transfer), mobile API, IndiaMART / Just Dial | Phases 7–9 |
| Deals, quotations (GST, revisions, PDF), sales orders → exactly one invoice | Phases 10–12 |
| Sales dashboard, 16 reports with queued exports, Customer 360, tasks, SLA | Phases 13–14 |
| Campaigns and automation | Phase 15 |

Mapping of the new brief's phase numbers to the repository: new 1–5 = repo 1–5, new 7–13 = repo 6–12. **New phase 6
(industry template engine) was never built.**

## 2. Missing — what the new brief adds

| Area | Missing pieces | Plan phase |
|---|---|---|
| Brand master | `brands`; `items.brand_id` | L1 (master) / L2 (item link) |
| Industry template engine | templates, versions, per-company enablement and overrides, KPI definitions, loss categories, dynamic fields, workflow states, 15 seeds | **L1** |
| Sales returns / credit notes, discounts | `sales_returns` + lines (feed net revenue); a cost snapshot on invoice lines | L2 |
| Inventory and procurement | `stock_movements`, `batches`, `serials`, `stock_balances`, expiry monitoring, PO + GRN with tolerance | L3 |
| Loss management | `loss_incidents`, `loss_impact_lines`, RCA, CAPA, workflow, escalation, `LossCalculationService`, `LossWorkflowService` | L4 |
| Finance and costing | cost centres, budgets, `item_cost_history`, moving-average costing, profitability, variance | L5 |
| Analytics and executive dashboard | `RevenueAnalyticsService`, `DashboardAnalyticsService`, `kpi_snapshots`, six `/analytics/*` JSON endpoints, 15 industry cards | L6 |
| Industry pilots | Automotive, Textile, Pharma/MD, Food & Beverage end to end, then the other 11 | L7 |
| Loss reports and exports | loss register, Pareto, CAPA overdue, realised savings, budget vs actual, expiry exposure, stock movement | L8 (on the Phase 13 report framework) |

## 3. Conflicts and decisions (recorded; change on the owner's word)

1. **Brand approval.** The brief wants approved-only brands. Built first as a plain master with active/inactive (so a brand
   can be used the day it is added); making it an approval document later is a model + policy change. *Open for the owner.*
2. **Tenancy vocabulary.** The brief's `locations` (site/plant/branch/warehouse) is the repository's existing `locations`
   ("branches"). Loss incidents, stock and budgets reuse it. No second table.
3. **Industry templates are global, enablement is per company.** The 15 templates (and their KPIs, categories, fields, workflow)
   are system data shared by all companies; `company_industry_templates` enables one for a company and pins a version;
   `industry_settings` holds that company's overrides (KPI thresholds). A company never edits the shared rows.
4. **Template versions.** Child rows carry the template version they belong to. Publishing a new version copies the current
   rows forward; a company stays on its pinned version until it moves; an incident will record the version it was created under.
5. **Money.** The brief says `decimal(18,2)`; the repository's convention is `amount()` = `decimal(15,2)` and `quantity()` =
   `decimal(15,3)`. New tables follow the repository (a 15-digit paise-exact amount is ample); unit cost / rates stay
   `decimal(18,4)` as in the Rate master. `DATA_DICTIONARY.md` is the brief's *starting* schema — [schema.md](schema.md) is the
   truth for built tables.
6. **Permissions.** Roles in the new brief that do not exist yet (Quality/Loss Manager, Investigator, CAPA Owner, Finance
   Controller, Plant/Site Head, Management) are added in the phase that needs them (L4–L6), not up front.
7. **Revenue truth.** Analytics read approved sales invoices minus approved returns (FORMULAS §2). Phase 12 invoices exist;
   they carry no cost snapshot yet — L2 adds it, so gross profit is reproducible.
8. **Regulated industries.** Pharma, food, healthcare templates carry fields and audit only; nothing claims GMP / FSSAI /
   NABH / 21 CFR Part 11 compliance.

## 4. Phase plan

| # | Phase | State |
|---|---|---|
| L1 | Brand master + industry template engine (this document's first delivery) | **built — awaiting owner review** |
| L2 | Brand on items, sales returns / credit notes, invoice cost snapshot, net-revenue figures ([returns-and-costs.md](returns-and-costs.md)) | **built — awaiting owner review** |
| L3 | Inventory and procurement ([inventory-and-procurement.md](inventory-and-procurement.md)) | **built — awaiting owner review** |
| L4 | Loss management ([loss-management.md](loss-management.md)) | **built — awaiting owner review** |
| L5 | Finance and costing ([finance.md](finance.md)) | **built — awaiting owner review** |
| L6 | Analytics, executive dashboard, KPI snapshots ([analytics.md](analytics.md)) | **built — awaiting owner review** |
| L7 | Industry pilots, then the remaining templates | **waits for the owner** (which industries to pilot, and their real thresholds and workflows) |
| L8 | Loss reports and exports; hardening ([reports.md](reports.md)) | **built — awaiting owner review** |

Each phase ends with a report and a stop for review, as in [CLAUDE.md](../CLAUDE.md).
