# Dashboards and reports (Phase 13)

The sales dashboard and sixteen baseline reports, with Excel and PDF exports that are made in the queue. Everything here is **read-only**: it
adds no business rule and changes no record. It reads the other modules through the same access classes their screens use, so a number on a
dashboard, a row on a report and a line in an export can never disagree about what a person is allowed to see.

## Who sees what

* **Opening a report needs two rights**: `report.view` *and* the right to see the records the report is about (`lead.view` for the lead
  register, `sales_order.view` for the order registers, `audit_log.view` for the audit report…). A report never shows more than its source
  screen. Operations hold `report.view` and `sales_order.view` but not `lead.view`, so they get the order reports and not the lead ones.
* **Which rows**: the report's query is restricted by `LeadAccess`, `DealAccess`, `QuotationAccess`, `SalesOrderAccess`, `FollowupAgenda` or
  `SalesScope` — never by a role name: a salesperson's own records, the teams a manager runs, everyone's for `sales.scope_all` (and, for
  orders, `sales_order.view_all`). The company and branch boundaries come from the models' global scopes.
* **Filters are requests, not facts**: a person, team, branch, source, choice or date that the user could not have picked from the lists is
  refused with a sentence (`ReportInput::resolve()`), on the screen and again when an export is made.
* **Exporting** needs `report.export` as well (Accounts, Sales Manager, administrators). A salesperson can read their reports but not
  download them.

## The reports

| Report | Heading | Needs | Notes |
|---|---|---|---|
| Lead register | Leads | `lead.view` | filters: dates, branch, person, team, source, stage |
| Lead source analysis | Leads | `lead.view` | leads by their stage *today*; won % per source |
| Follow-up register and outcomes | Leads | `followup.view` | choose *Overdue* / *Escalated*; outcome and done % |
| Lead transfer history | Leads | `lead.view` | from / to / by / why, from the append-only transfer log |
| Deal pipeline | Pipeline | `deal.view` | **forecast**: weighted value = expected value × probability |
| Quotation register and conversion | Pipeline | `quotation.view` | each quotation counted once however many revisions: sent → accepted → ordered |
| Sales order register | Sales | `sales_order.view` | **actual**: totals count approved, uncancelled orders only, net of tax |
| Pending sales orders | Sales | `sales_order.view` | drafts, waiting for approval, approved with no invoice; days open and past delivery |
| Target vs achievement | Sales | `target.view` | achievement from `TargetAchievement` (approved orders) |
| Attendance and route adherence | Field sales | `attendance.view` | one row per published plan day: checked in or not, distance, refused attempts |
| Geofence violation log | Field sales | `attendance.view` | the refused attempts as recorded |
| Item list with stock parameters | Masters | `item.view` | one row per item × branch parameter |
| Rate list by branch | Masters | `rate.view` | starts on the rates in force today |
| Pending approvals | Control | `report.view` | what waits for **you**; everything for `approval.override` |
| Integration sync failures and webhook status | Control | `integration.view` | never shows the buyer's payload |
| Audit activity | Control | `audit_log.view` | who did what to which kind of record; no old/new values |

**Not built yet** (the catalog says so on its page rather than faking them): *Campaign performance* (needs campaigns, Phase 15), *SLA
compliance* (Phase 14 — overdue follow-ups are already in the follow-up register) and *AI scoring and recommendation activity* (Phase 18).

## Actual results and forecasts

The brief asks that reports "clearly distinguish actual approved results from forecasts". Here:

* **Actual**: approved, uncancelled sales orders (net of tax), follow-ups done, check-ins made, target achievement. Marked *Actual* on the
  dashboard and in a note on the report.
* **Forecast**: open deals × probability (the pipeline, the dashboard's "open deals by probability / expected close date"). Marked *Forecast*;
  the column is called *Weighted value (forecast)*. A forecast is never added to an actual.
* Orders that are drafted or waiting for approval are listed but **never counted** as sales.

## The sales dashboard

On the home page for anyone who may see leads, deals, quotations, follow-ups or orders: choose a period (today … this year) and a branch.
Sections: headline figures; leads by stage and by source; the conversion funnel (by the stage each lead is at today); today's and overdue
follow-ups; target vs achievement; top salespeople and teams (a team's sales are its members' for the days they were on it); open deals by
probability and by expected close date; quotation conversion; branch performance. Campaign attribution and ROI says it arrives with
Phase 15. A section the person may not open is left out, not shown empty. Nothing is cached: each figure is read when the page is drawn.

## Exports

* Asked for from a report page: `ReportExporter::request()` checks the right (`report.export`), the filters and the size, writes a
  `report_exports` row and queues `GenerateReportExport`.
* The worker runs **as the person who asked** (`Auth::setUser` inside the company context), so their reach narrows the file as it narrows the
  screen; if they have lost the right by then the export fails with a sentence and no file is made.
* Excel (`.xlsx`): up to **50,000 rows** (a larger report asks the person to narrow the filters), read a page at a time so memory stays flat;
  numbers stay numbers and text is written as inert text, so `=HYPERLINK(...)` typed into a lead name cannot become a live formula.
  PDF: the first **1,000 rows**, landscape A4, with the filters, headline figures and the note printed on top.
* Files live on the private `local` disk for **7 days** (`reports:expire-exports`, daily 03:30), then the file goes and the row stays as
  "Expired".
* **Download** (`GET /reports/exports/{id}/download`) is authorised when it is asked for: it must be the requester's own export in the company
  they are working in (anything else is 404), not expired, and they must still be allowed to export that report (else 403).

## Settings and permissions

No new setting. No new permission: the existing `report.view` / `report.export` are used. Granted by this phase: `report.view` to the BDE and
Operations roles (Accounts and Sales Manager already had both; Approver has view).

## Known gaps and decisions to confirm

* **No cache.** Large companies may want per-dashboard caching with tenant-aware keys; it is left out until a measured need.
* **Exports run on whatever queue the installation has** (`QUEUE_CONNECTION=database` in production needs a worker; locally `sync`).
* **PDF is a print-out** of the first 1,000 rows, plain, no logo.
* **Lead-source "won"** means the lead's deal was won, not that an order exists; the order value per source is not shown.
* **Pending approvals** for a non-administrator is the Approvals inbox as a report; it shows what is waiting for them, not the whole company.
* **Route adherence** is "checked in on the day": attendance is one check-in a day against any stop, so per-stop visit tracking is Phase 17.
* **Target vs achievement** repeats the list screen's rule for which targets a person sees (`TargetList`).
* **No scheduled or e-mailed reports** and no saved filter sets.

## L8 — loss, finance, returns and HR reports

Nine more reports (28 in all), in the same framework, with the same rule: **the page, the Excel and the PDF read one `Report` class, which asks the place that works the figure out** — so they cannot differ from the
screens. Each is checked in `tests/Feature/Reports/LossAndFinanceReportsTest.php` against that place.

| Report | Heading | Needs | Figures come from |
|---|---|---|---|
| Loss incident register | Loss Intelligence | `report.view` + `loss_incident.view` | the incident and its lines; the summary is `LossFigures` |
| Loss by category (Pareto) | Loss Intelligence | `loss_incident.view` | `DashboardAnalytics::lossCategories` |
| Loss by industry | Loss Intelligence | `loss_incident.view` | `DashboardAnalytics::lossesByIndustry` |
| Corrective and preventive actions | Loss Intelligence | `corrective_action.view` | the actions; savings are only what a second person verified |
| Leakage radar findings | Loss Intelligence | `radar_finding.view` | the findings: **estimates the Radar proposed**, never added to a loss report; filter by status |
| Verified savings | Loss Intelligence | `savings_measurement.view` | the measurements; only a Finance-verified one is a saving, kept apart from the action's own verified result |
| Budget against actual loss | Finance | `budget.view` | `BudgetVsActual` |
| Profitability (product / brand / site / industry) | Finance | `profitability.view` | `Profitability` (agrees with `NetRevenueCalculator`) |
| Returns and credit notes | Sales | `sales_return.view` | `SalesOrderAccess::restrictReturns` |
| Employee register | HR & workforce | `employee.view` | `EmployeeAccess`; **no personal details** (no date of birth, mobile, address) |
| Leave register | HR & workforce | `leave.view` | `StaffReach`; **the reason is not shown** (it can be about someone's health) |

* **The four loss values stay apart**: the register shows actual loss, exposure and opportunity in separate columns and never a total of them; a duplicate or rejected incident shows blank figures and "Counted: No".
* **Service-backed reports** override `collection()` instead of `query()` (the Pareto, budget, profitability): the rows come already restricted from the service, and the page, the count, the Excel and the PDF all read them. They hold
  their rows in memory, so they are for grouped figures only; a register stays a query.
* **Exports**: HR Managers now hold `report.export`. One person may have at most **five** exports waiting or running at once (a job stuck for over an hour stops counting), so nobody can flood the queue.
* **Not built**: a recurring-incident report of its own (the Pareto's "Recurring" column covers it).

## L8b — stock and procurement reports, and scheduled reports

Five more reports (33 in all), each reading the place that works its figure out:

| Report | Heading | Needs | Figures come from |
|---|---|---|---|
| Expired and expiring stock | Inventory | `stock.view` | stock balances × batches; its summary shows `StockQuery::expiryExposure` for the same window (a test holds them equal) |
| Stock valuation | Inventory | `stock.view` | stock balances at the moving average, by item and branch (batches added together) |
| Open purchase order lines | Procurement | `purchase_order.view` | `PurchaseOrderService::received()` — what is received is worked out from receipts, never stored |
| Goods receipts | Procurement | `grn.view` | the receipts and their lines; "Posted" is blank until the stock has gone in |
| Reorder list | Procurement | `purchase_order.view` | `ReorderSuggestions::forCompany` |

**Scheduled reports** (`/reports/schedules`, `ReportScheduler`): a person with `report.export` can have a report e-mailed to them as Excel or PDF every day, week or month.

* It is made **as that person** (their access narrows it exactly as it narrows the screen) through the same `ReportExporter` as a button press, so the same caps, formats and 7-day keep apply; the file also appears under *My exports*.
* The **date range is relative** (yesterday, last 7 / 30 days, this month, last month, this / last financial year — 1 April) and is worked out on the day it runs; a report scheduled with fixed dates would say the same thing every week.
* `reports:send-scheduled` runs every 15 minutes. Each due schedule is **claimed by a conditional update of `next_run_at`**, so overlapping runs (or a second server) send one file, not several — proved with six real processes in
  `tests/Concurrency/ScheduledReportConcurrencyTest.php` (it fails when the claim is removed).
* A schedule whose owner has been switched off, has left the company or has lost the right to export **pauses itself and says why, once**; one that fails for another reason tells the owner and tries again at the next time. A mail that
  cannot be sent never undoes the export. At most **10** per person. A monthly schedule runs on day 1–28, so every month has it.
* A **Send now** button makes and sends one at once without moving the next scheduled time (the cap of five waiting exports still applies).
* **Not built:** sending to someone else or to an outside address (a file leaves the system only to the person whose access made it), a time zone other than the server's.
