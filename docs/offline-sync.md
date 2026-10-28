# Offline sync — the queue, the conflict policy, and what the server promises

For an app that keeps working without a signal (the installable app, [pwa.md](pwa.md), or a native one). **The server side is built; the app's queue is not** — see
*What is not built*. Brief section 6.14. The rest of the API is in [api.md](api.md); this page is the contract an offline client needs.

## The idea in four sentences

The app records what the person did while offline as a **queue of changes**, each with its own id. When there is a signal it sends the queue to `POST /api/v1/sync`.
The server runs each change **through the real endpoint it names** — so nothing is trusted more because it was queued — and answers, for each, what became of it. The
person sees the states (waiting, conflict, refused, done) and settles what needs them; the server never decides *for* them.

## Sending a queue — `POST /api/v1/sync`

```json
{ "operations": [
  { "id": "6f1d2c34-9a77-4b0e-8f39-0c5d1a2b3c4d", "method": "POST",  "path": "/leads", "ref": "lead-1",
    "body": { "name": "Shree Ganesh Traders", "mobile": "9876543210", "location_id": 3, "lead_source_id": 4 } },
  { "id": "0b9c8a76-22de-4c1f-9a53-7e0d4b1c6a88", "method": "POST",  "path": "/leads/@lead-1/followups",
    "body": { "scheduled_at": "2026-10-14T11:00:00+05:30", "type": "call" } },
  { "id": "d3e7c1aa-5b24-4f0e-8c61-a9f2e0b47d15", "method": "PATCH", "path": "/leads/412",
    "body": { "remarks": "Asked for a call on Friday", "expected_updated_at": "2026-10-12T09:30:00+05:30" } }
] }
```

* **At most 25 operations**, in the order the person did them. Each `id` (8–80 characters: letters, digits, `- _ : .`; a fresh UUID is right) is used once in the
  queue and **is the operation's `Idempotency-Key`**. `method` is `POST`, `PATCH` or `DELETE`. `path` is under `/api/v1` — no query string, no host, no `..`.
* **Only a change that can be sent again safely** can be queued: a route that takes an `Idempotency-Key`. Signing in or out, reads and `/sync` itself cannot (`not_syncable`).
* **`ref` and `@name`.** An operation may be given a `ref`; a later operation writes `@name` in its path and gets the id the first one was given — a lead made offline and a
  follow-up planned for it go in one queue. If the first did not go through, the second is **skipped**, never sent with a guess.
* The device's token and `X-Company-Id` are used for every operation. Each operation is its own request: it is throttled, logged (`api.log`) and audited like any other.

The call answers **200** whenever the queue was readable, whatever happened to each change (a refused device, an expired token, a body that is not a queue are the
usual 401/403/422). Shape: `data.results[]` — `id`, `ref`, `status`, `http_status`, `code`, `message`, `data`, `current`, `fields`, `retry_after` — and
`data.summary` (how many of each status).

## What each status means, and what the app does

| `status` | It means | The app |
|---|---|---|
| `applied` | It ran now. `data` is the record exactly as the real endpoint returned it | Drop it from the queue; update the local copy from `data`; remember `ref → data.id` |
| `replayed` | It had already run (same id): this is the answer it gave then; nothing ran again | The same as `applied` — an earlier reply was lost, that is all |
| `conflict` | The server's state is not what the change was based on: a stale `expected_updated_at` (`version_conflict`, with the record as it is now in `current`) or a record that moved on (`state_conflict`) | **Show both versions and let the person choose** (below). Never resend it unchanged |
| `rejected` | The server will not do it as sent: a rule, a permission, a geofence, a field, a record the person cannot see (400/403/404/422). `code`, `message` and `fields` say why | Mark it *needs attention*; the person fixes the data and sends a **new** operation (new id), or drops it |
| `retry` | It did not run and may be sent again as it is: too many requests (`retry_after` seconds), the same request still in progress, a busy server | Keep it; send the queue again later |
| `failed` | The server broke (5xx). It may or may not have run | Keep it and send it again **with the same id** — if it ran, you get `replayed` |
| `skipped` | It was not tried: `depends_on_failed` (an operation it needs did not go through) or `batch_stopped` (see below) | Keep it; send the queue again |

**A queue is resent from its first unfinished operation, whole.** What went through comes back `replayed` (free); what did not now runs. That is the only
retry logic the app needs.

**What stops a queue.** An expired or revoked token (401), a device or account that is refused (`scope_missing`, `account_inactive`, `api_access_denied`,
`company_not_accessible`) and a throttle (429) stop the batch: everything after is `skipped` with `batch_stopped`, because trying again now cannot go better. A change
that is merely refused or in conflict does **not** hold up changes that do not depend on it.

## The conflict policy

| Situation | What the server does | What the person is offered |
|---|---|---|
| **Duplicate submission** — the queue was sent twice, or a reply was lost | The same `id` is the same change: the first answer is kept for **48 hours** and returned (`replayed`); nothing runs twice. Two copies arriving together: one runs, the other gets `retry` | nothing — it is invisible |
| **Edit of a record someone else changed** (`expected_updated_at` is older than the record) | `409 version_conflict` → `conflict`, with the record as it is now. **The server's version stands; nothing is merged** | **Keep mine** (resend as a new operation with the new `expected_updated_at`, so it overwrites knowingly) or **Use theirs** (drop the change). Show both side by side |
| **Edit with no `expected_updated_at`** | Only the fields sent change; the last change wins, field by field | — send the stamp whenever the record was loaded before the change |
| **Record already moved on** (a follow-up already done, a lost lead, a deal already made from the lead) | `409 state_conflict` with the reason in `message` → `conflict` | Read the reason; usually drop the change |
| **Record no longer visible** (transferred away, deleted, another branch) | 404 → `rejected` — the API does not say whether it exists | Drop the change; refresh the list |
| **Stale master data** (the item, source, outcome or branch the change names was switched off) | The real endpoint refuses what is not usable now: 422 with `fields` → `rejected`; the app's copy of a lookup is never trusted over the server's | Refresh the lookups (`updated_since`; `meta.removed_ids`), let the person choose again |
| **A change that needs a position** (attendance, a lead saved from the field) | Judged **when the server receives it**, against **today's published plan**: outside the geofence, no plan, a low-accuracy reading → 422 and a row in the geofence log (`lead_save` / `attendance`) → `rejected` | Tell the person to be where the plan says, and send **the same day**. A queue sent a day late is refused for the new day's plan: the phone's clock and position are not taken as proof |
| **An approval-gated change** (a deal or inquiry that is pending, an order that is approved) | The real endpoint refuses it (locked) → `conflict`/`rejected` with the state in the message | Read the message; ask for it to be re-opened on the web |
| **Over 48 hours old** | The key may have expired, so a *replay* could create a second record (a lead has its own duplicate-mobile warning; a follow-up does not) | Do not keep a create in the queue longer than a day or two without asking the person |

Never: merge two versions, overwrite silently, apply a queued change the person could not have made live, or let the app choose a winner for them.

## What the app should show (the brief's "pending, conflict, failed")

One list of the person's queued changes, each with a plain state — **Waiting to send**, **Sending**, **Done**, **Needs your decision** (conflict), **Refused: …** (rejected,
with the message), **Will try again** (retry/failed/skipped) — and a count in the header. A conflict opens the two versions. Nothing is shown as saved until the server said `applied`
or `replayed`.

## What is not built

* **The app's queue.** Storing queued changes on the device (IndexedDB), sending them (and Background Sync where the browser has it), the list above and the conflict screen are
  **client work not started**: the installable app ([pwa.md](pwa.md)) shows an offline page and saves nothing offline yet. This page is the contract that work is written against.
* **`captured_at`.** A queued change does not carry when the person made it; the server records when it *received* it. A follow-up logged as "done" offline is stamped with the time the
  queue was sent. Whether (and how far) to trust a device clock is an owner decision.
* **A version number for lookups.** The app learns that reference data is stale from `updated_since` per list, not from one "your copy is out of date" signal.
* **Atomic queues.** Operations are independent (a refused one does not undo an applied one). An "all or nothing" group would need a transaction across endpoints.
* **Several devices of one person** editing the same record offline: they meet as an ordinary `version_conflict` on the second to arrive; there is no device-level merge.
* **Attachments** (photos of a visit) — no attachment store yet (Phase 19).
* **Not verified on a real device.** The contract is proven by server-side tests (`tests/Feature/Api/ApiSyncTest.php`, `tests/Unit/SyncOutcomeTest.php`); no phone has sent a queue.

## How it is built (for the next developer)

`SyncController` checks only the *shape* of the queue. `SyncRunner` turns each operation into a request to `/api/v1{path}` with the parent's `Authorization`,
`X-Company-Id` and the operation's `Idempotency-Key`, checks that the matched route is a replay-safe API route, and dispatches it through the **router** — so the route's
own middleware (token scope, context, throttle, idempotency) and controller run unchanged. It binds each sub-request as the application's `request` for the duration (as the
kernel does) and restores it. `SyncOutcome::classify()` is the only place an HTTP answer becomes a status. **Never add a rule to the runner**; if a queued change must be
refused, refuse it in the endpoint it names, where the live call is refused too.
