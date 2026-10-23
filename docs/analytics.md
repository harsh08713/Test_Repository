# Executive dashboard, analytics and KPI snapshots (L6)

`app/Modules/Analytics`. Read-only: it owns no business rule and works nothing out that another module already works out.

## Screens

| Screen | Address | Who |
|---|---|---|
| Executive dashboard | `/executive` | `analytics.view`; each section also needs the right to its figures |
| Six analytics pages | `/analytics/{revenue-loss, industry-revenue, industry-losses, top-products, loss-categories, brand-profitability}` | `analytics.view` + `profitability.view` (money) or `loss_incident.view` (loss); *Revenue vs loss* needs either and hides the series the person may not see |
| Industry dashboard | `/industries/{code}/dashboard` | `analytics.view` + `industry_template.view` |
| Header search | every page | results only for what the person may open |

The same data is served as JSON by adding `?format=json` to a page address (`AnswerAnalyticsJson`): same permission checks (403 / 404), `Cache-Control: no-store, private`.

## Filter

`AnalyticsFilter`: period preset (this/last month, quarter, financial year, last 12 months, custom), industry, branch, category. It lives in the query string and is **always re-read and re-checked**: an unknown preset, a reversed or over-5-year custom range or a non-numeric id falls back safely. Every query still runs through the tenant and branch scopes, so a branch the person does not work in shows nothing. The comparison period is the one of the same length ending the day before.

## Figures

All dashboard figures come from `DashboardAnalytics`, built on `Profitability`, `NetRevenueCalculator` and `LossFigures` (formulas in [FORMULAS.md](../FORMULAS.md)); never add a second calculation.

* **Revenue** is net, before tax; **loss** is validated *actual* loss only (exposure and opportunity are never added in). The change on a card is `(now − before) ÷ before`, and **blank when there was nothing before** (never infinity or 100 %).
* **Revenue by industry** splits a sale by the **industry of its item** (`items.industry_template_id`, set on the item form from the industries the company switched on). Items with none are "Unassigned". Losses are by the incident's industry.
* The trend buckets by day (≤ 45 days), week (≤ 183) or month; the previous period is drawn dashed, aligned bucket by bucket.
* Top loss categories carry the share, the running share (Pareto) and **recurrence** (same category, branch and item in the 90 days before).

* **Leakage and savings cards** (`DashboardAnalytics::leakage`), each only for who holds the right: *Radar findings waiting* and their *estimate* (open findings now, for the branch filter — **an estimate, never a loss**, labelled so and not added to any loss figure; `radar_finding.view`), and *Finance-verified savings* (net saving of measurements verified in the period, `LossFigures::financeVerifiedSavings`) with how many *recurred* (`savings_measurement.view`). A corrective action's own verified result is a different figure and is not on this page.

## KPI snapshots

`analytics:snapshots` (daily, 00:20) stores, per company and enabled industry template (at the version the company is pinned to), the value of every KPI **that can be measured**: `expiry_exposure`, `capa_overdue`, `open_count`, `gross_margin_pct`, `shrinkage_pct`. The rest (OEE, yield and the like need production data the system does not hold) show **"Not measured yet"**; a figure is never invented. Idempotent (`unique(company, location, KPI, period type, period start)`, upsert). A snapshot is company-wide, so it is shown beside the live value only to people who work in every branch.

## Charts and layout

Chart.js is loaded only on a page that has a chart (`window.analyticsChart`). The chart's wrapper is `wire:ignore` and keyed by a hash of its figures: without that Livewire's morph reset the canvas and the donuts and bars came back blank after a filter change (found only in the browser). The sidebar, header (search, company/branch switcher, notifications, user menu) are in `layouts/app.blade.php`; the menu is built per person by `NavigationMenu` (the same list the search uses). Production, Quality, Maintenance, Projects and HR are shown dimmed as "Soon" and are not links.

## Permissions

New form `analytics` (`view`, `export`): Company Admin / Super Admin, Accounts, Finance Controller (view, export), Quality/Loss Manager and Management (view). `export` is not used yet.

## Open for the owner

* The brief's image shows a revenue **target** line and a per-industry "% vs last year"; there are no revenue targets by industry, so the trend has no target and cards compare with the previous period instead.
* Revenue by industry depends on items being given an industry; existing items stay "Unassigned" until someone sets it.
* Snapshots are not shown as a history chart yet; they are stored for it.
* `/dashboard` is unchanged (sales dashboard); the executive view is a separate menu entry.
* The demo company is flagged with a "Demo data" badge (company setting `system.demo_data`).
