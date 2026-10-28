# Mobile / integrations API — `/api/v1`

The REST API a field app (the PWA first; native later) uses. The machine-readable contract is
[api/openapi.yaml](api/openapi.yaml) (OpenAPI 3.0; it is held equal to the real routes by
`tests/Feature/Api/ApiSpecTest.php`). This page is the guide: how it behaves, why, and what to do with each answer.

> **The one rule:** the app sends a position and what the person typed. Everything else — which company, which branch, which day,
> who may see which lead, whether the position is inside the geofence, a lead's number, the time — is decided by the server.
> The API has no business rules of its own: every endpoint calls the services the screens use (`LeadService`, `FollowupService`,
> `DealService`, `ApprovalService`, `FieldPositionGate`, `AttendanceService`, `LeadTransferService`, `ItemLookup`, `LedgerLookup`,
> `PinCodeLookup`), so the app can never do what the web screens refuse.

## 1. Getting in

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "ravi@example.com", "password": "…", "device_name": "Ravi’s Pixel", "device_id": "pixel-7-ravi-0001", "app_version": "1.4.2" }
```

```json
{ "data": { "token": "12|Qx9…", "token_type": "Bearer", "expires_at": "2026-10-26T09:30:00+05:30", "scopes": ["read", "write"],
            "device": { "id": 12, "name": "Ravi’s Pixel", "current": true }, "user": { "id": 31, "name": "Ravi Patel", "email": "ravi@example.com" } },
  "meta": { "request_id": "01K7Q3Z8Y2M6W4N5R9T0V1X2A3" } }
```

Send `Authorization: Bearer 12|Qx9…` on every other call. The token is shown **once**; only a hash is stored.

* **Who may sign in:** an active account holding the `api.access` permission (Sales Manager, BDE / Field Salesperson, Tele-caller,
  Company Admin and Super Admin by default; an administrator can change it in *Roles & permissions*). It is checked on **every**
  request, so removing it, or deactivating the person, stops their tokens at once.
* **Devices.** A token is a device. Signing in again with the same `device_id` replaces that device's token; beyond
  `API_MAX_DEVICES` (5) the least recently used device is signed out. `GET /devices` lists a person's own devices;
  `DELETE /devices/{id}` signs one out (a lost phone); `POST /auth/logout` signs this one out; `POST /auth/refresh` swaps the token
  for a new one with a new expiry. A token lasts `API_TOKEN_TTL_MINUTES` (14 days). Changing a password, or deactivating the
  account, deletes every token.
* **Scopes.** Ask for `"scopes": ["read"]` to get a device that can only `GET` (a dashboard on a shared screen). A read-only token
  can still sign out and refresh. Scope only narrows: what a call may do is always also decided by the person's permissions.
* **No cookies.** A browser session never authenticates an API call; CORS is closed until `API_CORS_ORIGINS` names an origin.
* **Throttling.** Sign-in: 5 a minute per e-mail and IP, 20 a minute per IP. After sign-in: 120 a minute per token (60 for writes)
  and 600 per IP. `429 too_many_requests` carries `Retry-After`.

## 2. Which company, which branch

The company is the person's own (their default) — or the one named in the `X-Company-Id` header **if they belong to it**. A company
they do not belong to is **refused** (`403 company_not_accessible`), never quietly replaced by another: an app must not believe it
is writing to one company while it writes to a different one. The branch is never sent by the app, except by someone who is not in
the field when they type in a lead (`location_id`, one of *their* branches); a field lead takes the branch of today's route plan.

## 3. Every answer has one shape

```json
{ "data": { … }, "meta": { "request_id": "…" } }
```
Lists add `meta.pagination` (`page`, `per_page`, `total`, `last_page`, `has_more`) and `meta.server_time`. Failures:

```json
{ "error": { "code": "outside_geofence", "message": "You are 833 m from the nearest stop on today’s plan (Naroda GIDC); you need to be within 500 m.",
             "details": { "reason_label": "Outside the geofence", "violation_id": 41, "attempt": "lead_save" } },
  "meta": { "request_id": "01K7…" } }
```
`error.fields` is `{ "mobile": ["…"] }` for validation. Every response carries `X-Request-Id` (send your own, 8–64 characters of
`A-Za-z0-9._-`, to trace a request through your logs); it is the same id the server writes to its log. Quote it when reporting a problem.

### Error codes

| Status | Code | Meaning |
|---|---|---|
| 400 | `bad_request` | The body is not valid JSON |
| 400 | `idempotency_key_required` | A create was sent without an `Idempotency-Key` |
| 400 | `idempotency_key_invalid` | The key is not 8–80 characters of letters, digits and `- _ : .` |
| 401 | `unauthenticated` | No token, or it expired or was revoked — sign in again |
| 401 | `invalid_credentials` | Same answer for an unknown address, a wrong password and a deactivated account |
| 401 | `account_inactive` | The account was switched off while the token was live |
| 403 | `forbidden` | A permission is missing for this action |
| 403 | `api_access_denied` | The account may not use the API (`api.access`) |
| 403 | `scope_missing` | A read-only device tried to change data |
| 403 | `company_not_accessible` | `X-Company-Id` names a company the person does not belong to |
| 403 | `no_company` | The account belongs to no company |
| 404 | `not_found` | Nothing here — or nothing the person may see (the API does not say which) |
| 405 | `method_not_allowed` | Wrong HTTP method for the address |
| 409 | `state_conflict` | A rule says no because of the state things are in (a lost lead, a follow-up already done) |
| 409 | `already_marked` | Attendance for today is already marked (not a violation) |
| 409 | `version_conflict` | `expected_updated_at` is out of date; `details.current` is the lead as it is |
| 409 | `request_in_progress` | The same request is still running — wait (`Retry-After`) and resend with the same key |
| 413 | `payload_too_large` | The request is too large |
| 415 | `unsupported_media_type` | Send the body as `application/json` |
| 422 | `validation_failed` | `error.fields` says which fields and why |
| 422 | `no_route_plan` | The person has no route plan for today |
| 422 | `plan_not_published` | Today's plan is still a draft |
| 422 | `plan_cancelled` | Today's plan was cancelled |
| 422 | `invalid_gps` | The reading is not a position (not numbers, out of range, or exactly 0, 0) |
| 422 | `low_accuracy` | The device says its reading is worse than the company's limit (default 150 m) |
| 422 | `outside_geofence` | The position is outside every stop's geofence (default 500 m) |
| 422 | `idempotency_key_reused` | The key was used for a different request |
| 429 | `too_many_requests` | Throttled; see `Retry-After` |
| 400–499 | `request_refused` | Any other refused request |
| 500 | `server_error` | Our fault. A generic message and the request id — never details |
| 503 | `busy` | The database chose another request in a race; resend (nothing was saved) |

## 4. The field day

**`GET /route-plan/today`** — the person's own plan for today, stops with the radius that will be applied to each (the stop's own,
else the plan's, else the company's). `data` is `null` with `meta.reason = "no_route_plan"` when there is none; a draft or cancelled
plan is returned as such, so the app can explain why the buttons are off.

**`POST /attendance`** (needs `attendance.create` and an `Idempotency-Key`)
```json
{ "position": { "latitude": 23.0225, "longitude": 72.5714, "accuracy_m": 12 } }
```
The server finds today's **published** plan and checks the position. Refusals are `422` with the reason as the code, each
**written to the geofence violation log** (`details.violation_id`) — a manager sees them under *Field sales → Geofence log*. A
position that is missing or is not a single number is a plain `validation_failed` and is not logged (it never was a reading).
`already_marked` is `409`.

**`POST /leads`** from the field — the same check, for a lead (see §5). A refused attempt is logged as a `lead_save`; the log screen
labels it "Lead saved in the field" and offers no way to accept it (only a refused *attendance* can be accepted by a manager, if the
company switched that on).

The geofence is the server's decision alone. Distances use the haversine formula on the position the device reports; a position
with `accuracy_m` worse than the limit is refused rather than trusted. Editing a lead that already exists is not a field entry and is
not geofenced; neither is a person at their desk who does not hold `attendance.create`.

## 5. Leads, inquiries and deals

| | |
|---|---|
| `GET /leads` | What the person may see: own; a manager's team's and the pool; everyone's with `sales.scope_all`; plus leads they hold a *planned* follow-up on. Filters `q`, `stage` (`open` default, `all`, or one stage), `owner` (`me`, `none`, id), `branch`, `source`, `type` (`new`, `existing`), `due` (`overdue`, `today`, `week`, `none`), `from`/`to`; they only ever narrow that. `per_page` ≤ 100 |
| `POST /leads` | Enter a lead. **Field staff** (`attendance.create`): `position` required, checked against today's plan; branch = the plan's; channel `field`; source defaults to "Field"; the accepted position is stored. **Everyone else:** `location_id` and `lead_source_id` required; channel `manual`. A mobile number an open lead already has is refused until `acknowledge_duplicate` is `true`. **An inquiry from an existing customer** sends `customer_ledger_id` (find it with `GET /lookups/customers?q=`; needs `ledger.view`): the name, contact and address come from the ledger, no mobile is needed, every interest is an approved item with a quantity and may carry `expected_rate`, and `expected_value` is the sum of the lines — see [deals.md](deals.md) |
| `GET /leads/{id}` | One lead with interests, the latest 30 follow-ups and `permissions` (`update`, `change_stage`, `transfer`, `delete`, `follow_up`, `convert`, `submit`); `type`, `customer`, `approval_status` (an inquiry only) and `deal` (once converted) |
| `PATCH /leads/{id}` | Change the fields sent; `interests` replaces the list; `expected_updated_at` guards against a stale edit |
| `DELETE /leads/{id}` | Remove one entered by mistake (`lead.delete`; a soft delete; not once converted) |
| `POST /leads/{id}/stage` | `new` ⇄ `contacted` ⇄ `qualified`; `lost` needs `lost_reason_id`; re-opening needs `comment`; the system alone sets `deal`, `quotation`, `won` |
| `POST /leads/transfer` | `lead_ids` (1–200), `to_user_id`, `reason` — all or nothing, history kept |

A lead the person may not see — another person's, another branch's, another company's — is **404**, the same as one that does not
exist. A lead they can see but may not do this to is **403** (or **409** with the reason when the state is why: lost, converted, or an
inquiry that is waiting for approval and so locked).

### Deals

| | |
|---|---|
| `POST /leads/{id}/convert` | Turn a **qualified** lead (with an owner, one the person works, in a branch they work in) into a deal — **once**. Needs `deal.create` and an `Idempotency-Key`. Body: `expected_close_date` (required), `probability`, `remarks`, `customer_ledger_id`, and `items` — **every line of the lead** (send its `lead_interest_id`) with `item_id`, `unit_id`, `quantity` and `expected_rate`, plus any more. A second conversion of the same lead — from a retry that is not a replay, or a second device — is `409 state_conflict` naming the deal. The number, the amounts, the value, the branch and the owner are the server's |
| `GET /deals` | The deals the person may see (their own; a manager's team's; everyone's with `sales.scope_all`; the ones an approver is asked about). Filters `q`, `stage` (`open` default, `all`, or one stage), `owner` (`me`, id), `branch`, `approval`, `closing` (`late`, `month`); `updated_since` for syncing |
| `GET /deals/{id}` | One deal with its lines (each with its `amount`), its stage history and `permissions` |
| `PATCH /deals/{id}` | **Where it stands** (`expected_close_date`, `probability` 1–99, `remarks`) while the deal is open; **what it is** (`customer_ledger_id`, `items` — which replaces the lines) only while it is a draft or was returned. `expected_updated_at` guards a stale edit |
| `POST /deals/{id}/stage` | `discovery` ⇄ `negotiation`; `lost` needs `lost_reason_id` (the lead is lost with it); back from lost needs `deal.reopen` and a `comment`; `quotation` and `won` are set by the documents that follow — asking for them is 409 |
| `POST /deals/{id}/submit` | Send a draft or returned deal for approval; it needs at least one line. The decision is taken in the approval inbox on the web |
| `POST /deals/{id}/reassign` | `to_user_id`, `reason`: hands the deal and its lead to another person (`deal.assign`; the same checks as a lead hand-over) |

A deal the person may not see is **404**. What the deal's **state** forbids — changing the lines of a deal in approval, moving a lost
deal, converting a lead that is not qualified — is **409 `state_conflict`** with the reason in `message`; what the person has no right
to is **403**. The total and every amount in an answer are the server's arithmetic: a client never sends them.

### Quotations (read only)

| Call | What it does |
|---|---|
| `GET /quotations` | The quotation revisions the person may see (those of their own leads and deals; a manager's team's; everyone's with `sales.scope_all`; the ones an approver is asked about). Filters `q`, `state` (`live` default, `all`, or one state), `deal_id`, `lead_id`, `branch`, `approval`, `latest`; `updated_since` for syncing |
| `GET /quotations/{id}` | One revision with its lines (rate, discount, GST rate, taxable value, CGST/SGST/IGST, total), the totals, the place of supply and its status history |

Quotations are made, approved, sent (PDF by e-mail) and revised on the screens; the API only lets the app look at them. A quotation the
person may not see is **404**. Every amount is the one stored when the draft was saved.

A lead entered from the field (example):
```http
POST /api/v1/leads
Authorization: Bearer 12|Qx9…
Idempotency-Key: 6f1d2c34-9a77-4b0e-8f39-0c5d1a2b3c4d

{ "name": "Shree Ganesh Traders", "contact_person": "Meera Shah", "mobile": "9876543210", "pin_code": "380001",
  "requirement": "Hex bolts, 5,000 pcs", "interests": [{ "item_id": 14, "quantity": 5000, "unit_id": 3 }],
  "position": { "latitude": 23.0231, "longitude": 72.5719, "accuracy_m": 9 } }
```
`201`, `Location: /api/v1/leads/57`, `data.lead_number` like `LD/AHD/26-27/00012`, `data.channel: "field"`, `data.position` the accepted one.

## 6. Follow-ups

`POST /leads/{id}/followups` (plan; `scheduled_at`, `type` = `call|visit|email|whatsapp|meeting`, optional `assigned_to`, `notes`),
`POST /leads/{id}/followups/log` (something just done: `type`, `outcome_id`, optional `notes`, `next_at`, `next_type`),
`POST /followups/{id}/complete`, `/reschedule`, `/cancel` (a reason), `GET /followups` (filters `status`, `type`, `lead_id`,
`person`, `team`, `from`, `to`), `GET /followups/calendar?from=…&to=…` (at most 62 days and 1,000 entries; `meta.window` says what
was used). An outcome that "needs a next follow-up" is refused without `next_at`. Reminders and the manager's overdue notice are the
scheduler's job and do not depend on the app.

**Times.** `2026-10-14T11:00:00+05:30` and `2026-10-14T05:30:00Z` are the same instant; `2026-10-14T11:00` (no offset) is
company wall-clock time. Everything the API returns carries its offset.

## 7. Reference data and syncing

`GET /lookups/items|units|lead-sources|followup-outcomes|lost-reasons|branches|staff` and `/lookups/pin-codes/{pin}`, and
`GET /lookups/customers?q=` (a search of at most 50 approved, active customers — code, name, GSTIN — for naming one on an inquiry;
needs `ledger.view`; the app does not keep a copy of the ledger). Items are
**approved and active only** — never a draft, a pending, a returned or a switched-off item. Branches are the ones the person works
in; `staff` is who they may hand a lead to.

**To keep a copy, ask for what changed.** `updated_since` takes an ISO time and returns what changed at or after it, **oldest change
first**, paged:

1. First run: call without `updated_since` and page until `has_more` is `false`. Remember `meta.server_time` *of the first page*.
2. Next time: `updated_since=<that time>`; page to the end; keep the first page's `server_time` again. (It is the moment the query began, so
   nothing that changed while you were reading pages is missed. Rows may repeat across runs: upsert by `id`.)
3. A lead removed since then arrives with `deleted_at` set — drop it. A follow-up that was called off arrives with
   `status.value = "cancelled"`. A lookup row that was switched off arrives with `active: false`. Items that may no longer be used are
   listed by id in `meta.removed_ids`.
4. A lead **transferred away** from a person stops appearing for them but is not announced: refresh the list (no `updated_since`)
   now and then — the app should treat the server's list as the truth.

**Conflicts.** Send `expected_updated_at` (the `updated_at` you loaded) on a change; a `409 version_conflict` returns the current lead
in `details.current` for the person to look at. Without it the last change wins, field by field (only the fields sent change). Any
change to the lead counts, including a follow-up planned on it. The conflict policy for an offline app — what the server does and what the person
is offered, change by change — is in [offline-sync.md](offline-sync.md).

**A whole queue at once — `POST /sync`.** An offline app can send up to 25 queued changes in one call (`{"operations": [{"id", "method", "path", "body", "ref"}]}`),
each run in order **through the real endpoint it names** (same token, company, throttle, validation, permissions, geofence, approval rules), with the operation's `id` as its
`Idempotency-Key`. `@name` in a path is the id of the record an earlier operation (with `ref: "name"`) made. The call answers 200 with an entry per operation —
`applied`, `replayed`, `conflict`, `rejected`, `retry`, `failed` or `skipped` — and a count of each; only a change that takes an Idempotency-Key can be queued
(`not_syncable` otherwise). The contract, every status and what the app does with it: [offline-sync.md](offline-sync.md).

## 8. Retrying safely — `Idempotency-Key`

Creates need a fresh UUID in `Idempotency-Key` (attendance, lead, follow-up plan/log); other changes accept one. The first request
runs; its **successful** answer is kept for 48 hours; a repeat — a dropped reply, an offline queue replaying — gets the same body and
status back with `Idempotent-Replayed: true` (the body and status, not the `Location` header — take the new lead's address
from `data.id`), and nothing runs again. The same key for a *different* request is `422
idempotency_key_reused`. Two copies arriving together: the database lets exactly one in and the other gets `409 request_in_progress`
(wait a second, resend). A refusal or error does not hold the key, so a corrected request can go again under it. Proven by
`tests/Concurrency/ApiIdempotencyConcurrencyTest.php`, which races separate processes.

## 9. What is logged, and what is never

One line per request in `storage/logs/api.log` (14 days): request id, method, the route *pattern* (`api/v1/leads/{lead}`), status,
milliseconds, user, token and company ids, IP, the *names* of the query parameters, user agent. **Never** a body, a query value (a
search can be a phone number), a header, a password or a token. Issuing, renewing and revoking a token are in the audit log (who,
which device, from where — not the token). A violation refused in the field is in the geofence log with the position the device
reported, how far it was from the stop, the accuracy and the server's time.

## 10. Not yet (and why)

* **Attachments, and making or sending quotations.** There is no attachment store yet (documents are Phase 19). Quotations can be listed
  and read (section 5); making, approving, sending and revising them, and downloading their PDF, are screens-only for now.
  (Deals and inquiries from customers are in — section 5.)
* **Approving or rejecting** an inquiry or a deal is done in the web approval inbox, not over the API.
* **Deal-level follow-ups** are not in the API: follow-ups are planned and recorded on the lead, which stays open while its deal is.
* **No geography lists.** A PIN code fills state, district and block; choosing them by hand from lists is the web form's.
* **Office entry is not geofenced.** A salesperson who enters a lead on the *web* form is not checked; the geofence applies to the
  mobile channel, as the brief words it. If the owner wants web entry blocked for field staff, remove `lead.create` from the web role
  or ask for the rule to be extended.
* **Existing installations** keep their edited permission matrix: grant `api.access` to the roles that should sign in (a fresh
  `migrate:fresh --seed` already does).
* **The offline app's queue.** `POST /sync` is built and tested ([offline-sync.md](offline-sync.md)); the client that stores and sends a queue, and shows pending, conflict and failed
  states, is not (the installable app saves nothing offline yet).
* **Live verification** of a native/PWA client is pending until one exists; the contract is covered by the server-side tests.
