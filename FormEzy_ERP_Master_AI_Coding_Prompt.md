# FormEzy ERP --- Master AI Coding Prompt

## Full ERP Foundation + CRM-Marketing Platform \| Implementation Specification

**Document purpose:** This is the authoritative project brief for an AI
coding assistant or development team. Use it to build FormEzy ERP in
controlled, testable phases. Preserve all requirements below. Do not
skip, silently remove, or replace existing requirements with simpler
alternatives.

**Build rule:** Start with Phase 1 only. Inspect the repository, state
assumptions, implement the phase, run relevant tests and quality checks,
summarize changed files and results, and stop for review before
proceeding. Never claim a test passed unless it was actually run and its
result is known.

------------------------------------------------------------------------

# 0. Role, Goal, and Operating Rules

Act as a senior Laravel architect, security engineer, database designer,
QA engineer, and full-stack developer. Build a production-ready,
modular, multi-company and multi-branch ERP foundation called **FormEzy
ERP**, including Masters, CRM-Marketing, field sales, quotation/order
workflows, reporting, integrations, and the extensibility required for
future ERP modules.

## 0.1 End-to-end business flow

Support the complete lifecycle:

``` text
Lead (New / Existing / Website / API / Excel / Mobile / Campaign / WhatsApp)
  -> Assignment and Follow-ups
  -> Qualified Lead
  -> Deal with item lines
  -> Optional Quotation (approved rates from Rate Master)
  -> Sales Order (approved rates from Rate Master)
  -> Approval
  -> Sales Invoice generated idempotently
  -> Customer communication, reporting and audit history
```

New and existing leads must be supported. The quotation step is optional
where explicitly permitted. Every master and transaction that is
designated approvable must pass the configured approval workflow before
it can be used downstream.

## 0.2 Non-negotiable operating rules

1.  Work phase by phase in the sequence in Section 19.
2.  Before coding, inspect the repository, PHP/Composer versions,
    Laravel version, database configuration, installed packages,
    existing routes, tests, and current implementation. Do not overwrite
    an existing project blindly.
3.  If the repository is empty, scaffold the application. If it contains
    a project, preserve compatible existing work and explain any
    migration or upgrade risks.
4.  Use migrations, seeders, factories, Form Requests, Policies,
    Actions/Services, Events/Listeners, Jobs, API Resources, Enums and
    tests consistently.
5.  Keep controllers thin. Put business rules in tested
    services/actions.
6.  Never trust client-supplied company, branch, rate, approval status,
    price, permission, geofence result, or user identity. Derive or
    validate these on the server.
7.  Use database transactions and row locks for document numbering,
    approvals, conversions, stock/ledger-affecting operations and other
    multi-table writes.
8.  Design retries and integrations to be idempotent. A retry must not
    create duplicate leads, orders, invoices, messages or webhooks.
9.  Never hard-delete important financial, approval, audit or customer
    records without a documented retention and authorization policy.
    Prefer soft deletes and reversals.
10. Do not store secrets in source control or logs. Encrypt integration
    credentials at rest and redact them from audit records.
11. Add automated tests for every acceptance criterion. If external API
    credentials are unavailable, use mocked contract tests and clearly
    mark live integration verification as pending.
12. At the end of each phase, report: implemented scope, migrations,
    routes, permissions, tests run and outcomes, known gaps, commands to
    run, and the next phase. Stop for review.
13. Do not implement all phases in one uncontrolled change. Do not
    report future-phase functionality as complete.
14. If a requirement is ambiguous, document a reasonable default
    assumption and make it configurable where appropriate; do not
    silently omit the requirement.
15. Provide setup, environment, queue, scheduler, storage, testing,
    deployment, backup and troubleshooting documentation.

------------------------------------------------------------------------

# 1. Mandatory Technology Stack

Use the following stack unless a verified compatibility issue requires a
documented, approved adjustment.

  -----------------------------------------------------------------------
  Area                                Requirement
  ----------------------------------- -----------------------------------
  Framework                           Laravel 13.x, latest stable
                                      compatible release

  Runtime                             PHP 8.3+

  Database                            MySQL 8 or MariaDB 10.11+, InnoDB,
                                      utf8mb4

  Web UI                              Blade + Livewire (latest compatible
                                      version), Tailwind CSS, Alpine.js

  Mobile / integrations API           REST API under `/api/v1`

  API authentication                  Laravel Sanctum, revocable device
                                      tokens

  Roles / permissions                 `spatie/laravel-permission`

  Audit trail                         `spatie/laravel-activitylog` or a
                                      compatible audited implementation

  Excel                               `maatwebsite/excel` if compatible;
                                      otherwise `openspout/openspout`
                                      with equivalent import/export
                                      behavior

  PDF                                 `barryvdh/laravel-dompdf` or
                                      `spatie/laravel-pdf`, selected
                                      based on compatibility

  Calendar                            FullCalendar JS

  Queues / scheduler                  Database queue initially or Redis
                                      where available; Laravel Scheduler

  Testing                             Pest

  Formatting                          Laravel Pint

  Static analysis                     PHPStan/Larastan level 6+ where
                                      project compatibility allows

  API documentation                   Scribe or OpenAPI

  Frontend tests                      Add appropriate tests for critical
                                      interactive behavior where
                                      supported

  CI                                  Automated tests, formatting and
                                      static analysis in the project
                                      pipeline
  -----------------------------------------------------------------------

Before installing packages, verify compatibility with the selected
Laravel/PHP versions using Composer metadata and package documentation.
Do not force incompatible dependency versions.

Use Laravel conventions, backed Enums, Form Request validation,
Policies, API Resources, service/action classes, dependency injection,
events/listeners, queues and explicit domain boundaries.

------------------------------------------------------------------------

# 2. Architecture and Shared Platform Rules

## 2.1 Modular structure

Use one consistent modular structure. Preferred starting point:

``` text
app/
  Modules/
    Shared/
      Actions/
      Enums/
      Events/
      Exceptions/
      Http/
      Jobs/
      Models/
      Notifications/
      Policies/
      Services/
    Masters/
      Actions/
      Enums/
      Events/
      Http/
      Jobs/
      Models/
      Policies/
      Services/
    CRM/
      Actions/
      Enums/
      Events/
      Http/
      Jobs/
      Models/
      Policies/
      Services/
    Sales/
      Actions/
      Enums/
      Events/
      Http/
      Jobs/
      Models/
      Policies/
      Services/
```

The exact structure may be adapted to Laravel conventions, but do not
mix multiple competing module approaches. If using
`nwidart/laravel-modules`, use it consistently and document the
trade-off.

## 2.2 Multi-company and multi-branch isolation

-   Every business/transaction table must include `company_id` and,
    where branch-specific, `location_id`.
-   Users can be assigned to one or more companies and locations.
    Enforce assignments server-side.
-   Implement tenant/branch scoping through a centralized, tested
    approach. Do not rely only on frontend filtering.
-   Policies and query scopes must prevent cross-company and
    unauthorized cross-branch reads, writes, exports, imports, downloads
    and API access.
-   Background jobs must carry and validate company/branch context.
-   Unique constraints must be company-aware where codes or numbers are
    company-specific.
-   Super Admin access must be explicit and auditable, not an accidental
    bypass.
-   Test direct URL access, API access, exports, nested relationships
    and job execution for tenant leakage.

## 2.3 Common fields

Use a shared convention for master and transactional records, as
appropriate to each entity:

-   `id`
-   `company_id`
-   `location_id` where applicable
-   `code` unique within the relevant company/scope
-   `status` backed enum
-   `approval_status`: `draft`, `pending`, `approved`, `rejected` where
    applicable
-   `approved_by`, `approved_at`
-   `remarks`
-   `created_by`, `updated_by`
-   timestamps
-   soft deletes when appropriate

Do not force irrelevant fields into every table solely to satisfy a
generic base model. Document any entity-specific exceptions. Keep
approval state distinct from operational status.

## 2.4 Document numbering

Create `number_series` keyed by company, location, document type and
financial year (April--March). Support formats such as
`QT/AHD/26-27/00001`.

-   Generate numbers inside a database transaction using row-level
    locking.
-   Prevent duplicate numbers with database unique constraints.
-   Define behavior for concurrent requests, failed transactions and
    sequence configuration changes.
-   Never use a simple `MAX(number)+1` implementation.
-   Store a normalized sequence value separately from the formatted
    display number when useful.
-   Test concurrent allocation and financial-year boundaries.

## 2.5 Shared approval engine

Build a reusable `Approvable` contract/trait or equivalent domain
service and an `approvals` table with:

-   `approvable_type`, `approvable_id`
-   approval level
-   assigned approver role and/or user
-   status
-   comment
-   acted-by user and acted-at timestamp
-   created timestamps and any configured due date

Requirements: - Configurable approval levels per company and document
type; default one level. - Approval and rejection events:
`DocumentApproved`, `DocumentRejected`. - Record each decision, actor,
timestamp, level and comment. - Define sequential vs parallel approval
behavior explicitly; default to sequential levels unless configured
otherwise. - Prevent self-approval where company policy requires it. -
Prevent approval of incomplete records or records that changed after
submission without resubmission. - Rejecting returns the record to the
relevant editable stage with the rejection reason. - Notify submitter
and next approver. - Only approved records are available in downstream
transaction dropdowns, APIs and conversion actions. - Use policies and
server-side state transitions; do not accept `approval_status=approved`
from a client payload.

## 2.6 Comments and audit

-   Polymorphic `comments` table for comments on any supported record.
-   Users with view/comment permission can comment without edit
    permission.
-   Record author, timestamps, visibility and optional attachment
    references.
-   Audit create/update/delete/approve/reject/convert/export/import and
    relevant settings changes.
-   Capture old/new values safely, redact secrets and sensitive data,
    and restrict audit-log access.
-   Audit logs should be append-only to ordinary users; corrections
    should be new events, not edits to historical events.

## 2.7 Money, dates and Indian context

-   Amounts: `decimal(15,2)`; quantities: `decimal(15,3)`; never use
    binary floats for financial calculations.
-   INR default currency; support company base currency as configured.
-   GSTIN, PAN, state code, HSN/SAC and place-of-supply fields where
    applicable.
-   Indian PIN codes and `Asia/Kolkata` timezone by default.
-   Display dates as `DD-MM-YYYY` while storing timestamps/dates in
    database-native formats.
-   Financial year defaults to 1 April--31 March.
-   Use a dedicated, tested tax calculation service for CGST/SGST vs
    IGST based on configured place-of-supply rules. Do not hard-code tax
    decisions in templates.

------------------------------------------------------------------------

# 3. Roles, Permissions and Access Matrix

Seed these roles:

-   Super Admin
-   Company Admin
-   Purchase User
-   Inventory User
-   Accounts User
-   Operations User
-   Sales Manager
-   BDE / Field Salesperson
-   Tele-caller
-   Approver

Implement permissions per module/form/action, at minimum:

-   view
-   create
-   edit
-   delete
-   approve
-   export
-   import
-   comment
-   assign/transfer
-   configure
-   view audit

Build an editable permission matrix UI. Use least privilege. Policies
must protect every action, including API endpoints, Livewire actions,
exports, bulk actions, downloads, comments and approval operations.

Special rules: - Operations users can view Item Master records and add
comments, but cannot modify item data. Any edit attempt must return HTTP
403 or the equivalent UI authorization error. - Suppliers are onboarded
by Purchase users only, unless a company permission configuration
explicitly allows otherwise. - Item approval routes to Accounts by
default. Operations may be selected as a workflow participant only for
permitted view/comment duties; do not grant edit or approval powers
contrary to company configuration. - Approver assignments must be
checked against company/branch scope. - Permission changes must be
audited.

------------------------------------------------------------------------

# 4. Module A --- Masters

## A1. Company Master

Fields: - company name and legal name - GSTIN, PAN, CIN - address,
state, PIN code - phone, email, logo - financial year start - base
currency - status and approval/configuration fields as appropriate

Support multiple companies and company-specific settings. Validate
identifiers when provided. Store logos using private or controlled
storage rules and validate file type/size.

## A2. Chart of Accounts

-   Hierarchical account groups using `parent_id`, unlimited depth.
-   Seed standard Indian groups such as Assets, Liabilities, Income,
    Expenses, Sundry Debtors, Sundry Creditors, Bank Accounts,
    Cash-in-Hand, Direct/Indirect Expenses, Duties & Taxes and
    appropriate subgroups.
-   Tree-view UI with cycle prevention and safe parent reassignment.
-   Prevent deletion of groups used by ledgers or postings.
-   Add validation for account code uniqueness within company.
-   Design the master for future accounting integration without falsely
    claiming a full double-entry accounting ledger exists unless
    implemented in a later approved scope.

## A3. Central Ledger Master

Use a centralized `ledgers` table with `ledger_type` enum: customer,
supplier, bank, cash, expense, general, plus type-specific child tables
where useful.

Common: - account code, name, ledger group - multiple billing/shipping
addresses - multiple contacts - contact details and status - approval
workflow

Customer: - B2B/B2C flag - GSTIN mandatory for B2B - credit days and
credit limit - payment preferences: cash, cheque, NEFT, UPI and
configurable options

Supplier: - onboarded by Purchase department - lead time in days -
contact details, credit terms and payment preferences

Bank/Cash/Expense/General: - account code, name, ledger group and
applicable contact/address - bank account number, IFSC and branch where
applicable - sensitive account data access must be permission controlled
and masked where appropriate

Post-condition: only approved ledgers can be selected in transactions or
exposed through lookup APIs.

## A4. Item Group Master

-   Hierarchical groups using `parent_id`.
-   Code, name, status, company scope and audit history.
-   Prevent cycles and unsafe deletion.

## A5. Item Master --- Department-wise onboarding

Implement a multi-stage form with separate permissions and stage
history.

### Stage 1 --- Purchase

-   name
-   item group
-   base UOM
-   multiple UOM conversions, e.g. 1 Box = 12 Nos
-   PO tolerance percentage for allowed over-receipt vs PO quantity
-   HSN and GST rate

### Stage 2 --- Inventory

-   assign item code
-   multiple alias names
-   location-specific minimum stock, maximum stock and reorder level
-   validate `minimum_stock <= reorder_level <= maximum_stock`
-   ensure location-specific rows are unique

### Stage 3 --- Accounts approval / Operations review

-   route to Accounts by default; routing is configurable per company.
-   Operations can view and comment only, with no modification rights.
-   Accounts approves/rejects according to configured workflow.
-   rejection returns to the relevant stage with the comment and
    history.

Status flow:
`purchase_draft -> inventory_pending -> approval_pending -> approved / rejected`

Post-conditions: - Item cannot be submitted for approval until Purchase
and Inventory stages are complete. - Only approved items are available
in transactions. - Preserve stage history, actor, timestamp and
comments. - UOM conversions must be validated, deterministic and tested.

## A6. Rate Master

Fields: - item, location, UOM - rate - effective-from and effective-to
dates - optional customer category / price list - approval state and
audit history

Implement `RateService::getRate(item, location, uom, date)`: - Return
the latest approved rate effective on the requested date for the
authorized company/location. - Reject expired, future-inapplicable or
unapproved rates. - Define tie-breaking rules and overlapping
effective-date validation. - Return a clear domain error when no
eligible rate exists; do not silently choose an arbitrary or unapproved
rate. - Editing rates in quotation/SO requires a specific permission and
creates an audit event with original and overridden values plus reason.

## A7. Location, Department, Designation and Unit

-   Location: code, name, address, status and geo-coordinates.
-   Department and Designation: code, name and status.
-   Unit/UOM: code, name, decimal-allowed flag and status.
-   Validate geo-coordinates and scope by company.

## A8. Supporting CRM Masters

-   State → District → Block → PIN Code hierarchy.
-   Block and PIN Code source data will be provided by Tent Studio.
    Build a controlled Excel import with template, preview, row-level
    validation, error report and idempotent upsert rules.
-   Lead Sources: Reference, Website, Social Media, Tele-caller,
    IndiaMART, Just Dial, Field, Excel Import, Other.
-   Follow-up Outcomes: Call Disconnected, Not Available, Interested,
    Not Interested, Call Back Later, Meeting Fixed, Store Visited, and
    admin-configurable values.
-   Lead Stages: New, Contacted, Qualified, Deal, Quotation, Won, Lost.
-   Lost Reasons master.
-   Additional configurable masters may include customer category,
    campaign type, task priority and support-ticket category.

------------------------------------------------------------------------

# 5. Module B --- CRM-Marketing

## B1. Sales Teams and Targets

-   Teams with manager and members; hierarchy Sales Manager →
    BDE/Salesperson.
-   Targets by salesperson/team and monthly/quarterly period.
-   Target dimensions: value and/or quantity, optionally item group and
    location.
-   Achievement = approved Sales Order value by default; define how
    cancellations, returns, tax and partial orders are treated.
-   Reports for target vs achievement, progress and performance history.
-   Prevent overlapping duplicate target definitions for the same
    dimensions/period unless explicitly allowed.

## B2. Route Plan, Territory and Attendance

Route Plan: - Per salesperson and date. - Ordered list of areas, blocks,
PIN codes and/or customers. - Geo-coordinates and configurable geofence
radius (default 500 m). - Store planned order, planned visit windows and
status where configured. - Managers can configure whether an override is
allowed; overrides require permission, reason and audit.

Attendance: - Mark from mobile API with GPS and server timestamp. - If
no route plan exists for the day, attendance cannot be marked. -
Validate GPS coordinates and route-plan/employee scope on the server. -
Store accuracy, recorded coordinates, decision and any override
reason. - Do not treat a client-side geofence calculation as
authoritative. - Keep a geofence violation log for rejected attempts.

## B3. New Lead

Fields: - lead number and date - source - company/person name - contact
person - mobile number, duplicate check and duplicate warning - email -
address, state, district, block and PIN code - requirement/items of
interest - expected value - assigned user/team - stage - next follow-up
date - geo-coordinates for field entries - remarks - completeness flag
for integration-created leads - campaign and attribution fields where
applicable

Entry channels:

1.  **Field salesperson/mobile API:** entry against today's route plan.
    Server validates the GPS location against the applicable geofence;
    reject save when outside the allowed route/geofence, return a clear
    422 response and log the attempt.
2.  **IndiaMART/Just Dial API:** scheduled jobs pull leads where
    supported by the provider's API plan. Store API credentials
    encrypted per company. De-duplicate by provider lead ID and mobile.
    Save raw payload safely in `lead_api_logs` with secrets removed.
    Mark imported leads incomplete until required fields are completed.
    If only webhook access is supported by a provider plan, support a
    verified webhook path as configured.
3.  **Manual:** references, website, social media and tele-caller. PIN
    code selection should auto-fill state/district/block from the master
    where unambiguous.
4.  **Excel import:** downloadable template, column mapping, preview,
    row-level validation, duplicate handling, queued processing for
    large files, progress and downloadable error report. Valid rows may
    import while invalid rows are reported; never hide row errors.
5.  **Website and landing-page forms:** configurable forms,
    consent/privacy text, source and campaign attribution, spam/rate
    protection, honeypot or CAPTCHA integration where appropriate, and
    webhook/API submission.
6.  **Campaign/communication capture:** capture source and campaign
    metadata for supported email, SMS and WhatsApp links or forms
    without claiming tracking where no provider signal exists.

Follow-ups: - date/time - type: call, visit, email, WhatsApp, meeting -
assigned user - outcome tag from Follow-up Outcome master - notes - next
follow-up date - related lead/deal/quotation/customer where applicable -
attachments when permitted - reminders in-app and email through
scheduler - calendar day/week/month views per user/team - highlight
overdue follow-ups and support escalation rules

Required lead behaviors: - single and bulk lead transfer with reason,
from/to users, actor, timestamp and complete transfer history -
qualified lead can convert to Deal and then Sales Order; Quotation is
optional where permitted - prevent duplicate conversion and preserve
source links - validate user/company/branch scope on every transfer and
conversion

## B4. Existing Lead / Existing Customer Inquiry

-   Link to an approved Customer ledger.
-   Add multiple requested item lines: item, UOM, quantity and expected
    rate.
-   Follow-ups with calendar, outcome tags and store-visit status.
-   Qualified inquiry converts to Deal with all item details.
-   Once approved, may convert to Quotation.
-   Keep customer history and link to previous orders/quotations where
    authorized.

## B5. Deal / Opportunity

-   Deal number
-   customer/prospect
-   item lines: item, UOM, quantity, expected rate
-   expected close date
-   probability percentage
-   expected value
-   stage
-   owner/team
-   source lead and activity history
-   approval required before quotation/SO conversion
-   enforce valid stage transitions and record history
-   support lost reason and reopen permissions
-   do not allow totals to be trusted from client input

## B6. Quotation / Proposal

-   Generate from qualified/approved Deal.
-   Auto-fill item rates using approved Rate Master by authorized
    location, UOM and date.
-   Manual rate override requires permission, reason and audit.
-   Discount percentage, GST, CGST/SGST vs IGST based on configured
    place-of-supply logic, totals, terms, validity date and customer
    addresses.
-   Revisions Rev 0, Rev 1... with immutable revision history.
-   Generate PDF and email to customer through configured mail
    transport.
-   Follow-ups with outcome tags and quotation status.
-   Approval required before conversion to Sales Order.
-   Prevent conversion from expired or unapproved quotations unless an
    authorized, audited exception is configured.
-   Store calculation snapshots so historic documents do not silently
    change when master rates/taxes change later.

## B7. Sales Order / Customer Order / Order Acknowledgment

Sources: - approved Deal - approved Quotation - Direct SO for an
eligible regular customer

Fields: - SO number/date - approved customer ledger - billing/shipping
address snapshots - customer PO number/date - delivery date - item
lines: item, UOM/conversion, quantity, rate, discount, tax and amount -
totals, terms and notes - credit limit result and any approved
override - source document links

Rules: - Auto-pick approved, effective Rate Master rate, or copy
approved source-document pricing according to explicit conversion
policy. - Check credit limit and warn/block according to company
settings. - Generate Order Acknowledgment PDF. - Approval triggers Sales
Invoice generation through `DocumentApproved`. - Invoice creation must
be idempotent: one invoice for a fully invoiced SO in the initial scope,
with unique source constraints and transaction protection. - Design
tables to allow partial invoicing in future, even if the first release
creates one full invoice per SO. - Do not claim inventory posting or
full double-entry accounting unless those features are separately
implemented and tested.

## B8. Sales Invoice

Initial scope: - invoice document generated from approved Sales Order -
independent number series - link to source SO and lines -
customer/address/tax/pricing snapshots - invoice PDF and audit history -
lifecycle status and permissions - idempotent generation and retry
handling

Before implementation, document whether stock and accounting postings
are in scope. Default assumption: invoice document and linkage only,
without claiming a full accounting ledger or inventory issue posting.
Build clear service boundaries to extend later.

------------------------------------------------------------------------

# 6. New CRM Skills and Advanced Features

These features extend the original requirements. Implement them after
the core CRM and sales flows are stable, unless the phase plan
explicitly brings forward a prerequisite.

## 6.1 Customer 360° Profile

Provide one authorized customer view containing: - customer master and
contacts - leads and inquiries - tasks, calls, meetings and follow-ups -
deals and stage history - quotations and revisions - sales orders and
invoices - communication history and campaign attribution - credit terms
and credit-limit status - documents and notes - support tickets where
the support module is enabled

Respect company/branch scopes and field-level permissions. Do not expose
sensitive bank or personal details unnecessarily.

## 6.2 AI-assisted lead scoring and sales recommendations

Design an optional AI integration behind an interface/provider
abstraction. The CRM must remain usable when AI is disabled or
unavailable.

-   Configurable lead scoring based on explicit business signals such as
    source, response history, requirement completeness, engagement and
    time since last contact.
-   Explain score factors to authorized users.
-   Suggest next actions, follow-up timing and draft messages.
-   Summarize CRM activities from available stored data.
-   Forecast deal probability only as an advisory estimate.
-   Never automatically approve a transaction, alter approved pricing,
    change permissions, or send customer communications without the
    configured human approval/consent policy.
-   Do not send unnecessary personal data or confidential records to an
    external AI provider.
-   Log provider, model/config version, timestamp, input data categories
    and result metadata without logging secrets or unnecessary sensitive
    content.
-   Include opt-out/disable controls and graceful fallback.
-   AI-generated text must be reviewed before sending unless a company
    explicitly configures an approved automation policy.

## 6.3 Omnichannel communications

Add integration-ready communication services for: - WhatsApp Business
provider - email - SMS - click-to-call / telephony provider where
available - meeting links/calendar integrations where configured

Requirements: - centralized customer communication timeline - templates
with variables and preview - message status where provider callbacks
supply it - opt-in/opt-out, consent and suppression handling -
queue-based sending, retry policy, provider rate limits and idempotency
keys - webhook signature validation and replay protection -
delivery/error logs with secrets and message content appropriately
redacted - per-company provider settings stored encrypted - test/sandbox
mode and clear "not connected" state - do not claim a message was
delivered based only on a successful local queue dispatch

## 6.4 Marketing Campaign Manager

-   Campaigns, campaign type, dates, owner, budget, target audience and
    status.
-   Campaign membership and lead attribution.
-   UTM/source capture for supported channels.
-   Campaign-to-lead, deal and approved-order conversion reports.
-   Audience filters with authorization and consent/suppression checks.
-   Drip/nurture sequences with configurable triggers, delays, exit
    criteria and frequency caps.
-   Preview and approval workflow for bulk sends.
-   Unsubscribe and suppression lists where applicable.
-   Track provider-confirmed delivery/click/conversion signals only when
    actually available.
-   Avoid duplicate sends using campaign/member/action idempotency.

## 6.5 Marketing automation and workflow builder

Provide a safe, configurable workflow engine with: - triggers: lead
created, stage changed, follow-up overdue, deal won/lost, quotation
nearing expiry, order approved - conditions: source, stage, branch,
owner, expected value and approved configurable fields - actions: assign
lead, create task, schedule reminder, notify manager, enqueue approved
communication - execution history, failure reason, retries and
pause/resume - versioned workflow definitions - loop protection,
execution limits and permission validation - preview/dry-run mode for
administrators - prohibit workflow actions from bypassing approval,
consent, tenant scope or permission checks

## 6.6 SLA and escalation management

-   Configurable first-response and follow-up SLAs by
    source/team/priority.
-   Due-time calculation using configured business hours/holidays where
    enabled.
-   Escalation to manager on overdue items.
-   SLA breach logs and dashboard metrics.
-   Pause/stop rules for closed, disqualified or transferred records.
-   All automated reassignment/escalation events are auditable.

## 6.7 Tasks and productivity

-   Personal/team task lists, priorities, deadlines, reminders,
    recurring tasks where appropriate, links to CRM records and
    completion notes.
-   Kanban/list/calendar views.
-   Assignment and reassignment history.
-   Permission-controlled comments and attachments.
-   Overdue-task reports and notifications.

## 6.8 Customer portal

Optional module: - secure customer login and account-scoped access -
view/download permitted quotations and order acknowledgments -
accept/reject quotations only if company workflow allows; record
actor/time and any comments - view invoice documents and order status -
update contact preferences through a controlled verification process -
no access to internal comments, other customers, internal rates or
staff-only notes - customer access can be revoked and audited

## 6.9 Document management and e-signature integration

-   Attachments linked to authorized records.
-   File validation, size limits, malware scanning integration point and
    private storage.
-   Version history, document category, uploader and timestamps.
-   Signed URL or authorized controller downloads with expiry.
-   Optional e-signature provider adapter; do not simulate a legal
    signature if no provider is configured.
-   Retention and deletion policy configurable by document type.

## 6.10 Support/ticket module (optional extension)

-   Ticket number, customer, category, priority, status, owner/team,
    description, attachments and resolution.
-   SLA targets and escalation.
-   Link tickets to leads/customer/order/invoice where appropriate.
-   Customer-visible and internal notes kept separate.
-   Audit all state changes and enforce tenant scope.

## 6.11 Incentives and commission (optional extension)

-   Configurable commission rules based on approved sales, product
    group, salesperson/team and time period.
-   Define treatment of cancellations, credit notes, returns and taxes.
-   Approval workflow before payout/export.
-   Recalculate only through versioned, auditable runs; preserve
    historical rule versions.
-   Do not expose payroll or sensitive payout data to unauthorized
    roles.

## 6.12 Forecasting and advanced analytics

-   Funnel: Lead → Deal → Quotation → Sales Order.
-   Conversion by source, campaign, team, salesperson, branch and time.
-   Sales forecast by weighted deal value and expected close date.
-   Target vs achievement.
-   Lost-deal reasons, stage aging, lead response time, overdue
    follow-ups and SLA breaches.
-   Quote-to-order conversion and average sales cycle.
-   Branch/rate/stock-parameter reports.
-   Clearly label forecasted values separately from actual approved
    sales.
-   Allow filtering by company, location, user, team and date range.
-   Export only after authorization; audit exports.

## 6.13 Territory and route optimization

-   Territory mapping by state/district/block/PIN code/customer.
-   Assign territory owners and coverage.
-   Route ordering suggestions based on configured coordinates and an
    optional mapping provider.
-   Respect provider terms, rate limits and privacy.
-   Route suggestions are advisory; retain manager/salesperson control.
-   GPS capture must be consented, purpose-limited and visible to
    authorized roles.

## 6.14 Offline-capable mobile/PWA

-   Responsive PWA or a native/Flutter app may consume `/api/v1`;
    initial assumption is a responsive PWA/API unless the owner selects
    native/Flutter.
-   Cache only permitted, minimal lookup data.
-   Use `updated_since`, pagination and stable IDs for sync.
-   Track client-generated UUID/idempotency keys for offline-created
    records.
-   Define conflict policy for edits, duplicate submissions and stale
    master data.
-   Never let offline data bypass server-side permissions, geofencing,
    approval rules or rate checks.
-   Clearly display pending sync, conflict and failed sync states.

------------------------------------------------------------------------

# 7. Dashboards and Reports

## 7.1 Sales dashboard

-   leads by stage and source
-   conversion funnel
-   today's and overdue follow-ups
-   target vs achievement
-   top salespeople and teams
-   deals by probability and expected close date
-   quote conversion
-   branch performance
-   campaign attribution and ROI where source data supports it

## 7.2 Required reports

All reports should support relevant filters by company, location, user,
team and date range; export to Excel/PDF when permitted.

-   Lead register
-   Lead source analysis
-   Follow-up register and outcome analysis
-   Lead transfer history
-   Deal pipeline
-   Quotation register and conversion percentage
-   Sales Order register
-   Pending Sales Orders
-   Salesperson attendance and route adherence
-   Geofence violation log
-   Target vs Achievement
-   Item list with stock parameters
-   Rate list by branch
-   Pending approvals
-   Campaign performance
-   SLA compliance and overdue follow-ups
-   AI scoring/recommendation activity where enabled
-   Integration sync failures and webhook status
-   Audit activity report

Use pagination and query optimization. Avoid loading all records into
memory for large exports; queue large exports and provide authorized
download links.

------------------------------------------------------------------------

# 8. Mobile API --- `/api/v1`

Use Sanctum device tokens with revocation, scoped authorization and
throttling.

Minimum endpoints: - login/logout - profile and device/session
management - today's route plan - mark attendance with GPS - lead CRUD
with server-side geofence validation - lead list and filters - follow-up
create/list/calendar - deal creation and authorized updates - quotation
view - approved master lookup: items, UOMs, PIN codes, sources,
outcomes - paginated synchronization with `updated_since` - supported
upload endpoints for authorized attachments - API health/status endpoint
that reveals no secrets

Requirements: - consistent JSON envelope and validation error
structure - correct HTTP status codes - API versioning and
documentation - rate limits by user/token/IP as appropriate - pagination
and bounded query parameters - idempotency for create/convert
operations - server-derived company/branch context - resource-level
authorization and field validation - request correlation IDs and
redacted logs - do not return unapproved items, ledgers or rates -
geofence violation returns HTTP 422 with a clear message and logs the
attempt - attendance without a route plan returns a clear
validation/business-rule response - document example requests/responses
and error codes using Scribe/OpenAPI

------------------------------------------------------------------------

# 9. Integration Hub

Build provider adapters and configuration rather than coupling business
logic directly to vendor SDKs.

Integrations to support: - IndiaMART - Just Dial - WhatsApp Business
provider - email transport - SMS provider - telephony/click-to-call
provider where available - website lead capture/webhooks - optional AI
provider - optional mapping/route provider - optional e-signature
provider

Shared integration requirements: - per-company settings - encrypted
secrets at rest - permission-protected settings screens - no secrets in
logs, exception messages, exports or audit payloads - provider status
and last successful sync - inbound/outbound logs with redaction -
webhook signature verification and replay prevention - queue-based
processing with retries/backoff and dead-letter/manual retry handling -
idempotency keys and unique provider IDs - sync watermark/cursor where
supported - dry-run/test connection action - clear distinction between
configured, connected, degraded and disabled - mock-based tests when
credentials are not available - document exact provider prerequisites
and API plan limitations

------------------------------------------------------------------------

# 10. Security, Privacy and Compliance

Implement security as a cross-cutting requirement.

1.  Authorization: enforce Policies on every read/write action, Livewire
    action, API route, bulk operation, export, download and background
    job.
2.  Tenant isolation: company/branch scoping at query, service and
    policy layers; test for IDOR and cross-tenant access.
3.  Authentication: secure session settings, CSRF protection for web
    forms, Sanctum token revocation, password policy and appropriate
    login throttling.
4.  Validation: Form Requests, allowlisted fields, safe enum validation,
    server-side business validation and output escaping.
5.  Secrets: encrypted provider credentials, environment variables for
    infrastructure secrets, rotation procedure and redaction.
6.  Uploads: validate MIME/type/size, randomize stored filenames,
    private storage by default, authorize downloads, prevent path
    traversal and executable uploads.
7.  Webhooks: signature validation, timestamp/replay protection, rate
    limits and idempotent processing.
8.  Data privacy: collect only needed personal data; record consent and
    opt-out where applicable; define retention and deletion rules;
    restrict exports.
9.  Audit: immutable-to-users audit trail for critical actions and
    configuration changes.
10. Financial correctness: decimal values, server-side tax/rate
    calculations, transaction boundaries and immutable document
    snapshots.
11. Abuse prevention: throttling, input bounds, pagination caps,
    anti-spam controls and safe error responses.
12. Operational security: production debug off, secure cookies/HTTPS,
    least-privilege database credentials, backups, restore tests and
    dependency updates.
13. AI privacy: only send necessary information to configured providers;
    offer disable/opt-out; do not send secrets; human review for
    consequential actions.
14. Do not assert legal/regulatory compliance merely because controls
    exist. Document relevant obligations for owner review.

------------------------------------------------------------------------

# 11. Database and Data Integrity Rules

-   Use foreign keys and indexes for company/location IDs, foreign keys,
    codes, mobile numbers, approval status, source IDs and frequently
    filtered dates.
-   Use unique constraints for company-scoped codes, document numbers,
    provider lead IDs, idempotency keys and invoice-source
    relationships.
-   Use `decimal` for money and quantities.
-   Define deletion behavior deliberately; financial history should not
    cascade-delete.
-   Use enums or validated reference tables consistently; do not create
    conflicting representations.
-   Store source-document snapshots for historic quotes/orders/invoices.
-   Add indexes based on actual query patterns and verify with query
    plans for high-volume paths.
-   Use DB transactions for multi-table operations.
-   Prevent race conditions in number series, duplicate import,
    conversion and invoice generation.
-   Add migration rollback guidance and avoid destructive production
    migrations without an explicit safe plan.
-   Seed roles/permissions, chart of accounts, UOMs, lead sources,
    follow-up outcomes and a demo company with sample data.
-   Do not seed real personal data or live credentials.

Minimum conceptual entities include: - companies, locations,
user-company/location assignments - departments, designations, units/UOM
conversions - chart-of-account groups, ledgers, ledger addresses, ledger
contacts - item groups, items, item aliases, item stage history, item
location parameters - rate masters / price lists - number series -
approvals and approval configuration - comments, activity logs and
notifications - states, districts, blocks and PIN codes - lead sources,
outcomes, stages and lost reasons - sales teams, memberships, targets,
route plans, route stops, attendance, geofence logs - leads, lead
items/interests, transfer history, API lead logs - follow-ups, tasks and
activity history - deals and deal items - quotations and
revisions/items - sales orders/items - sales invoices/items - campaigns,
campaign memberships, attribution and automation workflows/executions -
communication templates, message jobs/logs, consent/suppression
records - integration settings, sync logs, webhook logs -
documents/attachments and optional support tickets/commission records

The developer must produce an entity-relationship diagram or documented
relationship map before completing the schema phase.

------------------------------------------------------------------------

# 12. Quality, Testing and Observability

## 12.1 Automated tests

Use Pest. At minimum cover:

-   model relationships, casts, enums and validation
-   role/permission matrix and Policy denials
-   company/branch isolation for UI, API, exports and jobs
-   approval state machine and rejection/resubmission history
-   only approved masters in transaction dropdowns/APIs
-   item stage completeness and Operations comment-only access
-   rate selection by item/location/UOM/date and rejection when no
    approved rate exists
-   number series uniqueness under concurrent access where test
    infrastructure allows
-   lead duplicate warnings and provider idempotency
-   Excel row validation, valid-row import and error reporting
-   lead transfers preserving full history
-   route-plan requirement for attendance
-   server-side geofence acceptance/rejection and violation logging
-   lead/deal/quotation/SO conversion preserving all item lines
-   optional quotation path
-   tax calculation and financial rounding
-   credit-limit warn/block behavior
-   quotation revision immutability
-   Sales Order approval generating exactly one linked invoice
-   notification dispatch and failed-job behavior
-   integration webhook signature/replay handling
-   consent/opt-out suppression for bulk communication
-   AI-disabled fallback and human approval controls
-   file upload/download authorization
-   export authorization and bounded data access

## 12.2 Quality commands

Use commands compatible with the actual project and document their
outcomes, such as:

``` bash
php artisan test
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan route:list
php artisan migrate:status
```

Do not run conflicting test commands redundantly if the project uses one
standard entry point. Record skipped checks and reasons.

## 12.3 Performance and reliability

-   Queue imports, exports, email/SMS/WhatsApp, integration sync and
    expensive reports.
-   Use chunked processing and progress tracking.
-   Add retry/backoff and failure visibility.
-   Prevent N+1 queries using eager loading and query review.
-   Use caching only with tenant-aware cache keys and explicit
    invalidation.
-   Add health checks and structured, redacted logs.
-   Monitor failed jobs, integration health, queue backlog and scheduler
    execution.
-   Provide backup/restore documentation and perform a restore test in a
    non-production environment.

------------------------------------------------------------------------

# 13. UI/UX Requirements

-   Build a responsive admin layout using Blade, Livewire, Tailwind and
    Alpine.
-   Provide consistent page headers, breadcrumbs, filters, tables,
    pagination, create/edit forms, status badges, approval timelines and
    empty/error/loading states.
-   Use accessible labels, keyboard navigation, readable contrast and
    clear validation messages.
-   Use server-side pagination and authorization for large tables.
-   Forms should preserve entered values on validation errors where
    safe.
-   Show approval state and next action clearly.
-   Use confirmation for destructive or irreversible actions.
-   Make bulk actions permission-aware and auditable.
-   Provide a dashboard that changes by role, company and branch.
-   Use calendar day/week/month views for follow-ups.
-   Do not rely on color alone to communicate status.
-   Provide mobile-friendly lead capture, route plan, attendance and
    follow-up experiences.
-   Do not expose controls to unauthorized users as the only security
    mechanism; server-side authorization remains mandatory.

------------------------------------------------------------------------

# 14. Notifications and Scheduler

Notifications: - approval pending - approved/rejected - follow-up
reminders - overdue follow-up/task escalation - lead
assigned/transferred - integration sync failure - quotation expiry -
optional campaign/workflow status

Channels: in-app and email initially; SMS/WhatsApp only when configured
and permitted.

Scheduler: - API lead sync - reminder generation - overdue detection and
SLA escalation - campaign/workflow scheduling - integration health
checks - cleanup consistent with retention policy

Use timezone-aware scheduling, idempotent jobs, locks where needed, and
documented production scheduler setup.

------------------------------------------------------------------------

# 15. Company Settings

Provide permission-protected settings for: - approval levels and
approver assignment - default item approval route - geofence radius and
override policy - credit-limit warn/block behavior - document number
series and financial-year rules - provider API keys and integration
status - AI provider enabled/disabled and approved use cases -
communication consent/opt-out and frequency limits - campaign and
workflow defaults - timezone, date display and currency - document
templates, email settings and company branding - data retention and
export permissions

Audit all sensitive setting changes. Encrypt credentials and mask
secrets after saving.

------------------------------------------------------------------------

# 16. Assumptions to State Before Coding

Use these defaults unless the project owner confirms otherwise:

1.  Approval is single-level by default and configurable for multiple
    sequential levels.
2.  Item approval routes to Accounts by default. Operations is
    view/comment-only and cannot edit.
3.  IndiaMART/Just Dial credentials and API plans are supplied by the
    owner; use provider mocks until live access exists.
4.  Geofence radius defaults to 500 metres. Manager override is disabled
    by default; if enabled, require permission, reason and audit.
5.  Sales Invoice initially means invoice document generation and
    linkage only. Full stock and accounting postings are not assumed.
6.  Mobile delivery initially uses responsive PWA/API design;
    native/Flutter can be a later client.
7.  AI functions are optional and disabled until the owner configures a
    provider and data policy.
8.  Communication providers are optional and require company
    credentials, approved templates and applicable consent.
9.  The provided Block/PIN Code data comes from Tent Studio and will be
    imported using the template.
10. No live credentials or real customer data should be embedded in code
    or seeders.

If any assumption conflicts with the existing codebase, report the
conflict and request review before making an irreversible architectural
decision.

------------------------------------------------------------------------

# 17. Acceptance Criteria --- Core Requirements

All criteria must pass before the relevant release is marked complete.

## Masters and approvals

-   [ ] Unapproved items, ledgers and rates never appear in transaction
    dropdowns or APIs.
-   [ ] Operations can view an Item and add a comment but receives 403
    on edits.
-   [ ] Item cannot reach approval until Purchase and Inventory stages
    are complete.
-   [ ] Rejections preserve comments, actor, timestamp and stage
    history.
-   [ ] RateService returns only approved, effective rates for the
    correct item/location/UOM/date.
-   [ ] Document numbers are unique and safe under concurrent
    allocation.
-   [ ] Approval/rejection is recorded with user, time, level and
    comment.
-   [ ] Company/branch isolation prevents unauthorized cross-tenant
    access.

## CRM and field sales

-   [ ] Salesperson outside the route-plan geofence cannot save a field
    lead; API returns 422 and logs the attempt.
-   [ ] Salesperson without today's route plan cannot mark attendance.
-   [ ] IndiaMART/Just Dial leads are not duplicated on resync and
    remain incomplete until required fields are completed.
-   [ ] Excel import reports row-level errors and imports valid rows
    according to the documented partial-success policy.
-   [ ] Lead transfer retains complete history and reason.
-   [ ] PIN Code lookup fills state/district/block where a unique
    mapping exists.
-   [ ] Follow-up reminders and overdue views use the configured
    timezone and scheduler.
-   [ ] Qualified Lead → Deal → Quotation → SO preserves all item lines;
    permitted flows can skip Quotation.

## Sales

-   [ ] Quotation and SO use the approved, effective Rate Master rate
    for the authorized branch unless an audited, permitted override is
    used.
-   [ ] Tax calculations are covered by unit/feature tests.
-   [ ] Credit-limit behavior follows company configuration.
-   [ ] Quotation revisions preserve historical versions.
-   [ ] Approving a Sales Order creates exactly one linked Sales Invoice
    under the initial full-invoice policy.
-   [ ] Reprocessing events/jobs cannot create duplicate invoices.
-   [ ] Historical documents retain their pricing and tax snapshots.

## Security and quality

-   [ ] Unauthorized users cannot access data through direct URL, API,
    Livewire action, export, attachment or background job.
-   [ ] Provider secrets never appear in logs, audit data or UI
    responses.
-   [ ] Webhooks validate signatures and resist replay/duplicate
    processing.
-   [ ] Communication opt-outs/suppression rules are respected.
-   [ ] AI-disabled operation works without breaking core CRM.
-   [ ] Critical requirements have automated tests.
-   [ ] Formatting and static-analysis results are reported accurately.
-   [ ] Setup, environment, queue, scheduler, deployment, backup and
    restore documentation exists.

## Advanced feature acceptance criteria

-   [ ] Campaign attribution is stored only when supported by the
    incoming source data.
-   [ ] Automation workflows are versioned, auditable,
    permission-checked and loop-protected.
-   [ ] Customer Portal users cannot access another customer's records
    or internal notes.
-   [ ] Offline synchronization detects duplicate submissions and
    handles conflicts according to the documented policy.
-   [ ] Reports clearly distinguish actual approved results from
    forecasts.
-   [ ] Large exports run through queues and are authorized at request
    and download time.

------------------------------------------------------------------------

# 18. Required Deliverables

At the end of the relevant phases, provide:

1.  Repository and architecture summary.
2.  Folder/module structure.
3.  Database schema and relationship diagram/document.
4.  Migrations, factories, seeders and test data.
5.  Roles/permissions matrix.
6.  Approval state machine and configuration guide.
7.  Route and endpoint inventory.
8.  API documentation and sample payloads.
9.  UI page inventory and key workflows.
10. Integration setup documentation with credential placeholders only.
11. Pest tests and actual test results.
12. Pint and PHPStan/Larastan results.
13. `.env.example` with no secrets.
14. README setup guide, including queue/scheduler/storage commands.
15. Deployment, backup/restore and troubleshooting instructions.
16. Known gaps, assumptions, external dependencies and owner decisions
    still needed.

------------------------------------------------------------------------

# 19. Mandatory Build Phases

Complete in order. Stop and summarize after each phase.

1.  **Foundation:** inspect repository; Laravel 13 setup/compatibility;
    auth; Livewire/Tailwind layout; roles/permissions; company/location
    scoping; number series; approval engine; comments; audit logs;
    initial tests.
2.  **Masters:** Company, Chart of Accounts, Location, Department,
    Designation, Unit, Item Group, State/District/Block/PIN Code and
    Tent Studio import.
3.  **Ledger Master:** customer/supplier/bank/cash/general with approval
    workflows and permission restrictions.
4.  **Item Master:** department-wise three-stage workflow, multi-UOM,
    aliases, location stock parameters, Operations comment-only access.
5.  **Rate Master:** branch-wise rates, approvals, `RateService` and
    tests.
6.  **Sales Team, Targets, Route Plan, Attendance:** including "no route
    plan → no attendance".
7.  **New Lead:** manual entry, Excel import, follow-ups, calendar,
    reminders, lead transfer and history.
8.  **Mobile API + Geofencing:** Sanctum, route-plan geofence validation
    on lead save, attendance and API documentation.
9.  **IndiaMART / Just Dial:** scheduled pull/webhook jobs according to
    provider capabilities, deduplication, incomplete-lead flag and logs.
10. **Existing Lead & Deal:** item lines, approval and conversion rules.
11. **Quotation:** deal-based generation, rate lookup, GST, revisions,
    PDF/email and follow-ups.
12. **Sales Order:** deal/quotation/direct sources, rate lookup, credit
    check, order acknowledgment, approval and idempotent auto-invoice.
13. **Dashboards and Reports:** all baseline reports and authorized
    exports.
14. **Customer 360 + Tasks + SLA:** unified customer profile,
    productivity tools and escalation.
15. **Campaign Manager + Marketing Automation:** campaign attribution,
    communication templates, workflow builder and consent controls.
16. **Omnichannel Integrations:** WhatsApp, email, SMS, telephony and
    website lead capture through provider adapters.
17. **Advanced Field Sales + PWA Sync:** territories, route suggestions,
    offline synchronization and conflict resolution.
18. **AI Assistance + Forecasting:** optional lead scoring, activity
    summaries, next-action suggestions and sales forecasting with
    privacy controls.
19. **Optional Extensions:** Customer Portal, Document
    Management/e-signature, Support Tickets and Incentive/Commission
    modules.
20. **Hardening and Launch:** full Pest suite, static analysis,
    formatting, performance/security review, seed data, CI pipeline,
    README, deployment and restore validation.

Do not start later phases before completing and reporting the current
phase. Where a phase depends on external credentials or paid services,
implement and test the adapter with mocks, document the remaining live
verification, and continue only after the phase's locally testable
acceptance criteria pass.

------------------------------------------------------------------------

# 20. Start Here --- Phase 1 Instructions

**Begin Phase 1 now. Do not implement the entire application in one
response.**

1.  Inspect the repository and report current
    framework/runtime/dependency state.
2.  Identify compatibility risks for Laravel 13, PHP 8.3+, Livewire,
    Sanctum and the required packages.
3.  State the assumptions from Section 16.
4.  Propose the Phase 1 folder structure and core domain boundaries.
5.  Create the initial migrations for companies, locations, user
    assignments, number series, approval configuration/records, comments
    and required audit support. Adjust table ordering and schema details
    to fit the existing repository without losing requirements.
6.  Implement authentication integration, tenant/branch context,
    policies, roles/permissions seeders, number-series service and
    approval engine skeleton with meaningful tests.
7.  Add audit events and ensure secrets are redacted.
8.  Run migrations and relevant tests in the available environment. If a
    database or dependency is unavailable, report the exact blocker and
    provide reproducible commands; do not claim success.
9.  Show the files changed, key classes, routes, migrations, test
    outcomes, unresolved risks and how to review the work.
10. **Stop after Phase 1 and wait for approval before continuing to
    Phase 2.**

The implementation is complete only when the code, migrations,
authorization, tests and documentation support the stated requirements.
A UI mockup or generated code that has not been integrated and tested is
not a completed feature.
