# Roadmap — the 20 phases and where the new CRM skills land

Authoritative requirements: [FormEzy_ERP_Master_AI_Coding_Prompt.md](../FormEzy_ERP_Master_AI_Coding_Prompt.md)
(it supersedes the earlier 14-phase brief). Work one phase at a time; each ends with a report and a stop for review.

## Phases

| # | Phase | State | New skills delivered here |
|---|---|---|---|
| 1 | Foundation | **done** (aligned to the new brief) | groundwork: comment visibility, approval due dates, parallel approvals, tenant-aware jobs, append-only audit, assign/configure/view-audit permissions |
| 2 | Masters (Company, Chart of Accounts, Location, Department, Designation, Unit, Item Group, State→PIN, Tent Studio import) | **done** (owner said to continue after the report) | reusable spreadsheet import framework (template, check/preview, error report, queued apply) that Phases 6–7 reuse |
| 3 | Ledger master | **done** (owner said to continue) | the first real use of the approval engine: `LedgerLookup` (only approved ledgers are selectable), audited **re-open** of an approved record, field-level protection of bank data |
| 4 | Item master (3-stage) | **done** (owner said to continue) | exact `UomConverter`, `ItemLookup`, per-stage permissions, an append-only stage history written by the model, default routing of a document type to a role (`defaultApproverRole`) |
| 5 | Rate master + `RateService` | **done** (owner said to continue) | `RateService` (approved + active + in-period only, deterministic tie-break, domain errors that say why), overlap validation under a lock, audited **rate override** hook for Phases 11–12, price lists |
| 6 | Sales team, targets, route plan, attendance | **done** (owner said to carry on through Phases 7 and 8) | the CRM module itself; `SalesScope` (whose work a person may plan, measure and see), the server-side `GeofenceService` and `AttendanceService` the mobile API will call, the append-only violation log, the `AchievementSource` contract Sales Orders will implement |
| 7 | New lead: manual, Excel, follow-ups, calendar, reminders, transfer | **done** (owner said to carry on into Phase 8) | `LeadService` (the one door for lead data, whatever the channel), `LeadAccess` (work / see / manage), follow-ups on a morph so deals and quotations reuse them, `FollowupAgenda` (lists, calendar, the API), a first escalation rule (feeds **6.6**), import column mapping and per-import options in the shared framework, and browser checks for what the server-side tests cannot see |
| 8 | Mobile API + geofencing (Sanctum) | **done** (owner said to continue) | `/api/v1` with revocable Sanctum device tokens, `FieldPositionGate` (one geofence decision for attendance **and** a lead saved from the field, logged as `lead_save`), the JSON envelope + correlation ids + redacted access log, `Idempotency-Key` for offline-created records (feeds **6.14**), `updated_since` sync, an OpenAPI contract held equal to the routes by a test |
| 9 | IndiaMART / Just Dial | **done** (owner asked to continue into Phase 10); **not verified on a live account** | the **integration hub** the later providers share (Phase 16): per-company connections with a health status, provider adapters that touch no data, the inbound log as a dead-letter queue with manual retry, signed/replay-safe webhooks, a pull with a watermark, backoff and the provider's limits, retention, secrets only in Company settings; `LeadIntake` (provider-id and mobile de-duplication, incomplete flag) |
| 10 | Existing lead & Deal | **built** — awaiting owner review | an **inquiry from an existing customer** (a lead of a second kind: approved customer, priced item lines, the approval workflow); **conversion** of a qualified lead to a **deal** (once, every line across, source links kept); a deal's lines and server-side value, stages with history, approval that locks what was signed off, lost / re-open (`deal.reopen`) and hand-over; `DealAccess`, `ApproverReach`; deals and inquiries in the mobile API; `setStageBySystem()` for quotations and orders (Phases 11–12) |
| 11 | Quotation (+ tax service, revisions, PDF/e-mail) | **built** — awaiting owner review | quotations from an approved deal or inquiry (one row per revision, immutable once it leaves draft), priced only through `RateService` with an audited override, `TaxService` (CGST+SGST or IGST by place of supply, half-up paise, rupee round-off), approval before sending, PDF and e-mail with a dispatch log (a refused e-mail puts it back to draft), the deal following its quotations (Quotation ⇄ Negotiation), validity expiry and warnings, a follow-up after sending, the conversion guard with an audited exception for Phase 12, read-only API |
| 12 | Sales order → idempotent Sales Invoice | **done** — owner asked to continue into Phase 13 | sales orders from an accepted quotation (price copied as quoted), an approved deal or directly for a regular customer; the credit check (warn / block / ignore, audited override); approval that makes **exactly one** invoice (`InvoiceGenerator`: number first, row lock, locking read, unique key, retry command) and wins the deal; order acknowledgment and invoice PDFs; invoices that never change; cancelling an order cancels its invoice; targets achieved from approved orders (`SalesOrderAchievement`) |
| 13 | Dashboards & reports | **done** — owner asked to continue into Phase 14 | the sales dashboard (leads by stage and source, funnel, follow-ups, target vs achievement, top people and teams, deals by probability and close date, quotation conversion, branch performance — actual kept apart from forecast) and sixteen baseline reports over the existing access rules, with queued Excel/PDF exports that are authorised when asked for and again when downloaded; campaign, SLA and AI reports wait for Phases 14, 15 and 18 |
| 14 | Customer 360 + Tasks + SLA | **done** (gaps closed with Phase 15) | Customer 360 (one page per customer, each section through its own access class, no bank details); tasks (list, board, calendar, priorities, deadlines, reminders, repeats, links to records, assignment history, comments); SLA policies by source/team/priority with working hours and holidays, a sweep that starts, settles and notices clocks, an append-only breach log and manager escalation; overdue-task and SLA-compliance reports; dashboard cards | **6.1** Customer 360, **6.7** Tasks, **6.6** SLA & escalation |
| 15 | Campaign manager + marketing automation | **built** — awaiting owner review | approved message templates; campaigns (audience frozen at launch, steps, exit rules, pause/cancel/re-open); append-only consent, frequency cap, signed unsubscribe link; a message log where "sent" means accepted by the mail server; campaign attribution (manual, link, inferred); a versioned, dry-runnable workflow builder run as a named person, once per event; e-mail only until Phase 16 — see [marketing.md](marketing.md) |
| 16 | Omnichannel integrations | | **6.3** WhatsApp / e-mail / SMS / telephony adapters, website lead capture |
| 17 | Advanced field sales + PWA sync | | **6.13** Territories & route suggestions, **6.14** Offline PWA sync |
| 18 | AI assistance + forecasting | | **6.2** Lead scoring, summaries, next-action suggestions (disabled until configured) |
| 19 | Optional extensions | | **6.8** Customer portal, **6.9** Document management / e-signature, **6.10** Support tickets, **6.11** Incentives & commission |
| 20 | Hardening & launch | | CI pipeline, full suite, performance/security review, restore validation |

## Loss Intelligence phases (second brief, merged 2026-10-18)

Analysis, conflicts and the L1–L8 plan: [loss-intelligence.md](loss-intelligence.md). Built and awaiting owner review: **L1** Brand master + industry template engine;
**L2** brand and cost on items, returns / credit notes, net revenue ([returns-and-costs.md](returns-and-costs.md)); **L3** inventory and procurement
([inventory-and-procurement.md](inventory-and-procurement.md)); **L4** loss management ([loss-management.md](loss-management.md)). **L5** finance and costing ([finance.md](finance.md)). **L6** executive dashboard, analytics pages and KPI snapshots ([analytics.md](analytics.md)).

## What Phase 1 already provides for the new skills

| Skill | Foundation piece |
|---|---|
| 6.1 Customer 360, 6.10 Tickets | polymorphic comments, enforced morph map, tenant scopes, record-level `view_audit` permission |
| 6.5 Workflows and the integration hub (brief §9) | `RunsInCompanyContext` job middleware — jobs always run inside one company and fail if it is gone |
| 6.6 SLA | approval deadlines are configurable and stored (`approval_levels.due_hours` → `approvals.due_at`); overdue detection and escalation are built in Phase 14 |
| 6.8 Customer portal | `comments.visibility` (`internal` / `customer`), separate from staff notes |
| 6.12 Analytics | audit and approval ledgers that report on actual approved results |
| Security (brief §10) | no implicit Super Admin bypass, audited cross-company access, append-only audit, password policy, encrypted settings, secrets never logged |

## Rules every phase must satisfy (from the brief §0.2)

1. Inspect the repository first; report compatibility risks.
2. Tenant tests for direct URL, API, export and **job** access; never trust company, branch, rate, price,
   approval status or geofence result from the client.
3. Transactions + row locks for numbering, approvals, conversions; **idempotent** imports, webhooks, conversions and invoices.
4. Secrets encrypted at rest and redacted from logs and audit payloads; audit export / import / convert.
5. Integrations built as adapters with mock-based contract tests; live verification clearly marked pending.
6. Phase report: scope, migrations, routes, permissions, tests actually run, known gaps, commands, next phase — then stop.

## Decisions still needed from the owner (brief §16)

| Topic | Needed by | Default used meanwhile |
|---|---|---|
| IndiaMART / Just Dial plan: pull API or push webhook, credentials | Phase 9 | built for both against the providers' documented shapes; **live verification pending** until you supply an IndiaMART Pull API key (paid seller + Leads API add-on) and Just Dial's webhook set-up and a sample payload |
| Marketplace leads: a second enquiry from a number already on an **open** lead is added to that lead as a note, not made a new lead; a lead with no mobile or no real name is kept and marked incomplete; new leads wait in the pool (or go to one named person) in one chosen branch | Phase 9 | as built; alternatives are a new lead each time, or round-robin / rule-based routing |
| Marketplace pushes are unsigned unless a relay you run signs them (the providers cannot): the secret webhook address is the protection; the inbound log is kept 90 days (failed ones twice that) | Phase 9 | company setting `integrations.log_retention_days`; signing optional |
| Inquiries from existing customers: an inquiry is a lead of a second kind that goes through approval; its approval gates making a **quotation directly** from it (Phase 11), not converting it to a deal (which has its own approval) | Phase 10 | as built; the alternative is that a deal can be made only from an approved inquiry |
| Deals: made only by converting a qualified lead that has an owner; a lead converts once; every line of the lead comes across (adjusted, never dropped or swapped); fixed stages Discovery, Negotiation, Quotation, Won, Lost; probability 1–99 while open | Phase 10 | as built; company-configurable stages and direct deal entry are possible later |
| Deals: re-opening a **lost** deal is its own right (`deal.reopen`, Sales Manager and administrators by default); a deal is never deleted; follow-ups stay on the lead until tasks (Phase 14) | Phase 10 | as built |
| Tent Studio Block / PIN Code file and column layout | Phase 2 | our own template (State, District, Block, PIN Code, Locality); if their file differs only the importer's column list changes |
| Geofence manager override allowed? | Phase 8 | off (permission + reason + audit if enabled) |
| Sales Invoice scope: stock and accounting postings? | Phase 12 | as built: invoice document and linkage only, nothing posted to stock or accounts |
| Credit check without payments: what counts as "already out" | Phase 12 | the customer's other orders still inside their credit days (see [sales-orders.md](sales-orders.md)); refine when payments are recorded |
| "Regular customer" for a direct order | Phase 12 | has had `sales_order.direct_min_orders` (default 1) approved orders; a ledger flag is possible later |
| Mobile client: PWA or native / Flutter | Phase 8 / 17 | responsive PWA over the REST API |
| AI provider and data-sharing policy | Phase 18 | AI disabled |
| WhatsApp / SMS / telephony providers, approved templates, consent wording | Phase 16 | adapters + sandbox mode, "not connected" state |
| Rates: one branch per rate (no all-branches rate); live periods may not overlap, so a new price means ending the old rate first | Phase 5 | as built; the alternative is a company-wide fallback rate and "the later start wins" |
| Rates: where a customer gets their price list (a ledger field or a customer category) | Phase 11 | decided as: chosen **per quotation** (default standard rates); a ledger field remains possible later |
| Leads: a duplicate mobile number **warns** (and can be saved as a separate inquiry) rather than blocking; a lead needs a mobile number when typed in or imported | Phase 7 | as built, following the brief's "duplicate warning"; the alternative is to refuse a second open lead outright |
| Leads: who may see a lead — its owner, the owner's manager, administrators, and anyone with a planned follow-up on it (while it is planned) | Phase 7 | as built; the alternative is that only the owner's line sees it, and follow-ups cannot be given to anyone else |
| Leads: only managers and administrators import and hand over; salespeople and tele-callers own what they enter | Phase 7 | the default role grants; change them in the permission matrix |
| Follow-ups: reminder 30 minutes before; the manager is told once when one is 24 hours overdue | Phase 7 | company settings; full SLA policies are Phase 14 |
| API: who may sign in — a named permission `api.access` (sales roles and administrators by default), checked on every call | Phase 8 | as built; the alternative is "every active user" |
| API: a person who may mark attendance (`attendance.create`) enters leads from the field only — with a position, inside today's geofence, branch taken from the plan; everyone else enters typed-in leads. Entry on the **web** form is not geofenced; editing an existing lead is not geofenced | Phase 8 | as built; the owner may want the web form closed to field staff, or edits geofenced too |
| API: a refused field lead is logged but cannot be "accepted" by a manager (only a refused attendance can, when the company allows it) | Phase 8 | as built |
| API tokens last 14 days (renewable), at most 5 devices a person, 5 sign-in attempts a minute per e-mail and place | Phase 8 | `.env`: `API_TOKEN_TTL_MINUTES`, `API_MAX_DEVICES`; limits in `config/formezy.php` |
| Mobile client: PWA first; CORS closed until `API_CORS_ORIGINS` names an origin | Phase 8 / 17 | as built |
| Retention periods for documents, audit, communications | Phase 19–20 | configurable; nothing hard-deleted |
| Which optional extensions to build (portal, e-sign, tickets, commission) | Phase 19 | none until confirmed |
