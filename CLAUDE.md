# FormEzy ERP — working notes for the next session

Laravel 13 / PHP 8.3 / Livewire 4 / Tailwind 4 / MariaDB. Masters + CRM-Marketing, multi-company and multi-branch.

**The authoritative brief is [FormEzy_ERP_Master_AI_Coding_Prompt.md](FormEzy_ERP_Master_AI_Coding_Prompt.md)**
(20 phases; it supersedes the earlier 14-phase brief). Build **one phase at a time**: inspect, implement, run the gates,
report (scope, migrations, routes, permissions, tests actually run, gaps, commands, next phase), then stop for review.
Never report a check as passed unless it was run. Setup and deployment: [README.md](README.md). Docs index: [docs/index.md](docs/index.md).

## Phase tracker

| # | Phase | State |
|---|---|---|
| 1 | Foundation | **done** — aligned to the 20-phase brief; awaiting owner review |
| 2 | Masters (Company UI, Chart of Accounts, Location/Department/Designation/Unit, Item Group, State→PIN + Tent Studio import) | **done** — owner said to continue after the Phase 2 report |
| 3 | Ledger master | **done** — owner said to continue after the Phase 3 report |
| 4 | Item master (3-stage workflow) | **done** — owner said to continue after the Phase 4 report |
| 5 | Rate master + `RateService` | **done** — owner said to continue after the Phase 5 report |
| 6 | Sales team, targets, route plan, attendance | **done** — owner asked to carry on through Phases 7 and 8 |
| 7 | New lead: manual, Excel, follow-ups, calendar, transfer | **done** — owner asked to carry on into Phase 8 |
| 8 | Mobile API (Sanctum) + geofencing | **done** — owner said to continue after the Phase 8 report |
| 9 | IndiaMART / Just Dial | **done** — owner asked to continue into Phase 10; **not verified on a live account** |
| 10 | Existing lead & Deal | **done** — owner asked to continue into Phase 11 |
| 11 | Quotation | **done** — owner asked to continue into Phase 12 |
| 12 | Sales order → idempotent Sales Invoice | **done** — owner asked to continue into Phase 13 |
| 13 | Dashboards & reports | **done** — owner asked to continue into Phase 14 |
| 14 | Customer 360 + Tasks + SLA | **done** — its review gaps were closed together with Phase 15 |
| 15 | Campaigns + marketing automation | **built** — awaiting owner review |
| L1 | **Loss Intelligence** foundation: Brand master + industry template engine (15 templates) — see [docs/loss-intelligence.md](docs/loss-intelligence.md) | **built** — awaiting owner review (L2–L8 planned there; the CRM phases below are untouched) |
| L2 | Brand + standard cost on items, cost copied onto invoice lines, sales returns / credit notes, `NetRevenueCalculator` — [docs/returns-and-costs.md](docs/returns-and-costs.md) | **built** — awaiting owner review |
| L3 | Inventory & procurement: batch tracking, stock ledger + balances (moving average), stock entries, purchase orders, goods receipts with tolerance, posting once, expiry watch, reorder — [docs/inventory-and-procurement.md](docs/inventory-and-procurement.md) | **built** — awaiting owner review |
| L4 | Loss management: incidents under a template version, escalation, workflow with conditions + maker–checker, impact lines (4 values apart, superseded not edited, validated by another), RCA, CAPA, duplicates — [docs/loss-management.md](docs/loss-management.md) | **built** — awaiting owner review (L5 finance next) |
| L5 | Finance & costing: cost centres, approved monthly budgets, budget against actual loss, item cost history, profitability by product/brand/site — [docs/finance.md](docs/finance.md) | **built** — awaiting owner review |
| L6 | Executive dashboard, six analytics pages, KPI snapshots, header search, new layout — [docs/analytics.md](docs/analytics.md) | **built** — awaiting owner review |
| L8 | Loss, finance, returns and HR **reports and exports** (nine more, 28 in all), service-backed reports, an export cap per person — [docs/reports.md](docs/reports.md) | **built** — awaiting owner review (L7 industry pilots wait for the owner's choice of industries) |
| L8b | **Stock and procurement reports** (expiring stock, valuation, open PO lines, receipts, reorder list — 33 reports in all) and **scheduled, e-mailed reports** (relative date ranges, claimed once, pauses itself when the owner loses the right) — [docs/reports.md](docs/reports.md) | **built** — awaiting owner review |
| H5 | HR **onboarding and exit checklists**: a standard list per company, copied onto a person when they join or give notice, ticked by HR / the manager / the person — [docs/hr.md](docs/hr.md) | **built** — awaiting owner review |
| H1 | **HR & workforce** core: employee file, org chart, dated job history, notice/exit, papers, HR dashboard — [docs/hr.md](docs/hr.md) | **built** — awaiting owner review (H2 leave & attendance, H3 payroll, H4 recruitment/performance are planned there) |
| H2 | HR **leave and attendance** for all staff: leave ledger + accrual, requests decided by the manager, shifts, punches, day-close, corrections — [docs/hr.md](docs/hr.md) | **built** — awaiting owner review |
| H4 | HR **recruitment, performance and training**: openings to hire, review cycles with goals and reviews, training programs; notices for leave, corrections and these — [docs/hr.md](docs/hr.md) | **built** — awaiting owner review (H3 payroll still waits for the owner's statutory rules) |
| P1 | **Production**: bills of materials (approval master, multi-level), production orders (plan, release, issue material, report output and rejects, complete), material-only cost, yield and variance — [docs/production.md](docs/production.md) | **built** — awaiting owner review (Q1 Quality management is next) |
| Q1 | **Quality management**: inspection plans (approval master), inspections with batch hold / release, non-conformance reports linked to loss incidents and corrective actions — [docs/quality.md](docs/quality.md) | **built** — awaiting owner review |
| M1 | **Maintenance**: assets, preventive schedules, work orders (breakdown / preventive / corrective) with tasks, spare parts from stock, hours and downtime — [docs/maintenance.md](docs/maintenance.md) | **built** — awaiting owner review |
| R1 | **Profit Leakage Radar** (first slice of the "Industrial ERP 2026" brief): daily sweep proposes findings from expired stock, scrap and rejected receipts; a person reports or dismisses them; nothing automatic — [docs/radar.md](docs/radar.md) | **built** — awaiting owner review (verified-savings ledger, Tally integration + cash flow, AI copilot are next; pilot = plastics / packaging) |
| R2 | **Verified Savings Ledger**: a savings measurement per verified corrective action (actual loss before / after from the loss records, volume or day normalisation, cost), Finance verifies it (not the maker or the action owner), overlapping windows refused, recurrence flagged — [docs/savings.md](docs/savings.md) | **built** — awaiting owner review (Tally integration + cash flow and the AI copilot are next) |
| 16 | Omnichannel integrations | **first slice built** (WhatsApp + SMS sending, delivery reports, STOP replies, sandbox mode — [docs/channels.md](docs/channels.md); **not verified on a live account**); voice/telephony and website lead capture still to do |
| 17 | Advanced field sales + PWA sync | |
| 18 | AI assistance + forecasting | |
| 19 | Optional extensions (portal, documents/e-sign, tickets, commission) | |
| 20 | Hardening and launch (CI, restore test) | **first slice built** — security headers, `formezy:backup` / `formezy:restore-check` (run for real), a CI workflow (not yet run on GitHub), [docs/operations.md](docs/operations.md); **no load test, no off-site backup** yet |

Where each new CRM skill lands and which owner decisions are open: [docs/roadmap.md](docs/roadmap.md).

## Owner decisions on record

Confirmed by the owner on 2026-10-12 (Phase 1 sign-off) — treat as settled, do not revisit without being asked:

1. **Super Admin is an ordinary role holding every permission explicitly.** No `Gate::before` / `isSuperAdmin()` shortcut.
   Pending and approved documents are locked for Super Admin too. `approval.override` lets someone decide a level they
   were not designated for (recorded as an override) but never bypasses maker–checker. The demo admin therefore cannot approve
   what it submitted itself — use the seeded Approver or Accounts user for that.
2. `lead.transfer` is now `lead.assign`; the Approval Levels and Number Series screens need `setting.configure`.
   The Phase 1 migrations were edited in place (nothing deployed).
3. Demo accounts keep the password `password` (the seeder bypasses the policy); creating users through the app enforces
   the password policy.

## Open questions to the owner (defaults in force until answered)

1. **Tent Studio file layout** — we use our own template (State, District, Block, PIN Code, Locality); if theirs differs only the
   importer's column list changes.
2. **Who sees geography** — only Purchase, Accounts (added in Phase 3 for ledger addresses) and Company Admin hold `geography.view`,
   which guards the Geography *screens*. Address pickers (ledgers, leads) load states, districts and blocks on the server without
   it, so the Sales roles did not need the permission in Phase 7 and still cannot open the Geography master.
3. **Changing an approved record (decided by me in Phase 3, easy to change)** — approved records stay locked; a user who may edit
   them can **re-open** one (reason required, audited, back to draft, unusable until approved again) or switch it active/inactive.
   Ledgers use this now; Items and Rates will too. The alternative is a "pending revision" that keeps the approved version in use
   while the change is reviewed — more complete, much more work.
4. **Purchase creates suppliers only** (via `ledger.create` + no `ledger.manage_all_types`); Accounts/Company Admin handle every
   ledger type and are the only ones (besides Super Admin) who see full bank account numbers.
5. **Rates (Phase 5)** — one branch per rate (no company-wide fallback); live periods may not overlap, so a new price means ending
   the old rate first; customers are not linked to a price list yet (Phase 11 decided: chosen **per quotation**, default standard rates).
6. **Field sales (Phase 6)** — attendance is one check-in a day against *any* stop of the day's published plan; a reading less
   accurate than 150 m is refused; a manager's override (off by default) accepts a logged refusal within 7 days and never the
   manager's own; a manager's reach is their teams, everyone's is the explicit `sales.scope_all`; a person is on one team at a
   time; target achievement = approved sales orders, net of tax, cancellations out, returns in the period recorded
   ([docs/field-sales.md](docs/field-sales.md)).
7. **Leads (Phase 7)** — a new lead is *not* an approval document (`lead.approve` decides an inquiry from an existing customer, Phase 10);
   a duplicate mobile number on another open lead **warns and asks** rather than blocks; a typed-in or imported lead needs a
   mobile number; a lead's branch is fixed and its owner changes only through a hand-over; anyone with a *planned* follow-up on
   a lead may open it (not edit or hand it over) for as long as it is planned; only managers and administrators import and hand
   over; reminders go out 30 minutes before and the team's manager is told once when a follow-up is 24 hours overdue (both
   company settings). Leads cannot be exported yet ([docs/leads.md](docs/leads.md)).
8. **Mobile API (Phase 8)** ([docs/api.md](docs/api.md), contract [docs/api/openapi.yaml](docs/api/openapi.yaml)) — `/api/v1`, Sanctum
   device tokens (14 days, 5 devices a person), signing in needs the named permission `api.access` (sales roles + administrators),
   checked on every request. A person holding `attendance.create` enters leads **from the field only**: position required, checked
   by `FieldPositionGate` against today's published plan, branch = the plan's, refusal = 422 + a `lead_save` violation; everyone else
   enters typed-in leads. Entry on the *web* form and editing an existing lead are **not** geofenced (owner decision pending: close
   the web form to field staff?). A refused lead cannot be "accepted" by a manager. Deals (Phase 10) and a read-only quotation list/show (Phase 11) are in the API;
   attachment upload is not (Phase 19).
9. **Lead marketplaces (Phase 9)** ([docs/integrations.md](docs/integrations.md)) — IndiaMART pulled (and/or pushed), Just Dial pushed;
   every delivery is logged first and de-duplicated by provider lead id; **a second enquiry from a mobile number that is on an open
   lead becomes a note on that lead** (a closed lead does not block a new one); a lead with no mobile or only the placeholder name is
   kept and **incomplete**; new leads wait in the pool (or go to one named person) in one chosen branch, entered *as* the person who
   saved the connection (who must hold `lead.create` and `lead.assign`). Unsigned pushes rely on the secret address (the providers
   cannot sign). Log kept 90 days. **Built against documented shapes only — live verification pending**: IndiaMART needs a paid-seller
   Pull API key; Just Dial needs its account manager to switch the push on and send a sample payload.

10. **Inquiries and deals (Phase 10)** ([docs/deals.md](docs/deals.md)) — an *inquiry from an existing customer* is a **lead of a second kind**
    (`lead_type = existing`, an approved customer ledger fixed at entry and copied onto the lead, priced item lines, the approval
    workflow; it is locked while pending/approved and re-opened with a reason). A **deal** is made only by converting a **qualified lead
    that has an owner** (`DealService::convert`), once (`deals.lead_id` unique + the lead's row lock + a locking read), with **every line of
    the lead** across (adjusted, never dropped or swapped); the lead then sits at the Deal stage and follows its deal (lost with it). A
    deal's value and amounts are the server's; its customer and lines lock when it is pending/approved, where it stands (close date,
    probability 1–99, remarks) does not; Quotation/Won are set only by documents (`setStageBySystem`, approved deals only); re-opening a
    lost deal is `deal.reopen`; deals are never deleted; follow-ups stay on the lead. **Open reading to confirm:** the brief says an
    inquiry "once approved, may convert to Quotation" — built as a gate on a quotation made *directly* from it (Phase 11), not on
    converting it to a deal.

11. **Quotations (Phase 11)** ([docs/quotations.md](docs/quotations.md)) — a quotation is a number plus **revisions, one row each**
    (`unique(company, number, revision)`); a revision is a **draft** until it is approved and sent, and then **never changes** (the models
    refuse it; a change is a new revision, the old one *superseded*). Made from an **approved, open deal**, or directly from an **approved,
    open inquiry** that has no deal; a deal needs no quotation to be ordered. Prices **only** from `RateService` (price list chosen **per
    quotation**, standard fallback; unchanged lines keep their saved price; override = `rate.override` + reason + audit on the rate); GST
    by **place of supply** (`TaxService`: CGST+SGST within the supplier's state, IGST otherwise, half-up paise, grand total rounded to the
    rupee). Approval before sending; sending claims the revision first, then e-mails the PDF (a refusal puts it back to draft); the deal
    is in *Quotation* while a quotation of it is sent/accepted and back in *Negotiation* when none is; a follow-up call is planned
    `quotation.followup_days` after sending; `sales:quotation-expiry` (daily) expires and warns. Ordering from a quotation (Phase 12) must
    ask `QuotationConversionGuard`: hard "no" (draft, declined, superseded, withdrawn, unapproved, deal closed) is never waived; soft "no"
    (only sent, or out of date) needs `quotation.convert_exception` + a reason, audited. **Open for the owner:** plain A4 PDF (no logo /
    amount in words), e-mail only (no WhatsApp until Phase 16), no discount-approval threshold, a lost deal does not withdraw its quotations.

12. **Sales orders and invoices (Phase 12)** ([docs/sales-orders.md](docs/sales-orders.md)) — an order is made from an **accepted quotation**
    (cleared by `QuotationConversionGuard`; an unaccepted or out-of-date one only by `quotation.convert_exception` + a reason), an **approved
    deal** (no quotation needed), or **directly** for a *regular customer* (≥ `sales_order.direct_min_orders` approved orders, default 1). One
    **live** order per quotation and per deal (row lock + locking read + unique keys on the virtual `live_quotation_id` / `live_deal_id`).
    Prices: **copied as quoted** from a quotation (setting `sales_order.quotation_pricing`), otherwise the rate master. The **credit check**
    (`CreditCheck`) is warn/block/ignore by `credit_limit.behaviour`; a blocked order needs `credit_limit.override` + a reason. *Open
    assumption to confirm:* with no payments recorded, "already out" = the customer's other orders still inside their **credit days**.
    **Approving an order makes exactly one invoice** (`InvoiceGenerator`: number first, order row lock, locking existence read, rollback gives
    the number back, `unique(sales_order_id, source_key)`; retried by `sales:generate-invoices`; a failure is kept on the order as
    `invoice_error`) and wins the deal. An invoice is a **copy**, never changes, posts nothing to stock or accounts, is made in full only (the
    table is ready for parts), and is cancelled with its order. Targets are achieved by approved orders (`SalesOrderAchievement`). Accounts
    and Operations see every order via the new `sales_order.view_all`; BDEs may create and edit orders. **Open for the owner:** the credit
    assumption, "regular customer" as an order count, a won deal is not put back when its order is cancelled, no partial invoicing / API /
    e-mail of orders and invoices.

13. **Dashboards and reports (Phase 13)** ([docs/reports.md](docs/reports.md)) — sixteen baseline reports (`app/Modules/Reports`, one class each, listed by
    `ReportCatalog`) and a sales dashboard on the home page. A report needs `report.view` **and** the right to see its records (`lead.view`…); its
    rows are restricted by the same access classes as the screens (never a role name); filters are validated against what the person could have
    picked (`ReportInput::resolve`). **Actual** (approved, uncancelled orders net of tax, follow-ups done) is labelled and kept apart from
    **forecast** (open deals × probability); orders not yet approved are listed but never counted. Exports (`report.export`) are queued
    (`ReportExporter` → `GenerateReportExport`), made **as the person who asked**, kept 7 days on the private disk and downloadable only by them
    (re-checked at download); Excel ≤ 50,000 rows, PDF first 1,000. *Not built, said so on the page:* campaign performance (Phase 15), SLA
    compliance (Phase 14), AI activity (Phase 18). **Open for the owner:** the row caps, a 7-day keep, no cache, no scheduled/e-mailed reports,
    "won" per lead source meaning the deal was won, pending approvals showing a non-administrator only their own queue, BDE and Operations
    now holding `report.view`.
14. **Customer 360, tasks and SLAs (Phase 14)** ([docs/workspace.md](docs/workspace.md)) — `app/Modules/Workspace`. **Customer 360** (`/crm/customers/{ledger}`):
    a customer ledger only, for anyone with `ledger.view`; every section read through its own access class and left out when the person has no right to it;
    no bank details; records found by link (customer ledger id), not by name or phone. **Tasks**: list/board/calendar, one door `TaskService`, seen/worked through
    `TaskAccess` (own + made-by-me + the people you manage), `task.assign` to give one to somebody in reach, append-only assignment history, a repeating task makes
    the next on completion, reminders claimed with a conditional UPDATE. **SLA**: policies by source/team/priority (most specific wins, only for things made after the
    policy), optional working hours + holidays (`BusinessClock`), clocks and breaches kept by `SlaSweep` (`sla:check`, every 5 minutes) — it starts, settles from facts
    (contact, done, closed, handed over, moved) and notices; each breach logged once, told once, audited; **nothing is reassigned automatically**. **Open for the owner:**
    no pause, salespeople do not see the breach log (`sla_policy.view`), no SLA for quotations/approvals, a follow-up SLA policy runs beside Phase 7's 24-hour escalation,
    no attachments on tasks, "Add a task" only from a record's page.
14a. **Phase 14 gaps, closed with Phase 15** ([docs/workspace.md](docs/workspace.md)) — SLA clocks are made and settled by `SlaSweep` from facts (never observers), a breach is claimed by a conditional UPDATE and recorded
    once; a clock can be paused (`sla.pause`, reason, audited); quotations and approvals can be timed; the 24-hour Phase 7 follow-up alert stands down
    when a follow-up SLA policy covers the follow-up. `schedule:heartbeat` + `/admin/health` say whether the scheduler is alive.

15. **Marketing (Phase 15)** ([docs/marketing.md](docs/marketing.md)) — **e-mail is the only channel that can be handed over**; WhatsApp/SMS are
    `not_connected` until Phase 16. A message is made only by `CommunicationService` (approved + active template, `unique(company, idempotency_key)`,
    consent and frequency cap checked and the refusal *kept* as `suppressed`); `sent` = accepted by the mail server, never "delivered". Consent is an
    append-only history, the newest event wins, opt-out always wins; a marketing e-mail template must contain `{{unsubscribe_url}}` (signed link, GET
    shows, POST records). A campaign's audience is a definition worked out with the **owner's own reach** and frozen into members at launch; steps are
    sent by `CampaignRunner` (message first, then a conditional UPDATE moves the member on). Workflows are fixed triggers/conditions/actions, versions
    immutable, run **as a named person with that person's rights**, once per event (`unique(workflow_id, trigger_key)` claimed first), dry-run available,
    and no action can approve, skip consent or fire another trigger. **Open for the owner:** the lawful basis for consent is the company's, attribution is
    last-touch within 30 days (a credit, not proof), no delivery/bounce tracking until Phase 16.

16. **Loss Intelligence L1** ([docs/loss-intelligence.md](docs/loss-intelligence.md), `app/Modules/Industry`) — a second brief (Loss Intelligence ERP) was merged
    into this app; what it repeats is skipped, the rest is phased L1–L8 beside the CRM phases. **Industry templates are system data** (no `company_id`,
    seeded in every environment by `IndustryTemplateSeeder`); a company *enables* one (`company_industry_templates`, version pinned) and keeps its own KPI
    thresholds in `industry_settings`. Child rows carry a `version`; `publishNewVersion()` copies forward and a company moves on purpose. The
    default loss workflow is the rows with a NULL template id. **Brands are a plain master** (the brief wants approval — open for the owner). Money in new
    tables follows the repo (`amount()` 15,2), not the brief's 18,2. Decisions 1–8 of loss-intelligence.md are in force until answered. Thresholds in the
    seeds are examples, not standards; pharma/healthcare templates make no compliance claim.

17. **Returns, costs and net revenue (L2)** ([docs/returns-and-costs.md](docs/returns-and-costs.md)) — a return and a credit note are **one document** (`sales_returns.return_type`),
    against one *issued* invoice, one `SR/…` series, approval by anyone holding `sales_return.approve` (maker–checker on). A line's returnable quantity = invoiced − what
    *pending or approved* returns have taken (a draft reserves nothing), checked at save and again under an invoice lock at submission. An item's `standard_cost` (per base
    unit, optional) is copied onto each invoice line **once**, when the invoice is made; **no cost = null, never zero**; invoices made before L2 have none. `costing.method`
    exists but moving average waits for stock (L3), so both methods read the standard cost today. A return counts in net revenue in the period of its `return_date`, once
    **approved**. **Open for the owner:** date by approval instead of the form's date; a separate credit-note series; PDF/e-mail/API for returns.

18. **Inventory and procurement (L3)** ([docs/inventory-and-procurement.md](docs/inventory-and-procurement.md)) — stock is a **ledger** (`stock_movements`, append-only, base units, signed)
    plus **balances** per branch × item × batch (`batch_key` 0 = none), moving average per pool, no negative stock, expired/quarantined batches can be scrapped but not
    moved. Goods arrive only through an **approved goods receipt** against an **approved, open purchase order**, within the line's copied PO tolerance (accepted + rejected
    count; pending + approved receipts reserve), posted **once** by `GrnPoster` (retry `procurement:post-grns`). `costing.method` = moving average is live (branch, else
    company, else standard cost). **Sales still post nothing to stock** (assumption 5). **Open for the owner:** should an invoice/delivery issue stock and a goods return
    bring it back; GST/discounts on POs; purchase returns; cycle counts.

19. **Loss management (L4)** ([docs/loss-management.md](docs/loss-management.md)) — an incident keeps the **template version** it was made under (fields, categories,
    workflow); its state moves only through `LossWorkflowService` (transition permission, comment, approval by **someone other than the reporter**, and conditions:
    investigator → root cause → actions done → all verified and figures decided before closing). Money is impact lines of **one kind each** (actual / exposure /
    opportunity), **never added together**, superseded rather than edited, validated by someone other than the person who entered them; only validated actual is
    actual loss. A record reported twice is linked as a **duplicate** and not counted. Savings = verified CAPA results. New roles: Quality/Loss Manager, Investigator,
    CAPA Owner. **Open for the owner:** automatic incidents from scrap/expiry/rejection; a timer on untriaged incidents; attachments; multi-currency.

20. **Finance (L5)** ([docs/finance.md](docs/finance.md)) — a loss incident is *charged* to a cost centre; a budget is cost centre × month × optional loss category, an approval
    document (Accounts makes, Finance Controller approves); budget against actual compares **approved** budgets with **validated actual** loss only. Profitability = net revenue
    less cost of the lines' copied cost, with actual loss **beside** it, never subtracted; industries became a revenue dimension in L6 (by the item's industry). Every change of an item's cost is in
    `item_cost_history`. **Open for the owner:** budgets for revenue/expenses/accounts (losses only today), quarterly/yearly budgets, roll-up to parent cost centres,
    overhead allocation, a Plant/Site Head role.

21. **Analytics (L6)** ([docs/analytics.md](docs/analytics.md)) — `/executive`, six `/analytics/{page}` pages (`?format=json` gives the same data), an industry dashboard, the header
    search and KPI snapshots. Needs the new `analytics.view` plus the right to the figures (`profitability.view` for money, `loss_incident.view` for loss); a person without one
    simply does not get that section. Revenue by industry = the **item's** industry (`items.industry_template_id`; "Unassigned" otherwise). Only KPIs the system holds data for
    are measured and snapshotted (`KpiCalculator::SUPPORTED`); the others say "Not measured yet". A change on a card is blank when there was no earlier figure. **Open for the
    owner:** no revenue targets (so no target line), `/dashboard` left as the sales dashboard, snapshots stored but not charted as history.

22. **HR (H1)** ([docs/hr.md](docs/hr.md)) — `app/Modules/HR`; the brief did not specify HR, so it is planned H1–H4 in that file and built one phase at a time. An employee file is never deleted: a person
    leaves through notice → exit (`employee.record_exit`), and on the last working day (`hr:sync-exits`, daily) the **login is switched off and API tokens revoked**. Who sees a file is `EmployeeAccess`:
    `employee.view_all`, otherwise their own file and everyone below them in the reporting line; date of birth, personal e-mail, address, contacts and papers need `employee.view_sensitive` (not on one's
    own file). Job changes are dated history (`employment_events`, append-only). PAN, Aadhaar and bank details are deliberately **not** stored until payroll (H3). **Open for the owner:** statutory
    payroll rules (PF, ESI, PT, TDS), leave policy, an employee import.

23. **Leave and attendance (H2)** ([docs/hr.md](docs/hr.md)) — a leave balance is the append-only **leave ledger** (one line per `period_key` under a unique key); a request counts **working days only** and reserves its days
    while it waits; it is decided by the person's manager line (`leave.approve`) or HR (`leave.approve_all`), **never by the person themself**, on its own screen (not the approval inbox: the approver is the manager,
    which per-document-type levels cannot express). Attendance is append-only punches + a nightly, idempotent day-close; a correction adds punches marked "regularised". Field-sales attendance (Phase 6) is separate and
    unchanged. **Open for the owner:** the leave policy (types and quotas are samples), notifications, comp-off / encashment, biometric punches.

24. **Recruitment, performance and training (H4)** ([docs/hr.md](docs/hr.md)) — recruitment is HR's; an application reaches *offer* and *hired* only through `makeOffer` and `hire` (never by moving the stage); only the
    interviewer writes an interview's feedback; a hire makes the employee file through `EmployeeService` on a six-month probation and never fills more positions than the opening has. A review runs person → manager
    (never the person themself) → HR (`performance.finalise`) and is then immutable; goals are fixed once the manager has rated. Training seats are taken under the program lock. **Open for the owner:** salary in
    offers (payroll H3), a rating framework beyond 1–5, training cost and certificates. Leave, correction, interview, review and training **notices** go through `HrNotifier`
    (a failed mail never undoes the change).

25. **Loss, finance and HR reports (L8)** ([docs/reports.md](docs/reports.md)) — a report whose figure is worked out by a service (the loss Pareto, budget against actual, profitability) overrides `collection()` and reads that
    service, never a second copy of the formula; the register keeps the four loss values apart and shows a duplicate or rejected incident as not counted; HR reports leave out personal details and leave reasons; an export is limited to five waiting per
    person. **Open for the owner:** which industries to pilot (L7), scheduled/e-mailed reports, stock-expiry and procurement reports.

26. **Scheduled reports (L8b)** ([docs/reports.md](docs/reports.md)) — a report is e-mailed **only to the person who schedules it**, made as them with their access, as an attachment from the private disk; the date range is
    relative and resolved on the day it runs; a due schedule is claimed by a conditional update of `next_run_at` (one file however many runs overlap); it pauses itself, once, when the owner is inactive or can no longer export. At most
    10 per person; monthly on day 1–28. **Open for the owner:** sending to colleagues or outside addresses (a file would leave the person's own access), a per-company time zone.

27. **Onboarding and exit checklists (H5)** ([docs/hr.md](docs/hr.md)) — a person's list is a **copy** of the company's standard list, made once (`ChecklistService::start`: employee row lock + `unique(employee, kind, source line)`),
    automatically on hire and on notice; HR ticks any line, the manager (never for themself) the manager's lines, the person their own; a ticked line never changes. **Open for the owner:** reminders for late lines, blocking an
    exit until HR's lines are done, lists per department or employment type, undoing a tick. Late lines are reminded once a week (`hr:checklist-reminders`, a digest per person, claimed by a conditional update of `reminded_at`).
    A candidate has one **résumé** on the private disk (`ResumeService`, HR only, downloaded through `CandidateResumeController`).

28. **WhatsApp and SMS (Phase 16, first slice)** ([docs/channels.md](docs/channels.md)) — each channel is `off` (default: messages kept as *not connected*), `sandbox` (nothing leaves; `sandbox-…` reference) or `live` per company
    (`ChannelRegistry`); `CommunicationService::deliver()` hands a message to the provider adapter and keeps its reference (`provider_ref`); reports come back on the secret company webhook (`ChannelWebhookController`, signature
    required when live), are applied once and never backwards (`ChannelDelivery`), and a reply of STOP is an opt-out. **Built against the documented Meta Cloud API and Twilio shapes; live verification pending.** **Open for the
    owner:** WhatsApp needs Meta-approved templates outside the 24-hour window (we send text), India SMS needs DLT registration, the provider choice itself (Twilio is the assumption), voice/telephony and website lead capture.

29. **Production (P1)** ([docs/production.md](docs/production.md)) — the owner chose *core production* (no routing/capacity, no labour/overhead costing) and then Quality management. A **bill of materials** is an approval master (maker–checker,
    locked once waiting/approved; a change is a new bill and the old one is made inactive); a **production order** copies what it requires from the bill when planned; material is issued and good output received **only through
    `StockService`**, once per entry key, under the order's row lock, within the plan plus a company allowance (10% each by default); finished goods are valued at **material cost only**. Entries are append-only. **Open for the
    owner:** labour and overhead costing, routing/work centres/capacity, returning unused material, MRP/reservation, by-products, production from sales orders.

30. **Quality (Q1)** ([docs/quality.md](docs/quality.md)) — the owner chose *inspections + non-conformances + release* (no SPC, calibration or supplier quality). An **inspection plan** is an approval master (maker–checker, locked once waiting/approved);
    an **inspection** copies the plan's checks, **holds the batch** (quarantine) until it is decided, and works out **pass / fail from the limits** (never typed); a failed one is **rejected** (raises an NCR, batch stays held) or **accepted with a
    deviation** (reason ≥ 10 characters, never by the inspector, batch released). A **non-conformance** is decided (rework / scrap / use as is / return / sort — *use as is* is a deviation and releases the batch), linked to a loss incident and corrective
    action, and closed (a critical one only with an action). Holds are append-only (`batch_holds`). **Open for the owner:** an automatic hold on goods receipt / production output, sampling (AQL) tables, SPC, calibration, supplier quality.

31. **Maintenance (M1)** ([docs/maintenance.md](docs/maintenance.md)) — the owner chose *assets + preventive schedules + work orders* and a *loss incident linked by hand* (no meter triggers, no calibration). A schedule makes its work order
    `lead_days` before it falls due, **once for each due date** and never while one is open (`maintenance:generate-orders`, daily); completing it moves the schedule on from the day it was done. A work order is worked only by someone who holds
    `maintenance_order.execute` **and** the order is unassigned, theirs, or they may assign; completion needs every task ticked and a note (a breakdown also when the asset was running again); parts come only through `StockService::issue`,
    once per entry key. Downtime, MTTR, hours and parts value are **worked out by `MaintenanceFigures`**, never stored. **Open for the owner:** meter-based triggers, a calibration register, labour cost, notifications.

32. **Hardening (Phase 20, first slice)** ([docs/operations.md](docs/operations.md)) — every response carries `SecurityHeaders` (global middleware; no CSP on purpose); `formezy:backup` dumps the database to gzip (one snapshot, password only in the tool's
    environment) and `formezy:restore-check` loads it into a scratch database and compares it table by table (OK / WARN / FAIL, scratch dropped always) — scheduled only when `BACKUP_ENABLED=true`. **Verified once on the demo data (167 tables).** The
    CI workflow exists but has not run on GitHub. **Open for the owner:** acceptable data loss and recovery time, where off-site copies go, a load test with realistic volume, two-factor sign-in.
33. **Radar and the 2026 brief (R1)** ([docs/radar.md](docs/radar.md)) — owner answers on 2026-10-22: pilot **plastics / packaging**; finance by **integrating with Tally / another package** (no general ledger of our own); AI through a **self-hosted / other provider** behind a provider-independent interface (disabled until configured, provider and data policy not yet named); the Radar only **proposes** (findings become incidents only when a person reports them, never automatically). Detectors: expired stock, scrap, rejected receipt, **production material variance**, **price above standard** (`radar.price_variance_percent`); a new **high** finding, a savings measurement sent and a saving that recurs each tell people once through `LossNotifier` (a failed notice is logged, never undoes the work). A finding is valued only by `LossCalculationService` through `LossSources`; the sweep is claimed by a unique key per detector and source record; findings are written only by `RadarService`. **Open:** real plastics thresholds, a Tally data contract, the AI provider.
34. **Verified savings (R2)** ([docs/savings.md](docs/savings.md)) — a **savings measurement** (one per verified corrective action, `SavingsService` is its one door) compares the validated actual loss of the action's category
    at its branch before and after, scaled by volume or days, less cost; **Finance** verifies it (`savings_measurement.approve`, never the maker or the action's owner); once submitted the model refuses any change; two live
    measurements of the same loss may not have overlapping windows; `savings:check-recurrence` flags a loss that came back. Finance-verified savings (`LossFigures::financeVerifiedSavings`) are **never added to** the action's own
    `verified_result_amount` (`realised_savings`). Losses are read only through `LossFigures::actualFor()`. The executive dashboard shows the Radar backlog (as an *estimate*) and Finance-verified savings (`DashboardAnalytics::leakage`); reports `radar-findings` and `verified-savings` (40 reports in all). **Open:** API presence, price/mix normalisation, a multi-process race test of the branch lock.

## Commands

PHP here is **not** XAMPP's (8.2). Put the portable 8.3 first on PATH in every shell:

```powershell
$env:Path = "C:\Users\Sagar\tools\php-8.3.35;" + $env:Path
```

```bash
composer check                       # Pint + Larastan level 6 + Pest incl. tests/Concurrency (all must pass)
php vendor/bin/pest --colors=never   # tests only (MariaDB must be up; DB industrial_crm_test)
php artisan migrate:fresh --seed
php artisan formezy:docs             # regenerate docs/permission-matrix.md and docs/route-inventory.md
npm run build                        # needed after any CSS/JS/Blade class change for real browsers
php artisan serve --port=8731        # ports 8000 and 8080 are used by other projects on this machine
npm run test:browser                 # headless Edge/Chrome against the running server + freshly seeded demo data (~1 min); re-seed after
php artisan crm:followup-reminders   # one round of follow-up reminders/escalations (the scheduler runs it every 5 minutes)
```

A phase's gates are: Pint, Larastan, the full Pest suite (never two Pest runs at once: each rebuilds the test database),
`npm run build`, `formezy:docs`, `migrate:fresh --seed`, **and `npm run test:browser`** — the last because three faults
that passed every server-side test were found only in a real browser (see Gotchas).

## Architecture rules

- Modules live in `app/Modules/{Shared,Masters,CRM,Sales}/…`; module routes are loaded from the module's service provider.
- Every company-owned model extends `TenantModel`; add `Approvable` + `implements ApprovableDocument` for workflow
  documents and `BelongsToLocation` for branch-owned ones. `approval_status`, `approved_*`, `company_id` and
  `location_id` are never mass-assignable — set them with `forceFill()` in trusted code only.
- Schema: `$table->masterColumns()`, `locationColumn()`, `amount()` (15,2), `quantity()` (15,3). Never floats.
  `masterColumns(withStatus: false)` leaves out the active/inactive `status` for a table with a state of its own (route plans,
  team memberships, stops) — the model must then also reset `protected $attributes = []` (or give its own default), because
  `TenantModel` defaults `status` to `active` and would try to insert a column that is not there.
- **Records that are evidence are append-only**: attendance, the geofence violation log and item stage history are plain
  `Model`s with `BelongsToCompany` (+ `BelongsToLocation`), no soft deletes, and `updating` / `deleting` guards that throw. A
  correction is a new row that points at the old one (an accepted attendance points at the refused attempt), never an edit.
- New permission forms go in `PermissionRegistry`; new document types in `DocumentType`; new morph aliases in
  `SharedServiceProvider` (the morph map is enforced — an unregistered model throws). Then run `formezy:docs`.
- Approve through `ApprovalService`, number through `NumberSeriesService` inside the same transaction as the save.
  Documents whose content includes child rows must override `approvalFingerprintParts()`. Take the number **first**, before
  the transaction writes anything else: requests then queue on the series instead of deadlocking on each other.
- **Leads and follow-ups** ([docs/leads.md](docs/leads.md)): lead data has one door, `LeadService` (`create` / `update` /
  `changeStage` / `delete`) — the form, the import and Phase 8's API all send it the same input, and it checks the branch against
  the *actor* explicitly because an import runs where nobody is signed in. "Which leads may this person see or work" is always
  `LeadAccess` (`works()` / `sees()` / `manages()`, `restrict($query, $user)` for lists, `openable($user, $leads)` for a page of
  rows); a filter may only narrow what `restrict()` allows. Ownership changes only through `LeadTransferService` (all or
  nothing, history kept); follow-ups only through `FollowupService`, and are read through `FollowupAgenda`. Stage and ownership
  history are append-only evidence. A follow-up hangs on a morph (`subject`), so deals and quotations reuse the table.
- **The mobile API owns no business rule** (`app/Modules/Api`, [docs/api.md](docs/api.md)). It authenticates a device, settles the
  company (`X-Company-Id` is honoured only for a company the person belongs to — otherwise 403, never a silent fallback), throttles,
  de-duplicates and shapes answers, then calls the services the screens use. Records are found through the caller's own reach
  (`LeadAccess::restrict()`, `FollowupAgenda::query()`) so another person's or company's id is **404**; only then do the policies
  decide (403). A refusal the person should understand (a lost lead, a follow-up already done) is checked first and answered
  `409 state_conflict` with the service's wording, not a bare policy 403. Never put a rule in a controller or a Form Request:
  requests check *shape* (types, lengths, bounds) only. Attendance and a field lead share one geofence decision,
  `FieldPositionGate::verify()` — extend that, never a second copy. Creates take `Idempotency-Key` (`idempotent:required`); the
  database's `unique(user_id, idempotency_key)` is what makes a race safe. Every route must be in `docs/api/openapi.yaml`, and
  every error code in `docs/api.md`: `ApiSpecTest` fails otherwise. Wire times are ISO 8601 with offset, money a decimal string.
- **Marketplace leads** (`app/Modules/Integrations`, [docs/integrations.md](docs/integrations.md)): a provider **adapter** (`LeadProvider`)
  knows only its wire format and limits and never touches data; `LeadApiLogStore` writes every delivery down *first* (redacted by
  `PayloadRedactor`, unique per company + provider + provider lead id); `LeadIntake` turns it into a lead through `LeadService`, the same
  door as every channel (new providers add a class, not a code path); `ProviderSync` pulls within the provider's limits. Provider
  secrets (API key, signing secret) are **company settings only** — never a column, a log line, an error message or an audit entry
  (the key is in IndiaMART's *URL*: catch `ConnectionException` and never pass its message on). A failure that only a person can fix is
  an `IntakeProblem` (not retried); the delivery stays `failed` as the dead-letter queue. The webhook answers 404 identically for every
  wrong address and 202 for anything stored, even if the lead later fails.
- **Inquiries and deals** (`app/Modules/CRM`, [docs/deals.md](docs/deals.md)): lead data still has one door, `LeadService` — an
  inquiry is `create()` with `lead.customer_ledger_id` (the customer snapshot and the line rules live there); **deal data has one door,
  `DealService`** (`convert` / `update` / `updateProgress` / `changeStage` / `reassign` / `setStageBySystem`). Which deals a person sees is
  `DealAccess`; an approver's read-only reach is `ApproverReach` (used by both `LeadAccess` and `DealAccess`) — never a role name.
  A lead's system stages (Deal, Quotation, Won, Lost-with-its-deal) are set only through `LeadService::setStageBySystem()`. The web
  forms share `EditsItemRows` + `crm/partials/item-rows.blade.php`; the API's deal endpoints ask the state-free abilities
  (`DealPolicy::work` / `assign`) first so what the *state* forbids is a 409 with the reason, not a bare 403.
- **Quotations** (`app/Modules/Sales`, [docs/quotations.md](docs/quotations.md)): quotation data has one door, `QuotationService`
  (`create` / `update` / `revise` / `send` / `accept` / `decline` / `cancel`; the scheduler uses `expire`); `QuotationPricer` turns typed
  input into a priced, taxed quotation and saves nothing (the form's live preview and the save share it); `TaxService` is the only place
  GST is worked out; `QuotationDelivery` makes the PDF and sends the e-mail; `QuotationAccess` says whose quotations a person sees (the
  owner of the lead it comes from — never a copy of the owner on the quotation); `QuotationConversionGuard` is what an order asks. A
  revision that has left draft is immutable **in the model**, not only in the service; history and dispatch rows are append-only. Take
  the QT number first in the transaction; `revise` locks the revision it copies. The deal's stage follows its quotations only through
  `DealService::setStageBySystem()`.
- **Sales orders and invoices** (`app/Modules/Sales`, [docs/sales-orders.md](docs/sales-orders.md)): order data has one door,
  `SalesOrderService` (`createFromQuotation` / `createFromDeal` / `createDirect` / `update` / `overrideCredit` / `cancel`); `OrderPricer`
  prices and taxes typed input and saves nothing (the form's live preview and the save share it); `CreditCheck` is the only place a credit
  answer is worked out; `SalesOrderAccess` says whose orders and invoices a person sees (the salesperson stored on the order — never a copy of
  the lead's owner — plus `sales_order.view_all`). **The invoice has one door, `InvoiceGenerator::generateFor()`**: ask it as often as you like,
  from a listener, a command or a button — it takes the number first, locks the order, re-reads with a locking read and lets the unique key be
  the backstop; never insert a `SalesInvoice` anywhere else. An order that is waiting/approved/invoiced/cancelled, and every invoice, is
  immutable **in the model**. The deal is won only through `DealService::setStageBySystem()`. Achievement comes from `SalesOrderAchievement`.
- **Reports and the dashboard** (`app/Modules/Reports`, [docs/reports.md](docs/reports.md)): they are read-only and own no business rule. A report is
  one class extending `Reports\Report` — `query()` returns a builder **already restricted** through `LeadAccess` / `DealAccess` / `QuotationAccess` /
  `SalesOrderAccess` / `FollowupAgenda` / `SalesScope` (never a role name), ends in a unique order key (an export reads it page by page), and the
  page, the dashboard and the export all call it. Filters go through `ReportInput::resolve()`; never read a request value into a query. Money comes out as
  decimal strings, formatted only by `ReportColumn`. An export is made by `ReportExporter::generate()` signed in as the requester; hand a file over only through
  `ReportExportDownloadController` (owner + right re-checked). Anything shown as a forecast says so (`note()`, column label); only approved results are "actual".
- **Tasks, SLAs and Customer 360** (`app/Modules/Workspace`, [docs/workspace.md](docs/workspace.md)): task data has one door, `TaskService` (`create` / `update` / `start` /
  `complete` / `cancel` / `reassign`); who sees and works a task is `TaskAccess` (never a role name); a label of the record a task is about goes through `TaskService::about($task, $viewer)`
  so it is shown only to someone who may open the record. **SLA clocks are written only by `SlaSweep`** (start, settle from facts, notice breaches — each step idempotent and claimed by a
  conditional UPDATE or a row lock); never insert a `SlaClock` or `SlaBreach` anywhere else, and never reassign work from an escalation. Customer 360 reads every section through the access
  class of its own screen (`Customer360::build`) — add a section the same way, never query another module's table directly for it.
- **Marketing** (`app/Modules/Marketing`, [docs/marketing.md](docs/marketing.md)): a message to a person has one door, `CommunicationService::queue()`
  (campaigns, workflows and people all use it); consent is `ConsentService` (never read `contact_consents` any other way); templates render only through
  `TemplateRenderer` (fixed placeholders, no code); campaign data has one door, `CampaignService`, and the audience is `AudienceBuilder` with the owner's
  reach; who sees which message is `CommunicationAccess`; workflow data has one door, `WorkflowService`, and the engine acts through the services the
  screens use as the workflow's `run_as` person. The sweeps (`marketing:run`, `automation:run`) are **data-driven and idempotent** (unique keys + conditional
  UPDATE claims), not observers. A campaign's steps and a workflow version are immutable once the campaign leaves draft / once saved.
- **Industry templates** (`app/Modules/Industry`, [docs/loss-intelligence.md](docs/loss-intelligence.md)): template data has one door, `IndustryTemplateService`
  (`enabled` / `enable` / `disable` / `resolve` / `setKpiThresholds` / `status` / `validateFields` / `moveToCurrentVersion`; `publishNewVersion` is for operators). Read a
  template through `resolve()` (pinned version + the company's thresholds), never by querying the child tables for a screen. Incident custom fields are validated by
  `validateFields()` against the version the incident was created under — never trust the browser's field list. Never write a shared template row from a company request.
- **Returns and revenue** (`app/Modules/Sales`): return data has one door, `SalesReturnService` (`create` / `update` / `returnable` / `preview`; `problemBeforeSubmit` is what the
  model's `beforeSubmitForApproval` asks); whose returns a person sees is `SalesOrderAccess::seesReturn()` / `restrictReturns()` (the invoice's salesperson — never a role name).
  Cost comes only from `CostResolver` and is written only by `InvoiceGenerator` (invoice lines) and copied from the invoice line by `SalesReturnService` — never read an item's cost to
  value a past sale. **Net revenue, COGS, profit and margin are worked out only in `NetRevenueCalculator`** — dashboards and reports call it, they do not re-sum invoices.
- **Stock** (`app/Modules/Inventory`): stock has one door, `StockService` (`receive` / `adjustIn` / `adjustOut` / `scrap` / `transfer` / `rebuildBalances`) — never insert a
  `StockMovement` or touch `stock_balances` anywhere else. Quantities reach it in base units (`UomConverter`). Reads for people go through the scoped models / `StockQuery`;
  a system valuation (`StockQuery::averageCost`) filters by the item's company explicitly. **Purchasing** (`app/Modules/Procurement`): `PurchaseOrderService` and `GrnService`
  are the doors; what was received is always *worked out* from receipts (`PurchaseOrderService::received()`), never stored; stock is posted only by `GrnPoster`.
- **Loss** (`app/Modules/Loss`): incident data has one door, `LossIncidentService`; state only `LossWorkflowService::transition()`; money only `LossCalculationService`
  (`calculate` is the only place a formula is worked out); actions only `CapaService`; the four values and the Pareto only `LossFigures` — never re-sum impact lines elsewhere.
  A workflow move's permission is the one its transition row names (seeded by `IndustryTemplateSeeder`); never hard-code who may move an incident.
- **Finance** (`app/Modules/Finance`): budgets only through `BudgetService`; **budget against actual only `BudgetVsActual`**; **profitability only `Profitability`** (it must agree with
  `NetRevenueCalculator`: a test holds them equal); never re-sum invoices, returns or impact lines for a finance screen. Cost history is written only by the `Item` model event
  (standard cost) and `StockService` (moving average after a receipt).
- **Analytics** (`app/Modules/Analytics`, [docs/analytics.md](docs/analytics.md)): read-only. Dashboard figures only through `DashboardAnalytics` (built on `Profitability` /
  `NetRevenueCalculator` / `LossFigures`), page data only through `AnalyticsPages` (screens and `?format=json` share it), a KPI only through `KpiCalculator` (never a made-up value),
  snapshots only by `KpiSnapshotService`. The filter is always `AnalyticsFilter::fromArray()`. The sidebar and the header search both read `NavigationMenu`. **A Chart.js canvas must sit in a
  `wire:ignore` wrapper** (`analytics/partials/chart.blade.php`) or Livewire's morph blanks it after a re-render.
- **HR** (`app/Modules/HR`, [docs/hr.md](docs/hr.md)): employee data has one door, `EmployeeService` (`create` / `update` / `changeJob` / `confirm` / `linkUser` / `recordNotice` / `withdrawNotice` / `finaliseExit` /
  `rejoin` / contacts / papers); a change of job is only `changeJob` (it writes the history); whose files a person sees is only `EmployeeAccess` (never a role name); the reporting-line cycle check walks the
  chain **without** the branch scope. Papers leave the private disk only through `EmployeeDocumentController`. `finaliseExit` is idempotent and is the only place a login is switched off for leaving.
- **Leave and attendance** (`app/Modules/HR`): leave data has one door, `LeaveService` (`apply` / `approve` / `reject` / `cancel` / `adjust`); the ledger is written only by it and `LeaveAccrual` (`LeaveService::post`) — never
  insert a `LeaveLedgerEntry` anywhere else. Whose requests a person sees and decides is `StaffReach` (own / below them / all; **never their own to decide**). Attendance has one door, `AttendanceService` (`punch` /
  `closeDay` / `sweep` / `recloseRange` / the corrections); `attendance_days` is written only by `closeDay`. Working days come only from `WorkCalendar` (the shared `holidays` table + `hr.weekly_off`). Take the **employee row
  lock** first (`lockForUpdate`) before checking overlap, balance or the last punch — three race tests fail without it.
- **Talent** (`app/Modules/HR`): recruitment has one door, `RecruitmentService` (`saveOpening` / `openOpening` / `closeOpening` / `saveCandidate` / `apply` / `moveStage` / `scheduleInterview` / `recordFeedback` /
  `makeOffer` / `respondToOffer` / `hire`); performance `PerformanceService`; training `TrainingService`. Never move an application to `offer` or `hired` outside them, never insert an `Interview`, `Offer`,
  `PerformanceReview` or `TrainingEnrolment` anywhere else. Take the **row lock** first (opening for hiring, program for enrolling, review for ratings). Notices only through `HrNotifier`. **A Livewire view variable
  must not share its name with a public property** (`$cycle`, `$selected`): the property wins and the page misbehaves silently — pass the view data under another name.
- **Reports** (`app/Modules/Reports`): a report needing a figure that a service works out overrides `Report::collection()` (never re-write the SQL of `BudgetVsActual`, `Profitability`, `LossFigures` or
  `DashboardAnalytics`); a register overrides `query()` and restricts through the access class of its own screen. A column key must not be the name of an Eloquent method on the model (`number` ran `LeaveRequest::number()` as a
  relation). SQL that needs "today" must bind `now()`, not call `curdate()` — the tests (and a clock set for the run) would otherwise disagree with the screen.
- **Scheduled reports** (`app/Modules/Reports`): schedules are made, paused and removed only through `ReportScheduler`; a run is `runDue()` (claim first, then `ReportExporter::request(..., $scheduleId)`), the e-mail is sent by
  `ReportScheduler::deliver()` from `ReportExporter::generate()` and its failure is logged, never thrown. A word that is reserved in MariaDB (`lines`, `rows`, `groups`…) must not be a column alias — quote it or rename it (two
  reports failed on `lines` in L8b).
- **Checklists** (`app/Modules/HR`): a person's onboarding/exit lines are made only by `ChecklistService::start()` and finished only by `tick()` (never insert or update an `EmployeeChecklistItem` elsewhere); the model refuses to
  change a finished line.
- **Channels** (`app/Modules/Marketing/Channels`): a provider adapter (`ChannelProvider`) knows only its wire format and never touches data; whether a channel is connected is only `ChannelRegistry::isConnected()` (never
  `Channel::isConnected()`, which is e-mail's alone); provider credentials are company settings only and never appear in a failure message, a log line or the audit entry (provider text goes through `PlainText::safe`);
  delivery facts are written only by `ChannelDelivery` (conditional updates, never backwards). A new provider adds a class and a registry line, not a code path.
- **Production** (`app/Modules/Production`): bill data has one door, `BomService`; order data and everything that moves stock because of an order has one door, `ProductionOrderService` (`create` / `update` / `release` / `issue` /
  `recordOutput` / `complete` / `cancel`) — take the **order row lock first**, give every issue and output an `entry_key`, and move stock only through `StockService` (`issue`, `receive`). What was issued, produced, the yield and the
  variance are **worked out by `ProductionFigures`** — never stored, never re-summed in a screen or report. Issues and outputs are append-only.
- **Quality** (`app/Modules/Quality`): plan data has one door, `InspectionPlanService`; inspections only through `InspectionService` (`start` / `record` / `complete` / `decide` / `cancel`); reports only through `NonConformanceService`; a batch is held
  or released **only** through `BatchHoldService` (never set `batches.status` elsewhere for quality reasons). Take the inspection's row lock first, and use **locking reads** for any check another transaction may have just changed (a plain read inside a
  transaction is a snapshot). Whether a check passed is **worked out in `InspectionService::record`**, never taken from the browser.
- **Maintenance** (`app/Modules/Maintenance`): asset data has one door, `AssetService`; schedules `MaintenancePlanService`; work orders and everything that moves stock because of them `MaintenanceOrderService` (`create` / `generateDue` /
  `assign` / `start` / `tick` / `issuePart` / `logHours` / `complete` / `cancel` / `linkIncident`) — take the **order row lock first**, give every part and hours entry an `entry_key`, and move stock only through `StockService`. Downtime, MTTR,
  hours and parts value come **only from `MaintenanceFigures`** — never stored, never re-summed in a screen or report.
- **Queued jobs that touch business data** use `RunsInCompanyContext` (`$this->forCompany($id)` in the constructor).
- **There is no implicit Super Admin bypass.** Super Admin is a normal role re-synced to hold every permission; extra
  reach is the explicit `tenant.access_all` / `approval.override` / `comment.moderate` permissions, and their use is audited.
  Never add a `Gate::before` shortcut or an `isSuperAdmin()` shortcut for authorisation.
- Reads that must bypass tenancy use `Model::withoutGlobalScopes(Tenancy::SCOPES)` — **never** a bare
  `withoutGlobalScopes()`, which also drops SoftDeletes and resurrects deleted rows.
- Audit comes free from the `Auditable` trait and is append-only. Never log secrets; `User` already restricts what it logs.
- Business rules are enforced on the server, not in the UI: validate in Form Requests / Livewire `validate()`, authorise
  with policies, and treat anything from the browser (company, branch, rates, prices, ids) as untrusted.
- Integrations are adapters with mock-based contract tests; mark live verification as pending until credentials exist.
- **Field sales** ([docs/field-sales.md](docs/field-sales.md)): "whose records is this person allowed to touch" is always
  `SalesScope` (own / the teams they manage / everyone with `sales.scope_all`) — policies call it and lists use the
  `ScopesToPeople` trait; never compare role names. A position from a device is only a claim: `GeofenceService::check()` decides
  and `AttendanceService` records (or logs the refusal); nothing computed on the client is trusted, and the time is the server's.
  Phase 8's mobile endpoints and lead-save call these same services (through `FieldPositionGate`), they do not re-implement them. Target achievement goes
  through the `AchievementSource` contract (bound to a null source until Sales Orders exist in Phase 12).
- **Prices come only from `RateService::getRate()`** (or `GET /masters/rates/lookup`): approved + active + in-period rates, exact unit, a
  branch the caller works in, and a `RateNotFoundException` that says why — never query `Rate` for a price. A price changed on a document
  goes through `RateService::recordOverride()` (`rate.override` + reason + audit). Live rates for one item/branch/unit/price list never
  overlap; keep that check in `Rate::conflicting()` and in `RateMaintenance` if you touch rate saving.
- **Other modules never query `Ledger` or `Item` directly for pickers or APIs** — use `LedgerLookup` / `ItemLookup` (approved + active
  + current company only) and `UomConverter` for quantities (bcmath, never floats). Item stage and approval status are kept
  in step by the `Item` model itself; do not set `workflow_stage` and `approval_status` independently.
- A document type with no approval levels configured routes to the role its model names in `defaultApproverRole()` (items →
  Accounts via `approvals.item_approver`); return `null` to leave it to anyone holding `{form}.approve`.
- **Anything that picks a ledger goes through `LedgerLookup`** (`search()` / `usable($id)`): it returns only approved, active
  ledgers of the current company and never bank data. Never query `Ledger` directly for a transaction picker or an API, and
  re-check a submitted ledger id with `usable()` on the server. The same rule will apply to Items and Rates.
- **Sensitive fields** (bank account numbers) are encrypted casts, hidden from serialisation, excluded from the audit log
  (log a masked event instead), kept out of approval fingerprints (use a keyed hash) and sent to the browser only for holders of
  the dedicated permission — enforced in the service, not just hidden in the view.
- **Spreadsheet imports** go through `app/Modules/Shared/Imports` (see [docs/imports.md](docs/imports.md)): implement `Importer` +
  `ImportSession`, register it in a module provider, add a page extending `ImportWizard`. The check and the apply share one
  code path; imports are idempotent, a blank cell never erases data, bulk writes get one audit entry, and the job runs through
  `RunsInCompanyContext`. Never hand-roll an upload-and-loop import. An import runs later, in a job where nobody is signed in:
  the session must apply the uploader's own reach (their branches, the people they may assign to) to every row itself, and
  treat the choices stored with the upload as a request. Rows that take a lock other work waits on (a lead takes its branch's
  number series) commit a few at a time (`CommitsInBatches`), not a chunk of thousands.
- **Plain masters are not approval documents** (`masterColumns(withApproval: false)`): departments, designations, units,
  geography, account/item groups, branches. Ledgers and items (Phases 3–4) are. Standard (`is_system`) account groups are locked.
- Geography is per company (each company imports its own); `ValidatesParentCompany` stops a child pointing at another
  company's parent even if a tampered id gets past form validation.

## Gotchas learned the hard way

- **`INSERT IGNORE` then `SELECT … FOR UPDATE` on the same row deadlocks under concurrency**: on an existing row INSERT IGNORE takes a *shared* lock, and two
  transactions both upgrading to exclusive deadlock (found by `tests/Concurrency/StockConcurrencyTest` on `stock_balances`). Take the exclusive lock directly with
  `INSERT … ON DUPLICATE KEY UPDATE` (`DB::table()->upsert()`), then read it.
- **Inside a model's `saved` event a fresh insert is not `wasChanged()`** (changes are synced only on update) but its attributes are still dirty and `getRawOriginal()` still
  holds the old value: use `isDirty()` + `getRawOriginal()` to react to a change on both create and update (the cost-history hook missed every new item until a test said so).
- **`npm run test:browser` found XAMPP's PHP 8.2 on the PATH** and failed with "Composer detected issues in your platform… You are running 8.2.4" (its list of pages comes from `php artisan route:list`). The runner now looks for PHP 8.3+ itself
  (`PHP_BIN`, `php`, then `%USERPROFILE%	ools\php-8.*`) and stops with a clear message if there is none. Every other command here (`artisan`, `composer`, `pest`) still needs the 8.3 first on the PATH — see Commands.
- **A Python helper that runs `php` on Windows must name the binary by full path** (`C:\Users\Sagar\tools\php-8.3.35\php.exe`): `subprocess` resolves a bare
  `php` from the *parent's* PATH (XAMPP's 8.2), not from the `env` it is given — every run failed on the platform check and a mutation script reported
  three safeguards as "missed". Make such a script say when a run did not happen at all, instead of reading it as a pass or a fail.
- **Never edit code, tests or the permission registry while a full Pest run is going**: later tests load the new files against a database seeded at the start
  (old permissions, no new tables), so the run proves nothing either way. Stop it and run again.
- **A browser check must wait for a condition, never a fixed time.** The lead-import check paused 2.5 s after choosing a file; once the dev server took
  ~0.9 s a request (an upload is three round-trips) the click landed on the still-disabled button and nothing happened. It now waits for Livewire's
  `livewire-upload-finish` event and an enabled button (`page.until(...)`). When a check fails, find out what the page did (log its requests) before blaming the app.
- **Replacing a document's child lines must `forceDelete()` the old ones**, not soft-delete: a soft-deleted line still holds its unique key (line number, source line)
  and the new line with the same key fails. Found in L3 (purchase order and receipt edits) — and it was already wrong in L2's return edit, whose test happened to
  change to a different line. Edit tests must re-save with the *same* line too.

- **Never run a policy (or a `Gate::allows`) on a model loaded with a column list** (`with('lead:id,lead_number,…')`): the policy reads
  `assigned_to`, `created_by`, `approval_status`, `company_id`… and gets `null` — it answers wrongly and silently for one kind of record and
  throws a `TypeError` for another (`Lead::approvalStatus()` returned null on the deal page, a 500 found only in the browser; the Pest
  test had only asserted the lead's *number*, which shows either way — assert the link). Load the whole model whenever a policy will see it.
- **`wire:model` is deferred in Livewire 4**: a server-computed preview (an inquiry's total) stays stale until the next request. Use
  `wire:model.live.debounce.600ms` on the fields it depends on; Pest's `->set()` hides this, only the browser check showed it.
- **Inside a transaction a plain read is a snapshot from when the transaction began**: a request that waited for a row lock does not
  see what the winner committed unless it uses a *locking* read (`dealOf($lead, lock: true)`). The unique key is the backstop; the
  locking read is what gives the loser the right message. Mutation-check both (drop the unique key *and* the lock → five deals).
- **Map `ApprovalStateException` in the API renderer** (it is a plain `RuntimeException`: unmapped it is a 500). It is now a 409
  `state_conflict`. A stale `public/hot` file (left by `npm run dev`) makes every page ask a dead Vite server for its assets — delete it.
- `Model::value('stage')` returns the **cast** (an enum), so `->toBe('discovery')` fails; compare with the enum. Editing the line-ending of
  a file with a script: read and write with `newline=''` and keep CRLF if the file has it (Pint also normalises line endings).

- **A Livewire action must not be named after a `window` function either** (`open`, `close`, `print`, `stop`, `find`, `alert`,
  `confirm`, `focus`, `fetch`…): `wire:click="open(5)"` runs the browser's `window.open` and the PHP method never runs — a "view
  this delivery" button did nothing in Phase 9 while its Pest test passed (found by `npm run test:browser`). The list is in
  `tests/Feature/LivewireConventionsTest.php`. The same guard then found four older ones (`ImportWizard::confirm/open`,
  `NotificationBell::open`, `LeadShow::moveTo`), now `confirmImport`, `openBatch`, `openNotification`, `moveToStage`.
- **Never patch several files with a PowerShell array of `@(old, new)` pairs when one file has a single pair**: a one-element
  array of arrays is flattened, `$pair[0]`/`$pair[1]` become single *characters*, and `String.Replace` swaps them across the whole
  file (this turned every `b` into `u` in `tests/Browser/run.mjs` in Phase 9). Recovery that worked with no git: replay the file's
  recorded `Write`/`Edit` calls from the session transcript (`scratchpad/replay.mjs`), check by applying the faulty transform to
  the result and comparing. Use the Edit tool for source edits.
- **`Http::fake()` called twice does not replace the first fake — the first registered stub wins.** A test that changes what a
  provider answers must switch the answer inside **one** fake (an `ArrayObject` the closure reads), not call `Http::fake()` again.
- **A model's enum cast is applied to `groupBy` results too**: `->groupBy('status')->get()->pluck('total', 'status')` fails with
  "Cannot access offset of type …Enum on array". Use `->toBase()` for aggregates.
- **A lock across processes needs a cache they share.** `Cache::lock()` on the `array` driver is per process and protects nothing;
  the webhook race test runs its workers on `CACHE_STORE=database`. Production must use `database` or `redis`.
- A `use` forgotten in a new class shows as `Target class [App\Modules\<ThisModule>\…\SalesTeamService] does not exist` — the name
  resolved in the *current* namespace. Larastan reports it; so does the first test that resolves it.
- Mutation-check a race test by removing the safeguard and watching it fail; a unique key that a foreign key uses cannot be
  dropped (add a stand-in index first, drop, test, then restore both). The test database is re-created by the next `RefreshDatabase` run.
- Live-account integrations are **never claimed verified**: say "built against the documented shape, verification pending" in the
  report, the docs and this file until a real key has been used.
- The Phase 8 lesson held: run a new migration alone first (`migrate:fresh --seed --seeder=…RolePermissionSeeder` with
  `APP_ENV=testing DB_DATABASE=industrial_crm_test`); it passed first time.

- **A migration that fails makes EVERY test re-run `migrate:fresh` (~50 s each) — the suite then looks hung.** A new migration
  must be run alone first: `php artisan migrate:fresh --seed --seeder="Database\Seeders\RolePermissionSeeder" *> file` with
  `APP_ENV=testing` `DB_DATABASE=industrial_crm_test` and read the tail. MariaDB 10.4 refuses a `NOT NULL` `timestamp` without a
  default ("Invalid default value") — use `dateTime()` (only the first `timestamp` may be `useCurrent()`).
- **Sanctum**: `sanctum.guard` is `[]` on purpose (device tokens only; a session cookie must not authenticate the API) and the
  custom token model is `ApiToken` (`Sanctum::usePersonalAccessTokenModel`). `tokenable_type` holds the morph alias `user`.
  In tests use `apiCall()` / `apiToken()` / `idem()` from `tests/Pest.php`: `apiCall` forgets the guards and the tenant memo
  before and after, because the sanctum guard caches its user and `Auth::shouldUse('sanctum')` sticks — fixtures built after a
  request would otherwise run as the last caller.
- **Laravel renders exceptions to `render()` callbacks *after* `prepareException()`**: an `AuthorizationException` arrives as
  `AccessDeniedHttpException`, a `ModelNotFoundException` as `NotFoundHttpException`. `ApiExceptionRenderer` matches on those.
  An unknown `/api/...` path never reaches the route middleware, so the renderer also supplies the request id.
- `Log::withContext()` reaches only channels already opened; use `Log::shareContext()` for something every line must carry.
- Pest's `toHaveKey($key, $value)` and `toContain(...$needles)` take no message: a second argument is a *value/needle*. Use
  `expect(cond)->toBeTrue('message')`. Symfony YAML refuses `: ` inside unquoted text and `, ` inside an unquoted flow-mapping
  value: quote descriptions in the OpenAPI file.
- **PowerShell double-quoted strings bit again** while patching files in a script: `\n` stayed a literal backslash-n (it put code
  inside a comment — Larastan caught it) and `$leads` would have been expanded to nothing. For code use the Edit tool; if a
  scripted patch is unavoidable, use single-quoted strings, then `php -l` and run Larastan.

- **A MySQL/MariaDB multi-column UPDATE assigns left to right**: `SET status = 'x', exit_reason = IF(status = …)` sees the *new* status. Put the columns
  that read `status` before the one that changes it (a campaign member's exit reason was lost until the order was swapped).
- **Blade: do not mix inline `@php(...)` with a `@php … @endphp` block in the same file**, and never glue a directive to the previous one (`@endcan@if`) — both
  compile to broken PHP that only the first page render shows.
- **A morph with a null type** (`subject_type` empty, e.g. a task about nothing) must return early before the morph map is asked for a class.
- **A guard that reads a casted enum in a model event sees the enum, not the string**: read the raw value with `DB::table(...)->value()` (the
  `CampaignStep` guard) and give a model with its own states `protected $attributes = ['state' => 'draft']`.
- **Building a fixture for a locked document**: make it as a draft, add its children, then flip its status with `DB::table()->update()` (the concurrency
  test for campaigns did this) — the model guards rightly refuse the other order.

- **Pest closures stored on `$this` are called as `($this->name)(...)`**, never `$this->name(...)` (that is a call to a method that does not
  exist). A **real SMTP transport pointed at a closed port** (`config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [...port => 1]])` +
  `Mail::purge('smtp')`) is a quick, honest way to test a mail failure. A shell here-document containing `'` in PHP often fails to start
  — write PHP/Python with the Write tool. An `ApprovalService::submit()` of a document whose own `beforeSubmitForApproval()` finds it
  incomplete throws `ApprovalStateException` (map it in the API). **A preview must not stop at the first header error**: let the pricer
  return what it could work out (`preview()`) so each line shows its rate (or why it has none) while the rest is still being typed.

- **A mutation check of a race can need more than one safeguard removed.** The invoice race passed with only the row lock removed (the
  number series' own lock and the unique key still stopped it) and failed only when the lock, the locking existence read *and* the unique
  key were all taken out; the order race the same (quotation lock + locking read + both unique keys). Remove them together, then restore
  each (add a stand-in index first when a foreign key uses the unique key). The unique key on a virtual column
  (`virtualAs("CASE WHEN state = 'cancelled' THEN NULL ELSE quotation_id END")`) works on MariaDB 10.4 and is how "one live order per
  source" is made a database fact.
- **`DocumentApproved` has `->approver`, not `->by`**, and it is dispatched after the approval commits: a listener that fails must not undo the
  approval (log it, record it where somebody sees it, retry it from a command).
- **PHPStan remembers `$keep = $same && …` implies `$basis !== null`**: a later `$keep && $basis !== null` is reported "always true". Test the
  flag alone. A Pest closure stored on `$this` is called `($this->name)(...)`, and `toThrow(SomeInterface::class)` treats an interface as a
  message — name the message or a real class.

- **An aggregate over a report's own query** must drop its columns, grouping and order (`Report::totals()`): `selectRaw` after the report's `select()` mixes
  aggregates with plain columns and fails under `ONLY_FULL_GROUP_BY`. `FollowupAgenda::query()` filters on an unqualified `assigned_to`, so join it only
  through `whereIn('followups.id', …->select('followups.id'))`. A queued job has no signed-in user — `TenantContext` then sees every branch; an export that
  must respect someone's reach has to `Auth::setUser()` them inside the company context (and put the previous user back). A PHPStan docblock with
  `@return` in the middle of a one-line `/** … */` is not read; put it on its own line.

- **A Blade directive glued to the end of another directive is not compiled**: `@endcan@if (…)` leaves the `@if` as text and the page fails with an unbalanced `@endif` (the
  `\B` before `@` needs a non-word character). Put a space between them. Also **never mix the inline `@php($x = …)` with an `@php … @endphp` block in one file**: the block pattern
  starts at the first inline `@php(` and swallows everything up to the first `@endphp`. Use the block form throughout.
- **`Model::query()->update()` does not fire model events**, so an observer never sees `last_contacted_at` being stamped that way (`FollowupService` does). Read the *fact* in the
  sweep (`leads.last_contacted_at`, the stage history) instead of relying on observers; it also works for imports, the API and scripts.
- **A morph relation with a null type** (`$task->subject()->first()` when `subject_type` is null) builds `where '' is null` and fails: return early. And a **Livewire test's
  `withQueryParams()` stays for the next component test in the same Pest test** — clear them (`['type' => '']`) when the next call must start clean.
- **A race test where every process has the same clock cannot tell a real claim from `UPDATE … SET x = <same value>`** (MariaDB reports 0 rows changed, so the loser *looks* beaten). Give each
  worker its own time (`run(now()->addSeconds(random_int(1, 40)))`); then removing the guard fails the test.

- **A green Pest suite does not mean a screen works in a browser.** Pest drives Livewire on the server; it never runs the page's
  JavaScript. Three faults passed every test and were found only by opening the screens in a headless browser (Phase 7):
  1. **A Livewire action must not be named after Livewire's own JS helpers** — `upload`, `set`, `get`, `call`, `dispatch`, `watch`,
     `commit`, `errors`, `refs`, `on`, `el`, `id`, `js`, `hook`, `dirty`, `effect`, `entangle`, `intercept`, `island`,
     `dispatchTo/Self/El/Ref`, `removeUpload`, `cancelUpload`, `uploadMultiple`. In the browser `wire:submit="upload"` runs
     `$wire.upload` instead of the PHP method, so the import wizard's button did nothing from Phase 2 until Phase 7 (it is now
     `uploadAndCheck`). `tests/Feature/LivewireConventionsTest.php` reads the reserved names out of the installed `livewire.js`
     and fails for any component that uses one.
  2. **`session()->flash('status', …)` is only seen if something renders it.** The layout's `<x-flash />` is rendered on a full
     page load only, so a component that flashes and re-renders in place must print the message itself with `<x-status />` —
     seven screens (every plain master list, the trees, the settings pages) had been saying nothing. Pages that print it *and*
     reload showed it twice; the layout's notice now stays quiet when the page has already said it (it looks for
     `<x-status />`'s markup — keep that markup in one place). `tests/Feature/NoticesTest.php` covers both.
  3. **FullCalendar formats are per view**: a global `dayHeaderFormat` with a day and month heads the month view's columns with
     made-up dates. Give each view its own in `views: { … }`.
  Run `npm run test:browser` after touching a screen's JavaScript, the layout or Livewire (`tests/Browser/run.mjs`; add a check
  there for any new behaviour that only exists in the browser). In those checks never hand a DOM element back to Node
  (`!!el`, text or numbers only): Alpine, Livewire and Preact hang objects on elements that cannot be copied.
- **`NumberSeriesService::next()` inside a document's transaction used to deadlock on the first number of a series** (a new
  branch, a new financial year): a locking read of a row that is not there takes a gap lock, two requests holding it block each
  other's INSERT, and a nested transaction cannot retry — the whole document save was lost. Fixed in `lockSeries()` (plain read,
  `INSERT … ON DUPLICATE KEY UPDATE`, and the creator does not ask for the lock again). Each step is there because leaving it
  out deadlocked under `tests/Concurrency`; `SHOW ENGINE INNODB STATUS` ("LATEST DETECTED DEADLOCK") is what explained it.
- **A reminder "claimed" by `UPDATE … SET sent_at = now WHERE id = ?` is not claimed**: MySQL reports 0 rows changed when the
  value is already the same, so runs within the same second looked safe and runs a second apart both sent. The claim needs
  `WHERE sent_at IS NULL`; the race test gives each worker its own clock so that it can tell.
- **Measure before writing a number into the docs.** "A chunk holds the lock for a second or two" was a guess; measured, a lead
  takes ~25 ms to import and a 2,000-row chunk would have held its branch's number series for close to a minute.
- **Livewire keeps the error bag between requests.** A form that hands validation to a service (which throws) must
  `resetErrorBag()` at the start of the action, or a retry that succeeds still carries the last attempt's errors.
- **`Livewire::test(Component::class, ['model' => $stale])` authorises against the instance you pass**, not a fresh one: after
  changing a record through a service, pass `$model->fresh()` (a real request always binds a fresh model).
- **Seeding history through the real services**: `Carbon::withTestNow($moment, fn () => …)` makes "now" a past moment for one
  step, so stages, follow-ups and what is overdue come out as they would have (see `DemoCompanySeeder::at()`).
- `withSession([...])` in an HTTP test is not a flash: it stays for the following requests of the test (`flushSession()`).

- **Blade does not compile a directive glued to a word**: `500 m@if (…) …@endif` leaves the `@if` as text and then fails on the
  orphan `@endif` ("unexpected token endif"). Put a space before it, or use `{{ … ? … : '' }}`.
- **Screen tests: a filter drop-down prints every name and label it offers**, so `assertDontSee($person->name)` or
  `assertDontSee('Outside the geofence')` fails (or passes) for the wrong reason. Tell rows apart by their key:
  `assertSeeHtml('wire:key="rec-'.$id.'"')`.
- **A missing permission looks like a passing test**: `->call('delete')->assertHasNoErrors()` is also true when the call was
  refused with 403. Assert the effect (the row is gone) and, for the refusal, `assertForbidden()`. In Livewire tests a
  `findOrFail` miss is `assertNotFound()`, not a thrown exception.
- **A very long PowerShell command fails to start** (`ENAMETOOLONG`): write big files with the editor tools, not a here-string.
- **A lock only proves itself under a real race**: where the unique key alone would also stop the collision (the same month
  twice), race two requests whose keys differ (a month against the quarter that contains it) — see `tests/Concurrency`.

- **Pest fixtures before `actingAs`** — and that includes arguments of the chained call: `Livewire::actingAs($u)->withQueryParams([... makeRate(...) ...])`
  builds the rate *after* signing in, as that user, so tenancy refuses it. Build every fixture first.
- **Validation rules that call bcmath need `bail`** before them (`['bail', 'required', 'regex:…', fn …]`): without it the closure also runs on text the
  regex rejected and `bccomp('abc', …)` throws a `ValueError` instead of a validation error.
- **Larastan `*NEVER*` on a list-destructured loop variable** over a large literal array of tuples: move the literal into a method with a
  `@return list<array{0: string, …}>` and the real types come through (an undefined closure variable in the same method was a real bug it also found).
- **Eloquent**: `getOriginal()` returns *cast* values (an enum for a casted column); use `getRawOriginal()` to compare with the
  string a transition is about. `wasRecentlyCreated` is not set yet inside `saved`, and original attributes cannot tell an insert
  from an update when a column has a default — set a flag in `creating` (see `Item::$inserting`).
- **Livewire**: a route parameter must not share its name with a public property (edit routes use `{editing}`); an action that
  throws a refusal leaves that component instance unusable, so tests mount a fresh one per action.
- **Pest**: global helper functions in `tests/Pest.php` share one namespace across every test file — a duplicate name is a fatal
  error only on a full run. `assertSee` matches raw HTML, so `assertDontSee('Approve')` also hits "Approved".
- **PowerShell 5.1 `Set-Content -Encoding utf8` writes a BOM**, which breaks PHP files. Use the editor tools, or
  `[IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding $false))`.
- **PowerShell double-quoted strings eat Markdown backticks**: `` `r `` becomes a carriage return (`` `rate.override` `` turned into CR + "ate.override"),
  `` `n `` a newline, and `` `x `` simply loses its backtick. Put doc text in single-quoted strings or here-strings (`@'…'@`), or use the Edit tool, and
  scan for stray CRs (`[regex]::Matches($t, "\r(?!\n)")`) after a scripted doc change.
- **Livewire 4: never name a class `Index`** — it is treated as its folder's default component and can't be resolved
  back from its name (`Users\Index` → "users"). Use `UserList`, `AuditLogList`, …
- Livewire property paths split on dots, so keys such as `approvals.prevent_self_approval` must be mangled
  (`approvals__prevent_self_approval`) when used with `wire:model`.
- **Tailwind 4**: shared classes must be `@utility`, not `@layer components`, to be usable with `@apply`.
- `spatie/laravel-activitylog` 4.x has **no `tapActivity` hook**; the company is stamped by `ActivityLog::creating`.
- InnoDB can deadlock when several requests create the first row of a locked series; `NumberSeriesService` retries
  (`DB::transaction($cb, 5)`). `tests/Concurrency` proves it with real processes — it fails if the lock is removed. The same suite proves the rate overlap check
  (`RateOverlapConcurrencyTest`: overlapping rates submitted at the same instant, exactly one wins; it fails without the item lock in `Rate::beforeSubmitForApproval()`).
- Tests: `RefreshDatabase` wraps each test in a transaction, so DDL inside a test would commit it. The test-only
  `test_documents` table is created by a migration under `tests/Support/migrations`; `tests/Concurrency` deliberately
  runs without the wrapper and cleans up after itself.
- `Model::preventSilentlyDiscardingAttributes()` is on outside production: mass-assigning a non-fillable attribute throws.
- **PowerShell patch helpers that take `@(old, new)` pairs are dangerous**: a single pair gets flattened into two strings and
  `String.Replace` then swaps *single characters* across the whole file (this corrupted five PHP files once; there is no git
  repo to restore from). Use the Edit tool for source edits, and `php -l` every file after any scripted change.
- Livewire views must not use a variable called `$errors` (it is the shared error bag). `assertSee` matches the raw HTML, so
  text split by inline tags (`will be <strong>skipped</strong>`) must be asserted in pieces.
- `Livewire\Component::all()` is a public method; do not name a helper `all()`. Computed properties are not visible to
  PHPStan: call the method (`$this->batch()`) from PHP and use the property only in Blade.
- Maatwebsite Excel 4: exports are marked by `Maatwebsite\Excel\Concerns\Export` (`FromArray` etc. extend it); a
  `WithMultipleSheets` class must also declare `implements Export`. To keep uploaded text inert in a generated report, extend
  `PhpOffice\PhpSpreadsheet\Cell\StringValueBinder` and implement `WithCustomValueBinder`.
- Imports read `.xlsx` through PhpSpreadsheet with a read filter per chunk (flat memory, slower per chunk); CSV is streamed.
  Excel sheets often declare thousands of empty formatted rows, so the sheet size is only a progress estimate.
- **Livewire: a route parameter must not share its name with a public property** (`{ledger}` vs `public array $ledger`): Livewire
  tries to assign the bound model to the property and throws a TypeError. The ledger edit route uses `{editing}`.
- Laravel 13 sessions are stored as **JSON** (`session.serialization = json`): when forging a session for a smoke test, write
  `base64_encode(json_encode($attributes))` and take the login key from `Auth::guard()->getName()`.
- `required_without` does not treat `false` as missing, so "at least one of two checkboxes" must be an explicit check.
- `SeedCompanyDefaults` bulk-inserts (about 90 rows in a few statements); going through the models made every test that seeds a
  company take seconds. Keep seeding set-based.
- Local `.env` uses `QUEUE_CONNECTION=sync` so imports and notifications run without a worker; `.env.example` stays `database`.
- The Phase 1 migrations were edited in place while nothing was deployed. Once any environment holds real data, schema
  changes must be new migrations with a rollback plan.

## Assumptions in force (brief §16) — change if the owner says otherwise

1. Approvals: single level by default, configurable per document type and company, sequential unless set to parallel;
   maker–checker on by default.
2. Item approval routes to **Accounts** (company setting `approvals.item_approver`); Operations stays view + comment only.
3. IndiaMART / Just Dial: credentials and plan come from the owner; build against mocks until then (pull and webhook).
4. Geofence 500 m; manager override off by default (needs permission + reason + audit when enabled).
5. Sales Invoice: document and linkage only — no stock or accounting postings.
6. Mobile: responsive PWA over the REST API first; native/Flutter later if chosen.
7. AI is optional and disabled until the owner configures a provider and data policy.
8. Communication providers are optional and need company credentials, approved templates and consent.
9. Block / PIN Code data comes from Tent Studio via the import template (columns State, District, Block, PIN Code, Locality — our
   layout until their file is supplied).
10. No live credentials or real customer data in code or seeders.
11. Roles and permissions are global; company membership controls data access; only Super Admin edits the matrix.
