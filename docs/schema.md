# Schema and relationship map (Phases 1–9)

Phase 1 (Foundation) tables are in the first diagram, Phase 2 (Masters) in the second, Phase 3 (Ledgers) in the third, Phase 4 (Items) in the fourth, Phase 5 (Rates) in the fifth, Phase 6 (field sales) in the sixth, Phases 7 (leads) and 8 (the API's two tables) after it.
Later phases add their own
tables to this map (CRM, Sales). All business tables carry `company_id`; branch-owned ones also carry `location_id`.

```mermaid
erDiagram
    companies ||--o{ locations : "has branches"
    companies ||--o{ company_user : "members"
    users ||--o{ company_user : "belongs to"
    locations ||--o{ location_user : "assigned"
    users ||--o{ location_user : "restricted to"
    companies ||--o{ number_series : "counters"
    locations ||--o{ number_series : "per branch"
    companies ||--o{ approval_levels : "configured chain"
    companies ||--o{ approvals : "decisions"
    users ||--o{ approvals : "submitted_by / acted_by"
    companies ||--o{ comments : "owns"
    users ||--o{ comments : "authored"
    companies ||--o{ settings : "per company"
    companies ||--o{ activity_log : "audit (company_id)"
    users ||--o{ activity_log : "causer"
    users }o--o{ roles : "model_has_roles"
    roles }o--o{ permissions : "role_has_permissions"
    users ||--o{ notifications : "notifiable"

    companies {
        bigint id PK
        string code UK
        string gstin
        string pan
        tinyint financial_year_start_month "default 4 (April)"
        char base_currency "INR"
    }
    locations {
        bigint id PK
        bigint company_id FK
        string code "unique per company"
        decimal latitude
        decimal longitude
        string status "active|inactive; not an approval document"
    }
    company_user {
        bigint company_id PK
        bigint user_id PK
        bool all_locations "true = every branch"
    }
    number_series {
        bigint id PK
        bigint company_id FK
        bigint location_id FK
        string document_type
        string financial_year "e.g. 26-27"
        string prefix
        bigint last_number
    }
    approval_levels {
        bigint id PK
        bigint company_id FK
        string document_type
        tinyint level
        string approver_role
        bigint approver_user_id FK
        smallint due_hours
    }
    approvals {
        bigint id PK
        bigint company_id FK
        string approvable_type "morph alias"
        bigint approvable_id
        smallint cycle "one per submission"
        tinyint level
        string status "pending|approved|rejected|cancelled"
        bool is_parallel
        string document_hash "sha256 at submission"
        timestamp due_at
        bool overridden
        bigint submitted_by FK
        bigint acted_by FK
    }
    comments {
        bigint id PK
        bigint company_id FK
        string commentable_type "morph alias"
        bigint commentable_id
        string visibility "internal|customer"
        text body
    }
```

## Phase 2: Masters

```mermaid
erDiagram
    companies ||--o{ account_groups : "chart of accounts"
    account_groups ||--o{ account_groups : "parent_id"
    companies ||--o{ item_groups : "item hierarchy"
    item_groups ||--o{ item_groups : "parent_id"
    companies ||--o{ departments : ""
    companies ||--o{ designations : ""
    companies ||--o{ units : ""
    companies ||--o{ states : "own copy of the 36 states/UTs"
    states ||--o{ districts : ""
    districts ||--o{ blocks : ""
    states ||--o{ pin_codes : ""
    districts ||--o{ pin_codes : ""
    blocks |o--o{ pin_codes : "optional"
    companies ||--o{ import_batches : "uploads"
    users ||--o{ import_batches : "created_by"
    import_batches ||--o{ import_batch_errors : "rejected rows"

    account_groups {
        bigint id PK
        string code "unique per company"
        bigint parent_id FK
        string nature "asset|liability|income|expense, inherited from the root"
        bool is_system "standard groups are locked"
    }
    item_groups {
        bigint id PK
        string code "unique per company"
        bigint parent_id FK
    }
    units {
        bigint id PK
        string code "unique per company"
        bool decimal_allowed
    }
    states {
        bigint id PK
        string code "GJ"
        char gst_code "24, unique per company"
        string name
    }
    districts {
        bigint id PK
        bigint state_id FK
        string name "unique per state"
    }
    blocks {
        bigint id PK
        bigint district_id FK
        string name "unique per district"
    }
    pin_codes {
        bigint id PK
        char pin_code
        bigint state_id FK
        bigint district_id FK
        bigint block_id FK "nullable"
        string area "locality, '' = whole PIN"
    }
    import_batches {
        bigint id PK
        bigint company_id FK
        string importer "geography"
        string status "uploaded|checking|checked|queued|processing|completed|failed|cancelled"
        int total_rows
        int created_count
        int updated_count
        int unchanged_count
        int error_count
        json summary "counters, preview, ignored columns"
    }
    import_batch_errors {
        bigint id PK
        bigint import_batch_id FK
        int row_number "spreadsheet row, headings = 1"
        string phase "check|apply"
        json messages
        json raw "cells as uploaded"
    }
```

The plain masters (departments, designations, units, geography, account and item groups, branches) are **not**
approval documents: they have no `approval_status`. Ledgers and items (Phases 3–4) are.
Geography is per company: each company loads its own States → PIN codes (states are seeded from `StateCatalog`),
so one company's import can never change another's data. Districts, blocks and PIN rows have no natural code in the
source data, so they are keyed by name (the one documented exception to "a code on every master"). Import details:
[imports.md](imports.md).

## Phase 3: Ledgers

```mermaid
erDiagram
    companies ||--o{ ledgers : ""
    account_groups ||--o{ ledgers : "filed under"
    ledgers ||--o{ ledger_addresses : "billing / shipping"
    ledgers ||--o{ ledger_contacts : ""
    ledgers ||--o| ledger_bank_details : "sensitive"
    states ||--o{ ledger_addresses : ""
    districts |o--o{ ledger_addresses : ""
    companies ||--o{ payment_methods : "own list"
    ledgers }o..o{ payment_methods : "payment_preferences (codes)"
    ledgers ||--o{ approvals : "morph: ledger"

    ledgers {
        bigint id PK
        string code "account code, unique per company"
        string ledger_type "customer|supplier|bank|cash|expense|general (fixed for life)"
        string name "unique per company + type"
        bigint account_group_id FK
        string customer_kind "b2b|b2c (customers)"
        char gstin "15, check character verified"
        char pan
        smallint credit_days "customers, suppliers"
        decimal credit_limit "customers"
        smallint lead_time_days "suppliers"
        json payment_preferences "list of payment method codes"
        string approval_status "draft|pending|approved|rejected"
        string status "active|inactive"
    }
    ledger_addresses {
        bigint id PK
        bigint ledger_id FK
        bool is_billing
        bool is_shipping
        bool is_default_billing
        bool is_default_shipping
        bigint state_id FK
        bigint district_id FK "nullable"
        char pin_code
        char gstin "registration of this address"
    }
    ledger_contacts {
        bigint id PK
        bigint ledger_id FK
        string name
        string mobile
        string email
        bool is_primary
    }
    ledger_bank_details {
        bigint id PK
        bigint ledger_id FK "unique: one account per ledger"
        string bank_name
        text account_number "ENCRYPTED"
        char account_last4 "what everyone else sees"
        char account_number_hash "keyed hash: fingerprint and duplicate check"
        char ifsc
    }
```

A ledger is the **party / account master**: it posts nothing and is not a double-entry ledger. Which part of the chart of
accounts a ledger may be filed under depends on its type (`LedgerType::groupRootCodes()`):

| Type | Filed under (the standard group or anything beneath it) |
|---|---|
| Customer | Sundry Debtors (1160) |
| Supplier | Sundry Creditors (2230) |
| Bank | Bank Accounts (1110) or Bank OD Accounts (2310) |
| Cash | Cash-in-Hand (1120) |
| Expense | Direct (4100), Indirect (4200) Expenses or Purchase Accounts (4300) |
| General | any group |

Addresses, contacts and the bank account are covered by the **ledger's own approval**: they are edited only while the
ledger is editable, and a change after submission blocks approval (the fingerprint includes them; for the bank account it
uses the keyed hash, never the number).

## Phase 4: Items

```mermaid
erDiagram
    companies ||--o{ items : ""
    item_groups ||--o{ items : "filed under"
    units ||--o{ items : "base unit"
    items ||--o{ item_uoms : "other units"
    units ||--o{ item_uoms : ""
    items ||--o{ item_aliases : "other names"
    items ||--o{ item_location_parameters : "stock levels"
    locations ||--o{ item_location_parameters : "per branch"
    items ||--o{ item_stage_history : "append-only"
    users |o--o{ item_stage_history : "actor"
    items ||--o{ approvals : "morph: item"

    items {
        bigint id PK
        string code "nullable until Inventory assigns it; unique per company"
        string name "unique per company"
        bigint item_group_id FK
        bigint base_unit_id FK
        decimal po_tolerance_percent "0-100"
        string hsn_code "4, 6 or 8 digits"
        decimal gst_rate
        string workflow_stage "purchase_draft|inventory_pending|approval_pending|approved"
        string approval_status "draft|pending|approved|rejected (the shared engine)"
        string status "active|inactive"
    }
    item_uoms {
        bigint id PK
        bigint item_id FK
        bigint unit_id FK "unique per item"
        decimal factor "base units in ONE of this unit, 6 decimals: 1 Box = 12 Nos is 12"
    }
    item_aliases {
        bigint id PK
        bigint item_id FK
        string alias "unique per company"
    }
    item_location_parameters {
        bigint id PK
        bigint item_id FK
        bigint location_id FK "unique per item"
        decimal minimum_stock "min <= reorder <= max"
        decimal reorder_level
        decimal maximum_stock
    }
    item_stage_history {
        bigint id PK
        bigint item_id FK
        string event "created|sent_to_inventory|returned_to_purchase|submitted|approved|rejected|reopened"
        string from_stage
        string to_stage
        string approval_status
        bigint actor_id FK
        text comment
    }
```

`workflow_stage` (whose turn it is) and `approval_status` (the approval engine's state) are kept consistent by the `Item` model,
whichever screen or service drives the engine: draft/rejected ↔ a work stage (Purchase or Inventory), pending ↔ awaiting
approval, approved ↔ approved. A stage that does not fit its status is refused (`LogicException`). Details and rules:
[items.md](items.md).

## Phase 5: Rates

```mermaid
erDiagram
    companies ||--o{ price_lists : ""
    companies ||--o{ rates : ""
    items ||--o{ rates : "priced"
    units ||--o{ rates : "per unit"
    locations ||--o{ rates : "per branch"
    price_lists |o--o{ rates : "optional"
    rates ||--o{ approvals : "morph: rate"

    price_lists {
        bigint id PK
        string code "unique per company"
        string name
        string status "active|inactive"
    }
    rates {
        bigint id PK
        bigint item_id FK
        bigint location_id FK "one branch per rate"
        bigint unit_id FK "the item's base unit or a conversion"
        bigint price_list_id FK "null = the standard rate"
        decimal rate "15,4 - exclusive of GST, > 0"
        date effective_from "inclusive"
        date effective_to "inclusive; null = until further notice"
        string approval_status "draft|pending|approved|rejected"
        string status "active|inactive"
    }
```

Only **approved, active** rates inside their period are ever returned to a transaction (`RateService`). Two pending or approved
rates for the same item, branch, unit and price list cannot overlap. Details: [rates.md](rates.md).

## Phase 6: Sales teams, targets, route plans, attendance

```mermaid
erDiagram
    companies ||--o{ sales_teams : ""
    users ||--o{ sales_teams : "manages"
    sales_teams ||--o{ sales_team_members : "history kept"
    users ||--o{ sales_team_members : "one open at a time"
    sales_teams |o--o{ sales_targets : "or"
    users |o--o{ sales_targets : "for"
    item_groups |o--o{ sales_targets : "optional"
    locations |o--o{ sales_targets : "optional"
    users ||--o{ route_plans : "one per day"
    locations ||--o{ route_plans : "branch"
    route_plans ||--o{ route_plan_stops : "ordered"
    blocks |o--o{ route_plan_stops : ""
    ledgers |o--o{ route_plan_stops : "customer"
    route_plans ||--o| attendance_records : "marked against"
    route_plan_stops |o--o{ attendance_records : "matched stop"
    route_plans |o--o{ geofence_violations : "refused against"
    geofence_violations |o--o| attendance_records : "accepted by a manager"

    sales_teams {
        bigint id PK
        string code "unique per company"
        string name "unique per company"
        bigint manager_user_id FK
        string status "active|inactive"
    }
    sales_team_members {
        bigint id PK
        bigint sales_team_id FK
        bigint user_id FK
        date joined_on
        date left_on "last day, inclusive; null = still on the team"
        bigint open_user_id "generated: user_id while open, else NULL - unique per company"
    }
    sales_targets {
        bigint id PK
        bigint user_id FK "exactly one of user / team"
        bigint sales_team_id FK
        string period_type "monthly|quarterly"
        date period_start
        date period_end
        decimal target_value "15,2 net of tax; nullable"
        decimal target_quantity "15,3 base units; nullable"
        bigint item_group_id FK "optional"
        bigint location_id FK "optional"
        bool allow_overlap
        string slot_key "unique per company; NULL when overlap is allowed"
    }
    route_plans {
        bigint id PK
        bigint user_id FK "unique with plan_date per company"
        bigint location_id FK
        date plan_date
        string status "draft|published|cancelled"
        int geofence_radius_m "nullable = company default"
    }
    route_plan_stops {
        bigint id PK
        bigint route_plan_id FK
        int sequence "unique per plan"
        string stop_type "area|block|pin_code|customer"
        string label
        decimal latitude "10,7 nullable: with it the stop is a geofence"
        decimal longitude
        int radius_m "nullable = plan, then company"
        time visit_from
        time visit_to
    }
    attendance_records {
        bigint id PK
        bigint user_id FK "unique with attendance_date per company"
        bigint route_plan_id FK
        bigint route_stop_id FK
        date attendance_date
        timestamp marked_at "server clock"
        decimal latitude
        decimal longitude
        decimal accuracy_m
        decimal distance_m
        int radius_m
        string decision "accepted|accepted_override"
        bigint override_by FK
        bigint violation_id FK "unique: the refused attempt that was accepted"
    }
    geofence_violations {
        bigint id PK
        bigint user_id FK
        bigint route_plan_id FK "null when there was no plan"
        string attempt_type "attendance | lead_save (a lead refused in the field, Phase 8)"
        string reason
        string message
        decimal latitude "only when it was a real position"
        string raw_coordinates "what the device sent otherwise"
        decimal distance_m
        timestamp attempted_at "server clock"
    }
```

`attendance_records` and `geofence_violations` are **append-only** (the models refuse updates and deletes) and are not
soft-deleted. Details and rules: [field-sales.md](field-sales.md).

## Phase 7: Leads and follow-ups

```mermaid
erDiagram
    companies ||--o{ lead_sources : ""
    companies ||--o{ followup_outcomes : ""
    companies ||--o{ lost_reasons : ""
    locations ||--o{ leads : "branch (numbers the lead)"
    lead_sources ||--o{ leads : "came from"
    users |o--o{ leads : "assigned to (null = pool)"
    sales_teams |o--o{ leads : "owner's team at the time"
    states |o--o{ leads : ""
    districts |o--o{ leads : ""
    blocks |o--o{ leads : ""
    lost_reasons |o--o{ leads : "when lost"
    import_batches |o--o{ leads : "imported by"
    leads ||--o{ lead_interests : ""
    items |o--o{ lead_interests : "or a description"
    units |o--o{ lead_interests : ""
    leads ||--o{ lead_transfers : "ownership history"
    leads ||--o{ lead_stage_history : "stage history"
    leads ||--o{ followups : "subject (morph)"
    users ||--o{ followups : "assigned to"
    followup_outcomes |o--o{ followups : "when done"
    followups |o--o| followups : "next step of"

    lead_sources {
        bigint id PK
        string code "unique per company; fixed for standard rows"
        string name
        bool is_system "relied on by imports and integrations: never deleted"
        string status "active|inactive"
    }
    followup_outcomes {
        bigint id PK
        string code "unique per company"
        string name
        bool is_system
        bool counts_as_contact "the person was reached"
        bool needs_next_followup "refused without a next date"
    }
    lost_reasons {
        bigint id PK
        string code "unique per company"
        string name
    }
    leads {
        bigint id PK
        bigint location_id FK "fixed once entered"
        string lead_number "LD/BRANCH/FY/00001, unique per company"
        date lead_date
        bigint lead_source_id FK
        string name
        string mobile "10 digits; nullable for marketplace leads; indexed for the duplicate check"
        bigint state_id FK
        bigint district_id FK
        bigint block_id FK
        string pin_code
        decimal expected_value "15,2 nullable"
        bigint assigned_to FK "null = unassigned pool"
        bigint sales_team_id FK
        string stage "new|contacted|qualified|deal|quotation|won|lost"
        bigint lost_reason_id FK
        datetime next_followup_at "derived: earliest planned follow-up"
        datetime last_contacted_at
        decimal latitude "10,7 where a field entry was made: the position the geofence accepted (Phase 8)"
        decimal longitude
        bool is_complete
        string entry_channel "manual|import|field|indiamart|justdial|website"
        bigint import_batch_id FK
    }
    lead_interests {
        bigint id PK
        bigint lead_id FK
        bigint item_id FK "or"
        string description "one of the two is required"
        decimal quantity "15,3 nullable"
        bigint unit_id FK
    }
    lead_transfers {
        bigint id PK
        bigint lead_id FK
        bigint from_user_id FK "null = from the pool"
        bigint to_user_id FK
        bigint from_team_id FK
        bigint to_team_id FK
        text reason
        bigint transferred_by FK
        timestamp created_at
    }
    lead_stage_history {
        bigint id PK
        bigint lead_id FK
        string from_stage "null = created"
        string to_stage
        bigint lost_reason_id FK
        text comment
        bigint actor_id FK
        timestamp created_at
    }
    followups {
        bigint id PK
        bigint location_id FK "the subject's branch"
        string subject_type "morph: lead (deals, quotations, customers later)"
        bigint subject_id
        datetime scheduled_at
        string type "call|visit|email|whatsapp|meeting"
        bigint assigned_to FK
        string status "pending|done|cancelled"
        bigint outcome_id FK
        text notes
        datetime completed_at
        bigint completed_by FK
        bigint previous_id FK "the follow-up this one is the next step of"
        datetime reminder_sent_at "claimed by the scheduler: reminded once"
        datetime escalated_at "claimed by the scheduler: manager told once"
    }
```

`lead_transfers` and `lead_stage_history` are **append-only** (the models refuse updates and deletes). A lead has no
active/inactive `status` (its stage says where it stands) and is not an approval document. A follow-up is never deleted: it
is done or called off. Details and rules: [leads.md](leads.md).

## Phase 8: Mobile API (devices and idempotency keys)

```mermaid
erDiagram
    users ||--o{ personal_access_tokens : "tokenable (alias user): one row per signed-in device"
    users ||--o{ api_idempotency_keys : "remembered answers"
    companies ||--o{ api_idempotency_keys : "company the request worked in"

    personal_access_tokens {
        bigint id PK
        string tokenable_type "morph alias: user"
        bigint tokenable_id
        string name "the device's name"
        string device_id "stable id the app keeps; signing in again replaces the token"
        string token UK "sha256 of the token; the token itself is shown once"
        text abilities "read and/or write"
        string ip_address
        string user_agent
        string app_version
        timestamp last_used_at
        timestamp expires_at
    }
    api_idempotency_keys {
        bigint id PK
        bigint user_id FK
        bigint company_id FK
        string idempotency_key "UK with user_id"
        char fingerprint "sha256 of method, path, company and every input"
        string state "started | completed"
        smallint response_status
        mediumtext response_body "a successful answer only"
        datetime created_at
        datetime completed_at
        datetime expires_at "48 hours; api:prune removes it"
    }
```

A device token is not company data (a person may belong to several companies), so `personal_access_tokens` has no
`company_id`; issuing and revoking one is written to the audit log under the person's own company. `api_idempotency_keys`
holds copies of what an answer contained, for 48 hours, and is pruned daily. Nothing else changed: attendance, the violation
log and leads already had the columns the API needs (`attempt_type`, `source`, a lead's `latitude`/`longitude`).
Rules and use: [api.md](api.md).
## Phase 9: Lead marketplaces (IndiaMART, Just Dial)

```mermaid
erDiagram
    companies ||--o{ integration_connections : "one per provider"
    integration_connections ||--o{ lead_api_logs : "deliveries"
    leads ||--o{ lead_api_logs : "what a delivery became"
    locations ||--o{ integration_connections : "branch new leads go to"
    users ||--o{ integration_connections : "assigned_to (owner), actor_user_id (entered as)"

    integration_connections {
        bigint id PK
        bigint company_id FK
        string provider "indiamart | justdial; UK with company_id"
        boolean enabled
        string mode "pull | webhook | both"
        string public_key UK "the unguessable part of the webhook address; hidden, never audited"
        bigint default_location_id FK
        bigint assigned_to FK "owner; none = the pool"
        bigint actor_user_id FK "who the leads are entered as"
        smallint pull_interval_minutes
        smallint initial_lookback_hours
        datetime cursor_at "pull watermark"
        datetime next_pull_at
        datetime last_attempt_at
        datetime last_success_at
        datetime last_received_at "last push"
        smallint consecutive_failures
        string failure_kind "auth | rate_limited | invalid | unavailable"
        text last_error "redacted"
    }
    lead_api_logs {
        bigint id PK
        bigint company_id FK
        bigint integration_connection_id FK
        string provider
        string provider_lead_id "UK with company_id and provider"
        string via "pull | webhook | manual"
        string status "received | processing | created | linked | failed"
        bigint lead_id FK
        smallint attempts
        smallint received_count "how often the provider delivered it"
        json payload "as received, secrets removed"
        char payload_hash
        text error "redacted"
        datetime processed_at
    }
```

A connection holds **no secret**: the provider's API key and the webhook signing secret are company settings (encrypted, write-only), and
the sync bookkeeping (`cursor_at`, `last_*`, `consecutive_failures`, `last_error`) and `public_key` are kept out of the audit log.
`lead_api_logs` is the dead-letter queue: a `failed` row keeps its payload and is retried by hand; rows are pruned after the retention
period (`integrations.log_retention_days`, 90 days; failed ones twice that). Rules and use: [integrations.md](integrations.md).

## Phase 10: Inquiries from existing customers, and deals

```mermaid
erDiagram
    leads ||--o| deals : "converted to (lead_id UK)"
    ledgers ||--o{ leads : "customer_ledger_id (an inquiry)"
    ledgers ||--o{ deals : "customer_ledger_id"
    deals ||--|{ deal_items : "lines"
    deals ||--o{ deal_stage_history : "append-only"
    lead_interests ||--o| deal_items : "lead_interest_id (where a line came from)"
    items ||--o{ deal_items : ""
    units ||--o{ deal_items : ""
    users ||--o{ deals : "assigned_to"
    lost_reasons ||--o{ deals : ""

    leads {
        string lead_type "new | existing (CHECK); existing needs customer_ledger_id and vice versa"
        bigint customer_ledger_id FK "the approved customer an inquiry is from"
        string approval_status "draft for ever on a new lead; the workflow's for an inquiry"
        bigint approved_by FK
        datetime approved_at
    }
    lead_interests {
        decimal expected_rate "15,2, only an inquiry's lines; CHECK >= 0"
    }
    deals {
        bigint id PK
        bigint company_id FK
        bigint location_id FK "the lead's branch"
        string deal_number "UK with company_id; DL/BRANCH/FY/00001"
        date deal_date
        bigint lead_id FK "UNIQUE: a lead converts once"
        bigint customer_ledger_id FK "nullable: a prospect has none yet"
        string party_name "snapshot of the lead"
        string contact_person
        string mobile
        string email
        date expected_close_date
        tinyint probability "0-100 (CHECK); 1-99 while open"
        decimal expected_value "15,2, the sum of the lines, worked out by the server"
        string stage "discovery | negotiation | quotation | won | lost (CHECK)"
        bigint lost_reason_id FK
        text lost_remarks
        datetime lost_at
        bigint assigned_to FK "the lead's owner"
        bigint sales_team_id FK
        string approval_status "the shared workflow"
    }
    deal_items {
        bigint id PK
        bigint deal_id FK
        smallint line_no
        bigint lead_interest_id FK "nullable; null when the line was added to the deal"
        bigint item_id FK
        bigint unit_id FK
        decimal quantity "15,3 > 0"
        decimal expected_rate "15,2 >= 0"
        decimal amount "15,2: quantity x rate, half up to paise"
    }
    deal_stage_history {
        bigint id PK
        bigint deal_id FK
        string from_stage "null on conversion"
        string to_stage
        bigint lost_reason_id FK
        text comment
        bigint actor_id FK "null when a document set it"
        datetime created_at
    }
```

An inquiry from an existing customer is a row of `leads` (so follow-ups, hand-overs, access and the API are shared) that names its customer and
carries the approval columns. A deal has its own numbered, approvable row and lines; it keeps the link to the lead and to each lead line
it came from. The approval fingerprint of a deal covers its customer and lines (not its stage, probability or close date); an inquiry's covers
its customer, source, requirement and lines. Rules and use: [deals.md](deals.md).

## Uniqueness and integrity rules

| Rule | Where |
|---|---|
| A lead number is unique within a company and never reused | `unique(company_id, lead_number)`; issued by `NumberSeriesService` in the saving transaction; leads are soft-deleted |
| An open lead with the same mobile number is a duplicate: warned about, saved only when the person says so | `LeadService` (company-wide, across branches); `index(company_id, mobile)` |
| A lead's branch never changes; its owner changes only through a transfer | `LeadService::update()` ignores both; `LeadTransferService` |
| A lead is moved by hand only between New, Contacted and Qualified; Lost needs a reason, re-opening a comment; Deal / Quotation / Won are set by a conversion | `LeadStage::canMoveTo()`, `LeadService::changeStage()` under a row lock |
| An interest names an item or describes one; a lead's coordinates come as a pair; an expected value is not negative | `CHECK` constraints on `lead_interests` and `leads` |
| An interest's item is approved and active when chosen, and its unit is one the item is sold in | `LeadService` through `ItemLookup` |
| A lead is new or an inquiry from a customer, never half of each; an inquiry's rate is not negative | `CHECK` constraints `leads_type_valid`, `leads_inquiry_names_customer`, `lead_interests_rate_valid` |
| A lead converts to a deal once, however many people try at once | `unique(lead_id)` on `deals` **and** the lead's row lock with a locking read of the deal in `DealService::convert()`; proven by `tests/Concurrency/DealConcurrencyTest.php` (five deals with neither, one with either) |
| A deal number is unique within a company and never reused; deals are never deleted | `unique(company_id, deal_number)`, taken from `NumberSeriesService` first in the saving transaction; `DealPolicy::delete()` is false |
| A deal's probability is 0–100, its value and amounts are not negative, its stage is one of five, a line has a quantity | `CHECK` constraints on `deals` and `deal_items` |
| Every line of the lead comes across to its deal; a line's item is an approved, active item in a unit it is sold in, a whole quantity for a unit that cannot be split | `DealService::validate()` |
| A deal's value and each amount are worked out on the server, half up to paise | `DealService` (never read from the request) |
| A deal's customer and lines change only while it is a draft or returned; an approved deal is re-opened with a reason | `Deal::isContentEditable()`, `DealService::update()` under a row lock; `ApprovalService::reopen()` |
| A deal's stage history is never changed or deleted | model `updating` / `deleting` guards on `DealStageEntry` |
| A lead's stage follows its deal (Deal, Quotation, Won, Lost) | `DealService` through `LeadService::setStageBySystem()` |
| A hand-over moves every selected lead or none; two at the same instant are serialised | `LeadTransferService` (row locks, one transaction) |
| Stage history and ownership history are never changed or deleted | model `updating` / `deleting` guards on `LeadStageEntry`, `LeadTransfer` |
| A follow-up is finished once (and plans its next step once) | `FollowupService` under a row lock |
| A reminder, and an escalation, is sent once per follow-up | conditional `UPDATE … WHERE reminder_sent_at IS NULL` (and `escalated_at`) before anything is sent |
| A standard lead source or outcome keeps its code and is never deleted; one in use is never deleted | `KeepsSystemCodes`, `CrmMasterPolicy`, the list screens' usage checks |
| The first number of a series is issued without a deadlock even inside the document's own transaction | `NumberSeriesService::lockSeries()` (plain read, then `INSERT … ON DUPLICATE KEY UPDATE`, and no second lock request by the creator) |
| A company has one connection per provider | `unique(company_id, provider)` on `integration_connections`; the address key is unique too |
| The same provider lead is one delivery however often it arrives | `unique(company_id, provider, provider_lead_id)` on `lead_api_logs`; `received_count` counts the repeats; proven by `tests/Concurrency/WebhookConcurrencyTest.php` (five leads without it, one with it) |
| One lead per buyer when several enquiries from one mobile number arrive together | `LeadIntake` takes a cache lock on the number around "find the open lead, else create"; proven by the same race test (five leads without the lock) |
| Two workers never take the same delivery; one that died holding it loses it after ten minutes | one conditional UPDATE `received/failed → processing` (or stale `processing`) in `LeadIntake::claim()` |
| IndiaMART is never called more often than it allows | one conditional UPDATE of `last_attempt_at` against the provider's minimum gap in `ProviderSync` || A device token is stored only as a hash; one token per device id; at most `API_MAX_DEVICES` per person | `unique(token)`; `DeviceTokenService` (replaces the same device, signs out the least recently used beyond the limit) |
| The same Idempotency-Key never runs two requests: the database lets exactly one in | `unique(user_id, idempotency_key)` on `api_idempotency_keys`; proven by `tests/Concurrency/ApiIdempotencyConcurrencyTest.php` (five leads without it, one with it) |
| A lead saved from the field is saved only when its position is inside today's published plan's geofence, and its branch is the plan's | `FieldLeadService` through `FieldPositionGate`; a refusal is a `lead_save` row in `geofence_violations` || A person has one open team membership per company, and memberships never overlap in time | generated column + `unique(company_id, open_user_id)` on `sales_team_members`; `SalesTeamService` |
| A target is for a person or a team, never both or neither; it has a positive value and/or quantity; its period does not end before it starts | database `CHECK` constraints on `sales_targets` |
| Two targets for the same person/team, item group and branch do not cover the same days unless one allows overlap | `TargetService` under a lock on the person or team; `unique(company_id, slot_key)` for exact duplicates |
| One route plan per person and day | `unique(company_id, user_id, plan_date)` |
| A stop's coordinates come as a pair and are a real position; stops keep a unique order within a plan | `CHECK` constraints and `unique(route_plan_id, sequence)` on `route_plan_stops`; `RoutePlanService` |
| One attendance per person and day; a refused attempt is accepted at most once | `unique(company_id, user_id, attendance_date)`, `unique(violation_id)` on `attendance_records` |
| Attendance and the violation log are never changed or deleted | model `updating` / `deleting` guards |
| An item's name is unique within a company; its code too, but items without a code yet never clash | `unique(company_id, name)`, `unique(company_id, code)` (NULLs do not collide) |
| An item has one conversion per alternative unit; an alias is unique within the company; one stock row per item and branch | `unique(item_id, unit_id)`, `unique(company_id, alias)`, `unique(item_id, location_id)` |
| `minimum ≤ reorder level ≤ maximum`; whole numbers when the base unit cannot be split | `ItemService` |
| A conversion factor is positive, ≤ 6 decimals, and a whole number when the base unit cannot be split | `ItemService` |
| An approved item's code, base unit and existing conversions are frozen | `ItemService` |
| Stage and approval status must agree | `Item` model (`saving`) |
| Stage history is append-only | `ItemStageEntry` model hooks |
| A price list code is unique within a company | `unique(company_id, code)` on `price_lists` |
| A rate's period never ends before it starts; a rate is more than zero | database `CHECK` constraints `rates_period_valid`, `rates_rate_positive` |
| Live (pending or approved) rates for one item, branch, unit and price list do not overlap | `Rate::conflicting()` on save, and again under a lock on the item at submit |
| The unit of a rate is the item's base unit or a conversion; who and where an approved rate is for is fixed | `RateMaintenance` |
| A ledger name is unique within a company **and type**; a party may be both a customer and a supplier | `unique(company_id, ledger_type, name)` on `ledgers` |
| A GSTIN is used by one ledger per type | validation in `LedgerService` (a deleted ledger frees its GSTIN) |
| A ledger can only be filed under a group that belongs to its type and company | `LedgerService` (and `ValidatesParentCompany` on the model) |
| A ledger's type never changes | model `updating` guard |
| One default billing and one default shipping address | `LedgerService` (a lone address becomes the default) |
| One bank account per ledger; its number is encrypted and never audited | `unique(ledger_id)`; `encrypted` cast; excluded from the audit log |
| Groups that hold ledgers (or whose sub-groups do) cannot be deleted or moved | `AccountGroupTree` |
| Payment method codes in use cannot be renamed or deleted | `PaymentMethodList` |
| A code is unique within a company | `unique(company_id, code)` from the `masterColumns()` macro |
| A GST state code is unique within a company | `unique(company_id, gst_code)` on `states` |
| A district name is unique within a state, a block name within a district | `unique(company_id, state_id, name)`, `unique(company_id, district_id, name)` |
| A PIN row is PIN + district + locality | `unique(company_id, pin_code, district_id, area)`; `area` is NOT NULL (`''` = whole PIN) so the key is real, not defeated by NULLs |
| A child may only point at a parent of its own company | `ValidatesParentCompany` model guard on `account_groups`, `item_groups`, `districts`, `blocks`, `pin_codes` (plus form validation) |
| Account group tree: no cycles, same-nature moves only, standard groups locked | `AccountGroupTree` + `Hierarchy::wouldCreateCycle` |
| One counter per company + branch + document type + financial year | `unique(company_id, location_id, document_type, financial_year)` on `number_series` (branch is NOT NULL so the key really is unique) |
| One membership row per user and company | primary key on `company_user` |
| One approval level per company, document type and level | `unique(company_id, document_type, level)` |
| Settings: one value per company and key | `unique(company_id, key)` |

Deletes: master and document tables use soft deletes; `companies`, `locations` and the approval ledger are never
cascade-deleted from their owners (`restrictOnDelete`). Approval rows and audit entries are append-only.

## Column conventions (`Blueprint::masterColumns()`)

`company_id`, `code`, `status` (`active|inactive`), `approval_status` (`draft|pending|approved|rejected`),
`approved_by`, `approved_at`, `remarks`, `created_by`, `updated_by`, timestamps, soft deletes.
`companies` opts out of `company_id` and the approval columns (it is the tenant root); other tables opt out of
irrelevant columns with the macro's flags rather than carrying fields they never use.
Money is `decimal(15,2)` (`amount()` macro) and quantity `decimal(15,3)` (`quantity()` macro); a unit rate is the one exception, `decimal(15,4)`, because a price per piece can need four places.

## Polymorphic aliases (enforced morph map)

Shared: `user`, `company`, `location`, `number_series`, `approval`, `approval_level`, `comment`, `import_batch`, `role`,
`permission`. Masters (registered in `MastersServiceProvider`): `department`, `designation`, `unit`, `account_group`,
`item_group`, `state`, `district`, `block`, `pin_code`, `payment_method`, `ledger`, `ledger_address`, `ledger_contact`,
`ledger_bank_detail`, `item`, `item_uom`, `item_alias`, `item_location_parameter`, `item_stage_entry`, `price_list`, `rate`. CRM (registered in `CRMServiceProvider`): `sales_team`, `sales_team_member`, `sales_target`, `route_plan`, `route_stop`, `attendance`, `geofence_violation`, `lead_source`, `followup_outcome`, `lost_reason`, `lead`, `lead_interest`, `lead_transfer`, `lead_stage_entry`, `followup`, `deal`, `deal_item`, `deal_stage_entry`. Integrations (registered in `IntegrationsServiceProvider`): `integration_connection`. Sales (registered in `SalesServiceProvider`): `quotation`, `quotation_item`, `quotation_state_entry`, `quotation_dispatch`, `sales_order`, `sales_order_item`, `sales_order_state_entry`, `sales_invoice`, `sales_invoice_item`, `report_export`, `task`, `task_assignment`, `sla_policy`, `holiday`, `sla_clock`, `sla_breach`, `task_attachment`. Marketing (registered in `MarketingServiceProvider`): `campaign`, `campaign_step`, `campaign_member`, `message_template`, `communication`, `contact_consent`, `workflow`, `workflow_version`, `workflow_run`. Later modules register theirs the same way; an unregistered
model throws instead of writing a class name into the database.

## Quotations (Phase 11)

```mermaid
erDiagram
    leads ||--o{ quotations : "owner decides who works it"
    deals ||--o{ quotations : "optional"
    quotations ||--o{ quotation_items : has
    quotations ||--o{ quotation_history : "state changes"
    quotations ||--o{ quotation_dispatches : "sent / failed"
    quotations ||--o| quotations : "previous revision"
    quotations {
        string quotation_number "QT/AHD/26-27/00001, shared by every revision"
        int revision_no "0, 1, 2 ... unique with company + number"
        string state "draft|sent|accepted|declined|expired|superseded|cancelled (CHECK)"
        string approval_status "the shared workflow"
        date valid_until
        date price_date "rates are looked up as of this day"
        string supply_type "intra | inter (CHECK)"
        decimal grand_total "rounded to the rupee; round_off shown"
    }
    quotation_items {
        int line_no
        decimal list_rate "what RateService answered (4 places)"
        decimal rate "what the line is quoted at"
        bool rate_overridden "reason kept; audit on the rate"
        decimal discount_percent
        decimal gst_rate "the item's rate when the line was saved"
    }
```

* A revision is a row; `previous_id` points at the one before it. `deal_id` is null for a quotation made straight from an inquiry;
  `lead_id` is always set and is what decides who owns the quotation.
* Customer, address, place of supply, supplier state and GSTIN, terms and every amount are **copies** made when the draft is saved.
* `quotation_history` and `quotation_dispatches` are append-only (models refuse update and delete).

| Rule | Enforced by |
|---|---|
| A revision number is used once per quotation | `unique(company_id, quotation_number, revision_no)` + the row lock in `QuotationService::revise()` |
| A revision that has left draft never changes (content, lines, delete) | `Quotation` / `QuotationItem` model guards; `QuotationService` |
| Only a draft that is approved, latest and still valid is sent; the claim is atomic | `QuotationService::send()` (locked draft → sent, then the e-mail; a refusal puts it back) |
| Prices come only from the rate master; a changed rate needs `rate.override`, a reason and leaves an audit entry on the rate | `QuotationPricer` → `RateService` |
| GST by place of supply, half-up paise, rupee round-off | `TaxService` (unit-tested) |
| A deal is in Quotation while a quotation of it is sent or accepted, otherwise back in Negotiation | `QuotationService::syncSourceStage()` through `DealService::setStageBySystem()` |
| A quotation may become an order only if latest, approved, sent/accepted and in date (exception audited) | `QuotationConversionGuard` |
| A warning is sent once | `WHERE expiry_warned_at IS NULL` claim in `QuotationExpiry` |

## Sales orders and sales invoices (Phase 12)

```mermaid
erDiagram
    quotations ||--o{ sales_orders : "at most one live"
    deals ||--o{ sales_orders : "at most one live"
    sales_orders ||--o{ sales_order_items : has
    sales_orders ||--o{ sales_order_history : "state changes"
    sales_orders ||--o{ sales_invoices : "one per source_key"
    sales_invoices ||--o{ sales_invoice_items : has
    sales_order_items ||--o| sales_invoice_items : "invoiced by"
    sales_orders {
        string so_number "SO/AHD/26-27/00001"
        string source_type "quotation|deal|direct (CHECK)"
        string state "open|invoiced|cancelled (CHECK)"
        string approval_status "the shared workflow"
        string credit_result "not_checked|ignored|no_limit|ok|warn|blocked|overridden (CHECK)"
        bigint live_quotation_id "virtual: the quotation unless cancelled; UNIQUE"
        bigint live_deal_id "virtual: the deal unless cancelled; UNIQUE"
    }
    sales_invoices {
        string invoice_number "SI/AHD/26-27/00001"
        string source_key "full now; parts later; UNIQUE with sales_order_id"
        string status "issued|cancelled (CHECK)"
    }
```

* An order and an invoice carry **copies** of the customer, both addresses, the place of supply, the supplier, the terms and every figure.
* `sales_order_items.invoiced_quantity` and `sales_invoice_items.sales_order_item_id` (unique per invoice) are what invoicing in parts will build on.
* `sales_order_history` is append-only (the model refuses update and delete).

| Rule | Enforced by |
|---|---|
| A quotation, and a deal, have at most one live (not cancelled) order | row lock on the source + a locking read in `SalesOrderService`, and `unique(live_quotation_id)` / `unique(live_deal_id)` on virtual columns |
| An order that is waiting, approved, invoiced or cancelled does not change (content, lines, delete) | `SalesOrder` / `SalesOrderItem` model guards; `SalesOrderService` |
| An approved order has exactly one full invoice, however often it is asked for or raced | `InvoiceGenerator` (number first, order row lock, locking existence read, rollback gives the number back) and `unique(sales_order_id, source_key)` |
| An issued invoice and its lines never change; only its status moves, with its order | `SalesInvoice` / `SalesInvoiceItem` model guards |
| An order over a blocking credit limit cannot be sent for approval without an audited override | `SalesOrder::beforeSubmitForApproval()` through `CreditCheck`; `SalesOrderService::overrideCredit()` |
| A direct order needs a regular customer, in a branch the person works in | `SalesOrderService::createDirect()` |
| A target counts approved, uncancelled orders on their order date, net of tax, for the salesperson credited | `SalesOrderAchievement` |

## Report exports (Phase 13)

`report_exports` is the only table of the phase: one row for each asked-for export (`company_id`, `user_id`, `report` key, `format` xlsx|pdf, `status`
queued|running|ready|failed|expired, the `filters` as asked, `file_path` on the private disk, `row_count`, `expires_at`). Status and format are
CHECK-constrained; the company, user, report and format cannot be changed once asked for (the model refuses); a file is deleted after 7 days and the row
stays as "expired". Reports and the dashboard read the other modules' tables and write nothing.

## Tasks and SLAs (Phase 14)

```mermaid
erDiagram
    tasks ||--o{ task_assignments : "who had it"
    tasks }o--o| tasks : "previous_id (repeats)"
    sla_policies ||--o{ sla_clocks : times
    sla_clocks ||--o| sla_breaches : "ran out once"
    tasks {
        string status "open|in_progress|done|cancelled (CHECK)"
        string priority "low|normal|high|urgent (CHECK)"
        string recurrence "none|daily|weekly|monthly (CHECK)"
        string subject_type "lead|deal|quotation|sales_order|ledger (a morph, optional)"
    }
    sla_clocks {
        string kind "first_response|followup|task"
        string subject_type "lead|followup|task"
        string state "running|met|stopped (CHECK)"
        dateTime breached_at "set once; a met clock may carry it (answered late)"
    }
```

| Rule | Enforced by |
|---|---|
| A finished or called-off task never changes | `TaskService::live()` (locks the row, refuses) and `TaskPolicy` |
| Every giving of a task is kept | `task_assignments`, append-only (the model refuses update and delete) |
| A thing has one clock for a given start moment | `SlaSweep::open()` (policy row lock + re-check) |
| A breach is recorded once and announced once | the conditional `UPDATE … WHERE state='running' AND breached_at IS NULL` that claims it, and `unique(sla_clock_id)` on `sla_breaches` |
| The breach log and the clocks are records | `SlaBreach` is append-only; `SlaClock` cannot be deleted |
| A task reminder / overdue notice goes out once | `UPDATE … WHERE reminder_sent_at IS NULL` (and `overdue_notified_at`) in `tasks:remind` |

## Phase 14 gap closure (migration `2026_10_26_100000_workspace_gaps`)

`sla_clocks` gains `paused_at`, `paused_minutes`, `pause_reason`; `kind` on `sla_policies`, `sla_clocks` and `sla_breaches` widens to VARCHAR(20) and the
policy check allows `quotation_response` and `approval`; `task_attachments` (private files on a task: name, storage path, sniffed MIME, size, uploader,
`removed_at`/`removed_by` — a removed file is marked, not erased from the record).

## Campaigns, consent and workflows (Phase 15, migration `2026_10_27_100000_create_marketing_tables`)

```mermaid
erDiagram
    message_templates ||--o{ campaign_steps : "used by"
    campaigns ||--o{ campaign_steps : "ordered"
    campaigns ||--o{ campaign_members : "frozen at launch"
    campaign_members ||--o{ communications : "one per step"
    workflows ||--o{ workflow_versions : "immutable versions"
    workflows ||--o{ workflow_runs : "once per event"
    workflow_runs ||--o{ communications : "may queue"
    campaigns ||--o{ leads : "attribution (campaign_id, utm, attribution)"
```

| Rule | Enforced by |
|---|---|
| A step is made once for a member, whatever runs | `unique(company_id, idempotency_key)` on `communications` (`c{campaign}:m{member}:s{step}`) and the conditional UPDATE that moves the member on |
| A workflow runs once per event | `unique(workflow_id, trigger_key)` on `workflow_runs`, claimed before any action |
| The approved steps of a campaign do not change | the `CampaignStep` model guard (reads the campaign's status) |
| Consent is history, never edited | `contact_consents` is append-only; the newest event per channel and address wins |
| A workflow version never changes | `WorkflowVersion` refuses update and delete; a change is a new version |
| A sent message is kept as it went out | the rendered subject and body are stored on `communications` |
| Marketing e-mail needs an opt-out | the template cannot be saved without `{{unsubscribe_url}}`; the signed link's GET changes nothing, POST records the opt-out |

## Brands and industry templates (Loss Intelligence L1, migration `2026_10_28_100000_create_brand_and_industry_template_tables`)

See [loss-intelligence.md](loss-intelligence.md) for why. `brands` is a plain company master. The `industry_templates` family is **system data**
(no `company_id`) shared by every company; only `company_industry_templates` and `industry_settings` belong to a company.

```mermaid
erDiagram
    industry_templates ||--o{ kpi_definitions : "per version"
    industry_templates ||--o{ loss_categories : "per version"
    industry_templates ||--o{ template_fields : "per version"
    industry_templates ||--o{ template_reports : "per version"
    industry_templates ||--o{ workflow_states : "own, or none = default workflow"
    industry_templates ||--o{ workflow_transitions : "own, or none = default workflow"
    companies ||--o{ company_industry_templates : "enabled, version pinned"
    industry_templates ||--o{ company_industry_templates : "used by"
    companies ||--o{ industry_settings : "own KPI thresholds"
    companies ||--o{ brands : "owns"
```

| Rule | Enforced by |
|---|---|
| A company never edits the shared template rows | no route or service writes them; the policy refuses create/update/delete; a company's choices live in `industry_settings` |
| A company on version 1 keeps seeing version 1 | every child row carries `version`; `company_industry_templates.version_pinned` selects it; `publishNewVersion()` copies forward |
| One row per template, version and code | `unique(industry_template_id, version, code)` on KPIs, categories, fields and reports |
| One enablement per company and template | `unique(company_id, industry_template_id)` |
| The default workflow serves templates without their own | rows with `industry_template_id` NULL; matched by hand in the seeder because a unique key never matches NULL |
| Brand code unique per company | `unique(company_id, code)` from `masterColumns()` |

## Returns, credit notes and item costs (Loss Intelligence L2, migration `2026_10_29_100000_create_sales_returns_and_item_costs`)

See [returns-and-costs.md](returns-and-costs.md). `items` gains `brand_id` (→ `brands`) and `standard_cost` (per base unit, 18,4). `sales_invoice_items` gains
`unit_cost`, `cost_amount`, `cost_source` (copied when the invoice is made; null = unknown).

```mermaid
erDiagram
    sales_invoices ||--o{ sales_returns : "against one issued invoice"
    sales_returns ||--o{ sales_return_items : "lines"
    sales_invoice_items ||--o{ sales_return_items : "part of a line comes back"
    brands ||--o{ items : "optional"
```

| Rule | Enforced by |
|---|---|
| A return line is part of exactly one invoice line, once per return | `unique(sales_return_id, sales_invoice_item_id)` |
| A return never takes more than the invoice line allows | `SalesReturnService::preview()` at save, and `problemBeforeSubmit()` under a lock on the invoice at submission |
| Pending and approved returns, and their lines, never change | `SalesReturn` / `SalesReturnItem` model guards (read the stored approval status) |
| A return is never deleted | model refuses |
| Return numbers are unique per company | `unique(company_id, return_number)` |
| Type and supply are known values; amounts are not negative | CHECK constraints on `sales_returns` / `sales_return_items` |

## Inventory and procurement (Loss Intelligence L3, migration `2026_10_30_100000_create_inventory_and_procurement_tables`)

See [inventory-and-procurement.md](inventory-and-procurement.md). `items` gains `tracking` (none | batch), `has_expiry`, `shelf_life_days`.

```mermaid
erDiagram
    items ||--o{ batches : "tracked by batch"
    items ||--o{ stock_movements : "the ledger"
    batches ||--o{ stock_movements : "of a batch"
    locations ||--o{ stock_movements : "at a branch"
    items ||--o{ stock_balances : "on hand"
    batches ||--o{ stock_balances : "per batch (batch_key)"
    ledgers ||--o{ purchase_orders : "supplier"
    purchase_orders ||--o{ purchase_order_items : "lines"
    purchase_orders ||--o{ grns : "received against"
    grns ||--o{ grn_items : "lines"
    purchase_order_items ||--o{ grn_items : "accepted / rejected"
    grn_items ||--o{ stock_movements : "posted once (source)"
```

| Rule | Enforced by |
|---|---|
| A movement is never changed or removed | `StockMovement` model guards |
| A document line posts a kind of movement once | `unique(company_id, source_type, source_id, source_line_id, movement_type)` + the poster's re-check under the receipt's row lock |
| One balance row per branch, item and batch | `unique(company_id, location_id, item_id, batch_key)`; `batch_key` 0 = no batch (a NULL could not be unique) |
| Stock never negative | `StockService` refuses, and `CHECK (quantity >= 0)` on `stock_balances` |
| Balances agree with the ledger | written in the same transaction under the balance row's lock (taken by INSERT … ON DUPLICATE KEY UPDATE, never INSERT IGNORE + FOR UPDATE: that deadlocks); `stock:rebuild-balances` checks |
| One batch number per item | `unique(company_id, item_id, batch_no)` |
| A receipt line is one order line once | `unique(grn_id, purchase_order_item_id)` |
| Pending / approved orders and receipts, and their lines, never change | model guards on `PurchaseOrder`, `PurchaseOrderItem`, `Grn`, `GrnItem` |
| Quantities are sensible | CHECKs on `purchase_order_items` and `grn_items` (accepted + rejected > 0) |

## Loss management (Loss Intelligence L4, migration `2026_10_31_100000_create_loss_management_tables`)

See [loss-management.md](loss-management.md).

```mermaid
erDiagram
    industry_templates ||--o{ loss_incidents : "made under (version kept)"
    loss_categories ||--o{ loss_incidents : "kind"
    loss_incidents ||--o{ loss_impact_lines : "figures"
    loss_impact_lines ||--o| loss_impact_lines : "superseded by"
    loss_incidents ||--o{ root_cause_analyses : "causes"
    loss_incidents ||--o{ corrective_actions : "CAPA"
    root_cause_analyses ||--o{ corrective_actions : "answers"
    loss_incidents ||--o| loss_incidents : "duplicate of (primary_incident_id)"
    loss_incidents ||--o{ workflow_events : "history (morph)"
    corrective_actions ||--o{ workflow_events : "history (morph)"
```

| Rule | Enforced by |
|---|---|
| A state changes only through the workflow | `LossWorkflowService::transition()` (transitions of the incident's template version, permissions, comments, approval, conditions) |
| Workflow history is never changed | `WorkflowEvent` model guards |
| A figure's amount never changes; a figure is never deleted | `LossImpactLine` guards (only validation and supersession columns move) |
| Kinds and states are known values; a figure is not negative; confidence 0–100 | CHECK constraints |
| A record reported twice is counted once | `primary_incident_id` set at creation (under a lock); `LossFigures` excludes duplicates |
| Numbers unique per company | `unique(company_id, incident_number)`, `unique(company_id, action_number)` |

## Finance and costing (Loss Intelligence L5, migration `2026_11_01_100000_create_finance_tables`)

See [finance.md](finance.md). `loss_incidents` gains `cost_center_id`.

```mermaid
erDiagram
    cost_centers ||--o{ cost_centers : "parent"
    cost_centers ||--o{ budgets : "monthly"
    loss_categories ||--o{ budgets : "optional"
    cost_centers ||--o{ loss_incidents : "charged to"
    items ||--o{ item_cost_history : "every cost change"
```

| Rule | Enforced by |
|---|---|
| One budget per cost centre, category and month | `unique(company_id, cost_center_id, category_key, period_start)` — `category_key` is a generated `COALESCE(loss_category_id, 0)` so "no category" is unique too |
| A pending or approved budget never changes | `Budget` model guard |
| Cost history is never changed or removed | `ItemCostEntry` model guards |
| A budget is not negative | CHECK on `budgets` |

## L6 additions

* `items.industry_template_id` (nullable FK → `industry_templates`): the industry an item belongs to; revenue by industry follows it.
* `kpi_snapshots`: company, `location_key` (0 = whole company), industry template, KPI definition + version, `period_type` (`day`), `period_start`, value / numerator / denominator (decimal 18,4, nullable), source freshness, computed at; unique `kpi_snapshot_once` (company, location, KPI, period type, period start).

## P1 (production) additions

* `boms` (approval master: `code` unique per company, `item_id`, `output_quantity`), `bom_items` (unique bill + line, unique bill + component, `scrap_percent`).
* `production_orders` (branch, `order_number` unique, `bom_id`, `item_id`, `planned_quantity`, dates, `state`), `production_components` (what the order requires, copied from the bill).
* `production_issues` and `production_outputs` (append-only; `unique(production_order_id, entry_key)`); the stock side is in `stock_movements` (`source_type` `production_issue` / `production_output`).

## M1 (maintenance) additions

* `assets` (branch, `code` unique per company, kind, criticality, optional cost centre), `maintenance_plans` (preventive schedules: asset, checklist, every N days / weeks / months, `lead_days`, `next_due_on`, `last_done_on`).
* `maintenance_orders` (branch, `order_number` unique, asset, optional schedule + `plan_due_on` — `unique(plan, due date)` —, type, priority, `state`, `stopped_at` / `restored_at` for downtime, optional loss incident), `maintenance_tasks`.
* `maintenance_parts` and `maintenance_labour` (append-only; `unique(order, entry_key)`); the stock side is in `stock_movements` (`source_type` `maintenance_part`).

## Q1 (quality) additions

* `inspection_plans` (approval master: `code` unique per company, item, stage) and `inspection_plan_parameters` (measure / check, limits, critical).
* `inspections` (branch, `inspection_number` unique, plan, item, optional batch, `held_batch`, lot quantity, `state`), `inspection_results` (the checks and limits copied from the plan, the reading, `passed` worked out by the service).
* `batch_holds` (append-only hold / release history), `non_conformances` (`ncr_number` unique, optional inspection and batch, severity, `state`, disposition, links to `loss_incidents` and `corrective_actions`).

## H1 (HR) additions

* `employees` (company, branch, `user_id` unique, name, contact and personal details, department, designation, `reports_to_id`, `employment_type`, joining / probation / confirmation dates, `employment_status`, notice / exit dates and reason).
* `employee_contacts`, `employee_documents` (private-disk path, never serialised), `employment_events` (append-only history).

## H2 (leave and attendance) additions

* `leave_types`, `leave_ledger` (append-only; unique `leave_entry_once` on employee, type, entry type, period key), `leave_requests` (state pending / approved / rejected / cancelled).
* `shifts`; `employees.shift_id`; `attendance_punches` (append-only), `attendance_days` (unique employee + date), `attendance_regularisations`.

## H4 (recruitment, performance, training) additions

* `job_openings`, `candidates` (unique mobile per company), `applications` (unique candidate + opening; `hired_employee_id` unique), `application_events` (append-only), `interviews`, `offers` (one per application).
* `review_cycles`, `performance_goals`, `performance_reviews` (unique employee + cycle).
* `training_programs`, `training_enrolments` (unique program + employee).
