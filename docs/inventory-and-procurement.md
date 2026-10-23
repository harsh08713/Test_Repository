# Inventory and procurement (Loss Intelligence L3)

The decisions and the phase plan are in [loss-intelligence.md](loss-intelligence.md); the formulas in [FORMULAS.md](FORMULAS.md); the tables in [schema.md](schema.md).
Code: `app/Modules/Inventory` (the stock ledger, balances, batches, expiry) and `app/Modules/Procurement` (purchase orders, goods receipts, reorder suggestions).

## How an item is tracked

On the item's **Inventory stage**: *tracking* — none (counted only) or **by batch / lot**; *has an expiry* (only for an item tracked by batch: the expiry
belongs to the batch); an optional *shelf life*. Once stock of the item has moved, its tracking cannot be changed.

## The stock ledger and balances

* Every change of quantity is a row of the **stock ledger** (`stock_movements`): a signed quantity in the item's **base unit**, a unit cost, a value, and the
  document line or the reason behind it. Kinds: receipt, adjustment (found / lost), scrap, transfer in / out (issue and purchase return are reserved).
  A movement is **evidence**: it is never changed or removed — a mistake is put right by another movement.
* **Stock on hand** (`stock_balances`) is one row per branch × item × batch, kept in step by `StockService` in the same transaction, and **rebuildable**
  from the ledger (`php artisan stock:rebuild-balances` reports any row that disagreed — it should be none).
* **Moving average.** Each balance row is its own pool: a receipt adds quantity and value; anything going out takes value at that pool's average; when the
  last unit leaves, the whole remaining value leaves with it, so no rounding residue is ever left. A transfer arrives at exactly the value that left.
* **No negative stock** — asking for more than is on hand is refused, and the database has a CHECK as the backstop.
* **Batches.** An item tracked by batch needs a batch on every movement; one that is not has none. A batch that has **expired**, or is in quarantine,
  cannot be issued or moved — it can be scrapped.
* **Stock entries** (`/inventory/entry`, `stock.create`): stock found, stock lost, scrap, and transfer between branches — each with a reason, each limited
  to the branches the person works in. Goods from a supplier never come in this way: they come through a goods receipt. Stock found is valued at the
  pool's average, else the item's standard cost, else the cost typed in (or it is refused).

## Purchase orders

* Made for an **approved, active supplier** and **approved, active items**, each in a unit the item has, to be received at one branch the person works in.
  The quantity is also kept in base units (exact conversion), and the item's **PO tolerance** is copied onto the line, so a later change of the item does
  not move what was agreed. Numbered `PO/<branch>/<FY>/00001`. **GST is not worked out on purchase orders yet** — the total is quantity × rate.
* The expected date defaults to today plus the supplier's **lead time**.
* An approval document: maker–checker on, anyone holding `purchase_order.approve` decides; pending and approved orders never change.
* **Cancelling** (`purchase_order.edit`) needs a reason, and is refused while a receipt against it is pending or approved.

## Goods receipts

* Made against an **approved, open** order, at the order's branch, by someone holding `grn.create`. Per order line: quantity **accepted** and **rejected**
  (a rejection needs a reason); for a batch item, the **batch number** (and lot, manufacture date) and — for an item that expires — the **expiry date**, which
  must not have passed (an expired batch is rejected, not stocked). A batch number that already exists for the item must carry the same expiry.
* **Tolerance** (FORMULAS.md `grn.max_qty`): what is received on a line — accepted *and* rejected, in base units — may not exceed the ordered quantity ×
  (1 + tolerance %) less what other receipts that are **pending or approved** already took. A draft or rejected receipt reserves nothing. Checked when the
  receipt is saved and again, under a lock on the order, when it is sent for approval.
* The cost of an accepted unit is the order's rate converted to the base unit.
* **Approval puts the accepted goods into stock — once** (`GrnPoster`). The receipt's row lock and `posted_at` decide who posts; the stock service looks
  for an existing movement of that line; `unique(company, source, line, type)` is the backstop. Proved by `tests/Concurrency/StockConcurrencyTest` (five
  processes posting at once make one movement; the test fails when the safeguards are removed). A posting that fails (for example a batch that was made
  with another expiry in the meantime) leaves the approval standing, writes the reason on the receipt and is retried by `procurement:post-grns` every
  15 minutes; "Put into stock now" on the receipt does the same.
* Rejected quantities never reach stock. (A loss incident from a rejection arrives with Phase L4.)

## Expiry watch

`stock:expiry-watch` (daily 06:30): a batch with stock past its expiry date is marked **expired**; a batch with stock expiring within
`inventory.expiry_warning_days` (30 by default, 0 = off) tells everyone in the company holding `stock.view`, **once** (claimed by a conditional UPDATE).
The *Batches and expiry* page shows the value of stock expiring within that window, or already expired (FORMULAS.md `expiry_exposure`).

## Reorder suggestions

`/procurement/reorder`: for every item and branch with stock levels set (Inventory stage), the ones whose stock on hand **plus what is still due on
approved, open orders** is at or below the reorder level. The suggested quantity brings stock up to the maximum; the supplier is the one the item was last
ordered from on an approved, open order, and the date shown is when an order placed today could arrive, given that supplier's lead time. Nothing is ordered.

## Cost of sales

The company setting `costing.method` is now live: **moving average** (the default) costs an invoice line at the average cost of the stock the invoice's
branch holds — the company's stock anywhere if the branch has none — and at the item's standard cost when there is no stock at all; **standard** always uses
the standard cost. The invoice line records which answered (`cost_source`).

## Who may do what (default grants)

| Role | Purchase orders | Goods receipts | Stock |
|---|---|---|---|
| Purchase User | view, create, edit, export, comment | view | view |
| Inventory User | view | view, create, edit, export, comment | view, create (entries), export |
| Accounts User | view, approve, export, comment | view, approve, export, comment | view, export |
| Operations User | view | view | view |
| Approver | view, approve, comment | view, approve, comment | — |

Company Admin and Super Admin hold everything. Sales roles see none of it. Everything is limited to the company and the branches the person works in.

## Known gaps / for the owner

* **Sales do not take stock out.** Assumption 5 (an invoice posts nothing to stock) still holds, so stock is purchases, entries, transfers and scrap only.
  Say if an approved invoice (or a delivery) should issue stock, and if a sales *return* of goods should bring it back.
* No GST, discount or freight on purchase orders; no purchase return to the supplier; no serial numbers; no partial cancellation of an order line.
* No stock-count (cycle count) document: a count difference is a stock entry with a reason.
* A loss incident is not yet raised from scrap, an expired batch or a rejection — that is Phase L4.
* The reorder date is "if ordered today": it does not forecast when stock will reach the reorder level.
