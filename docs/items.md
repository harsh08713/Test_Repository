# Items

The item master is filled in by three departments in turn. Screens: *Masters → Items*. Tables and relationships:
[schema.md](schema.md). The approval engine underneath: [approvals.md](approvals.md).

```
Purchase ──send──▶ Inventory ──submit──▶ awaiting approval ──approve──▶ approved
   ▲                  │                         │
   └──── return ──────┘ ◀──── reject (to either stage) ┘        approved ──re-open (to either)──▶ back for rework
```

| Stage | Who (permission) | Fills in | Can move it on |
|---|---|---|---|
| **1. Purchase** (`purchase_draft`) | Purchase (`item.manage_purchase_stage` + `item.edit`; `item.create` to start one) | name, item group, base unit, other units ("1 Box = 12 Nos"), PO tolerance %, HSN/SAC, GST rate, description | **Send to Inventory** once name, group, base unit, HSN and GST rate are in |
| **2. Inventory** (`inventory_pending`) | Inventory (`item.manage_inventory_stage` + `item.edit`) | item code, alias names, minimum / reorder level / maximum stock per branch | **Submit for approval** once a code is assigned; or **Return to Purchase** with a reason |
| **3. Approval** (`approval_pending`) | the role items are routed to: **Accounts** by default | nothing | **Approve**, or **Reject** naming the stage it goes back to (comment required) |
| **Approved** | — | — | only **re-open** (a reason, and which stage), or switch active/inactive |

Each stage can only be edited by its department, and only while the item is in that stage: Purchase cannot touch an item once
it is with Inventory, and Accounts edits nothing. **Operations can view an item and comment on it, and every attempt to change
it is refused (403)** — it holds neither stage permission, nor approval.

## Rules the data must satisfy

* **Name** unique within the company (ignoring case and spacing). **Code** unique within the company, assigned in stage 2
  (`ITM-00007` is suggested); items without a code yet do not clash.
* **Item group** and **base unit** must be active and the company's own.
* **HSN/SAC** is 4, 6 or 8 digits. **GST rate** must be one of the slabs in `config/formezy.php → gst_rates` (edit the list when
  the law changes; rates already on approved items are never rewritten). **PO tolerance** is 0–100 %, two decimals.
* **Unit conversions** (up to 10): "1 *unit* = *factor* base units", factor > 0 with at most 6 decimals, each unit once, never
  the base unit itself. If the base unit cannot be split (Nos, Box…), a factor must be a whole number ("1 Box = 2.5 Nos" is refused).
* **Aliases** (up to 20): unique within the company, never the item's own name or code, never another item's name, code or alias.
* **Stock levels** per branch (up to 100): one row per branch; `minimum ≤ reorder level ≤ maximum`; quantities have at most 3
  decimals and are whole numbers when the base unit cannot be split. A person who works in one branch sees and changes only that
  branch's row; the other branches' rows are left exactly as they were.

### Once an item has been approved

An approved item may already be on documents, so its identity is frozen from then on: its **code** and **base unit** cannot be
changed, and its **existing unit conversions** can be neither changed nor removed — new units can be added. (Name, group, HSN, GST
rate, tolerance, aliases and stock levels can still be corrected through a re-open.) An item that was ever approved cannot be
deleted, only switched off.

## Unit conversions: `UomConverter`

Exact and deterministic: `convert($item, $quantity, $fromUnit, $toUnit)` works in `bcmath` (never floats), computes
`quantity × factor(from) ÷ factor(to)` at 12 decimals and rounds **half up to the 3 decimals** a stored quantity has.

* A unit that cannot be split only ever yields a whole number: 250 Nos is 2.5 Box, so converting it to Box is **refused**, never
  rounded — stock must not appear or vanish.
* Quantities go in and come out as strings (`"2.500"`); negative, over-precise or non-numeric input and a unit the item has no
  conversion for are errors, not guesses (`UomException`).

## Routing: who approves

* **No approval levels configured** (the default): the item is routed to one role. The company setting
  `approvals.item_approver` (*Administration → Company settings*) picks it: **Accounts** → the `Accounts User` role (default),
  or **Operations** → the `Operations User` role. Only people holding that role *and* `item.approve` can decide; the Approver
  role and Company Admin hold the permission but are not designated. Operations has **no** approval permission by default, so
  routing items to Operations only works once the company explicitly grants `item.approve` to that role — until then submission
  stops with a message saying nobody can decide the item.
* **Approval levels configured** (*Administration → Approval levels*): those win — roles or named people, several levels,
  sequential or parallel. Maker–checker still applies: nobody approves an item they created or submitted.

## What is recorded

* **Stage history** (`item_stage_history`, append-only): every move — started, sent to Inventory, returned to Purchase,
  submitted, approved, rejected (and where it went), re-opened — with who, when and the comment. It is written by the `Item`
  model itself, so it holds whichever screen or service drove the approval engine (the item page, the approval inbox, an API).
* The **approval ledger** (each decision, level, comment, round) and the **audit log** (every field change; unit rows, aliases and
  stock rows are audited under their own names).
* A rejection keeps the item's approval status as *rejected* while its stage shows who has it again: the list shows
  "Returned to Purchase". Resubmitting starts a new approval round; the old one stays on record.

## Using items from other modules

Never query `Item` directly for a picker or an API. `ItemLookup` returns only **approved, active** items of the current company:

```php
app(ItemLookup::class)->search('bolt');          // code, name, alias or HSN
app(ItemLookup::class)->usable($id);             // null for anything not approved, inactive, deleted or another company's
```

`GET /masters/items/lookup?q=bolt&limit=20` serves the same as JSON (id, code, name, HSN, GST rate, PO tolerance, base unit and
units with their factors); the company is never a parameter. Convert quantities with `UomConverter`.

## Known limits

* An approved item is changed by re-opening it (it leaves the pickers until approved again); there is no "pending revision" that
  keeps the approved version in use meanwhile.
* No import or export of items yet (the `item.import` / `item.export` permissions exist for when they arrive).
* A removed unit conversion, alias or stock row is deleted outright (its unique key must be reusable); the audit log keeps what it held.
* Transactions that use items arrive in Phases 10–12, so "in use" checks cannot look at them yet; the freeze on an approved
  item's code, base unit and conversions stands in for them.
