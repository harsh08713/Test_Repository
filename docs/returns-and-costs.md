# Returns, credit notes, item cost and net revenue (Loss Intelligence L2)

Built on top of [sales-orders.md](sales-orders.md). The decisions and the phase plan are in [loss-intelligence.md](loss-intelligence.md); the formulas are in [FORMULAS.md](FORMULAS.md).

## Brand and standard cost on an item

Purchase sets both on the Purchase stage of the item. The **brand** is optional and must be an active brand of the company. The **standard cost** is
the cost of one *base* unit (up to 4 decimals, never negative); it is optional too. Both are shown on the item page.

## The cost on an invoice line

When an invoice is made (`InvoiceGenerator`), each line copies the item's cost **once**: `unit_cost` (per unit of the line — the standard cost times
the unit's conversion factor), `cost_amount` (quantity × unit cost, half-up paise) and `cost_source`. It is never recalculated: changing the item's
cost later does not touch invoices already made, and the invoice line refuses any change.

* An item **with no cost** leaves the three columns **empty** — unknown is not zero. The invoice itself is made as usual.
* Invoices made **before** this release have no cost (they cannot be filled in truthfully afterwards); the profit figures count such lines.
* The company setting `costing.method` decides (`CostResolver`): **moving average** (default) uses the average cost of the stock the invoice's branch
  holds, else the company's stock anywhere, else the standard cost; **standard** always uses the standard cost. The stored `cost_source` says which
  answered. (Moving average became live with Phase L3 — see [inventory-and-procurement.md](inventory-and-procurement.md); earlier invoices keep what they took.)

## Returns and credit notes

A **sales return** (goods come back) or a **credit note** (money is credited, goods stay) is made against **one issued invoice** (not a cancelled one).
They are the same document with a `return_type`; both reduce net revenue the same way; they will differ for stock in L3.

* **Making one** — from the invoice page ("Return or credit…"). Choose the kind, a date (between the invoice date and today), a reason, and how
  much of each invoice line comes back. Prices, discount and GST are the invoice line's own and cannot be changed: a whole line returned reproduces the
  invoice line to the paisa. The credit is worked out by `TaxService` (the same arithmetic as the invoice); the total is rounded to the rupee as an invoice is.
* **How much can come back** — a line's invoiced quantity less what other returns have taken that are **pending or approved**. A draft or rejected one
  reserves nothing. It is checked when the return is saved, and again — under a lock on the invoice — when it is sent for approval, so two returns that
  each fit alone cannot both go through. A unit that cannot be split takes whole numbers only.
* **Numbering** — `SR/<branch>/<FY>/00001`, one series for returns and credit notes, taken first in the transaction.
* **Approval** — the shared workflow, maker–checker on (the person who made it cannot approve it). No fixed approver role: anyone holding
  `sales_return.approve`. **Pending and approved returns never change** (the model refuses), and none is ever deleted.
* **Cost** — each line copies the invoice line's own cost snapshot (unit cost × returned quantity), never today's cost. If any returned line had no
  cost, the return's `cost_total` is empty.
* **Cancelling the order** — an order whose invoice has a pending or approved return or credit note cannot be cancelled.

### Who sees what

A return is seen by whoever may see its invoice: the salesperson credited with the order, the people who manage them, `sales.scope_all`, and everyone
holding `sales_order.view_all` (Accounts, Operations); an approver can also open one waiting for their decision. Making one needs `sales_return.create`
**and** being able to see the invoice. Default grants: Accounts — everything, approving included; Sales Manager — view, create, edit, comment; BDE,
Operations — view; Approver — view, approve, comment. Company Admin and Super Admin hold every permission.

## Net revenue (`NetRevenueCalculator`)

`NetRevenueCalculator::forPeriod($from, $to, $locationId = null)` is the only place the figures of FORMULAS.md §2 are worked out — the dashboard and
reports of Phase L6 must use it:

| Figure | Rule |
|---|---|
| gross sales | Σ gross amount of the lines of **issued** invoices dated in the period (a cancelled invoice counts for nothing) |
| discounts | Σ discount of those lines |
| returns | Σ taxable value of **approved** returns and credit notes dated in the period |
| net revenue | gross sales − discounts − returns |
| cost of goods sold | Σ cost of those invoice lines − Σ cost of the returned lines |
| gross profit | net revenue − cost of goods sold |
| margin % | **null** when net revenue is 0, else gross profit ÷ net revenue × 100, 2 decimals |
| `lines_without_cost`, `cost_complete` | how many lines have no known cost; a report with any must say its profit is not complete |

Tax is excluded from every figure. The sums run through the tenant scopes, so a person sees the company and branches they work in.

## Known gaps / for the owner

* A return is **not** a stock movement yet (Phase L3), and posts nothing to accounts.
* **Dated by approval or by the form?** A return counts in the period of its `return_date` (up to today), not of its approval; a return approved a month
  after its date lands in the earlier month. Say if net revenue should follow the approval date instead.
* No PDF for a credit note yet; no e-mail; no API endpoint; no return of an invoice made before the cost column existed *with* its cost.
* One series for returns and credit notes; a separate `CN` series is a one-line change if the books need it.
* Partial invoices do not exist yet, so a return is always against a full-order invoice.
