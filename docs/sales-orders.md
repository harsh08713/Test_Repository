# Sales orders and sales invoices (Phase 12)

A sales order is what a customer has ordered, at which prices and with which tax, for delivery by a date. It is made from an accepted
quotation, from an approved deal, or directly for a regular customer; it is checked against the customer's credit limit, approved inside
the company, and **approving it makes the sales invoice — exactly one, however many times that is asked for**. Everything goes through
`SalesOrderService` and `InvoiceGenerator`.

```
accepted quotation ─┐
approved deal ──────┼─▶ Sales order (draft) ─▶ credit check ─▶ approval ─▶ DocumentApproved ─▶ Sales invoice (one) ─▶ order: Invoiced
regular customer ───┘      │                                                    └─▶ the deal (and its lead) is Won
                           └─▶ Cancelled (with its invoice) ─▶ frees the quotation and the deal to be ordered again
```

## Where an order comes from

| Source | Rules |
|---|---|
| **An accepted quotation** | cleared by `QuotationConversionGuard`: the latest revision, approved, accepted by the customer and still in date. A quotation that is only *sent*, or has run past its valid-until date, can be ordered from only by someone holding `quotation.convert_exception`, **with a reason** — audited on the quotation and kept on the order. A draft, declined, withdrawn or superseded quotation never can. |
| **An approved, open deal** | no quotation is needed. The deal's lines are priced from the rate master (a deal's rate is only an expectation). |
| **Directly** | for a **regular customer**: one with at least `sales_order.direct_min_orders` approved, uncancelled orders (default 1; 0 lets any customer be ordered from directly). The order is credited to the person making it, in a branch they work in. |

The customer must always be an **approved, active customer ledger**. A deal for a prospect with no ledger yet is ordered once a customer is
chosen on the order form. A quotation and a deal can each have **one live order** at a time (the lock on the source, and the unique keys on
`live_quotation_id` / `live_deal_id`); cancelling the order frees them again.

## What an order is

* **Snapshots.** The customer's name, contact, billing and shipping addresses, GSTIN, the place of supply, the supplier's state, the customer's
  own order number and date, the terms, the price and tax of every line and every total are **copies** taken when the order is saved. The
  masters can change; the order does not.
* **Prices.** From a quotation the default is **copy**: a line that still has the item and unit it was quoted with keeps its quoted price,
  GST rate and any (already audited) rate change — only the quantity and discount can be adjusted. Company setting
  `sales_order.quotation_pricing = rates` looks every line up again in the rate master. A line the quotation did not have, or whose item or
  unit changed, is looked up. A new rate change needs `rate.override`, a reason, and leaves an audit entry on the rate (as on a quotation).
  Tax is `TaxService`'s, by place of supply.
* **Credited to** the salesperson who owns the lead (or made it, for a direct order) — set once and never moved by a later hand-over of the
  lead. Targets count the order for them.
* **Locked** while it is waiting for approval, once approved, invoiced or cancelled: the model refuses any change to its content or its lines
  (only the approval, its state and the notes that go with the state can move). A rejected order can be changed and sent again; an approved
  one can be re-opened (with a reason) only while no invoice has been made from it.
* **Cancelling** (`sales_order.delete`, with a reason; not while it waits for a decision) withdraws the order **and the invoice made from it**.
  The deal, once won, is not put back (see the gaps).
* Never deleted. Its history (`sales_order_history`) is append-only.

## The credit check

`CreditCheck` compares the customer's credit limit (ledger) with what is already out plus this order, as the company's
`credit_limit.behaviour` says: **warn** (allow, show the warning to the approver), **block**, or **ignore**. It is worked out on every save
and again when the order is sent for approval; the answer is kept on the order (`credit_result`, limit, exposure).

* A customer with **no credit limit** (or a limit of zero) has nothing to exceed.
* An order over the limit in a company that **blocks** cannot be sent for approval until someone holding **`credit_limit.override`** allows
  it, **with a reason** (`overrideCredit`) — audited. A bigger order later needs it allowed again.
* **What "already out" means — an assumption to confirm.** Nothing records payments yet (accounting is not in scope), so the system cannot
  know what a customer has paid. It counts the customer's *other* orders that are waiting for approval or approved, not cancelled, whose order
  date is still inside the customer's **credit days**: goods sold on credit are treated as unpaid until their credit period is over. When
  payments are recorded, only `CreditCheck::exposure()` changes.

## The invoice

`InvoiceGenerator::generateFor()` is run by the `DocumentApproved` listener, by the retry command `sales:generate-invoices` (every 15
minutes, and on demand), and by a button on an approved order that has no invoice. It is safe however often it is asked:

1. an invoice that already exists is returned at once, taking no number;
2. otherwise, in one transaction, the **invoice number is taken first**, the order's row is locked, and the existence of the invoice is
   checked again with a locking read — if it has appeared meanwhile the transaction is rolled back (giving the number back, so the series has
   no gap) and the existing invoice is returned;
3. `unique(sales_order_id, source_key)` is the backstop — a second full invoice cannot be inserted.

The invoice is a **copy of the order as it was approved**, with its own number (`SI/AHD/26-27/00001`), a link to the order and to each of its
lines, a due date (invoice date + the customer's credit days) and a PDF. It **posts nothing to stock or to accounts** (not in scope), and an
issued invoice never changes — only its status moves, to *cancelled*, with its order. A failure that is not a duplicate leaves the order
approved and without an invoice, **records what went wrong on the order** (`invoice_error`, shown on its page) and is retried. The invoice
table is ready for invoicing in parts later: `source_key` (`full` now), `sales_order_items.invoiced_quantity`, and a line-level link.

## Targets

Targets are now achieved by sales orders (`SalesOrderAchievement`): only **approved**, uncancelled orders; on the **order date**, for the
salesperson credited; the value **net of tax** (after discount, before GST); **in full when approved**, invoiced or not; quantities in each
item's **base unit**; an item-group target filters the lines and a branch target the order's branch. Returns and credit notes do not exist
yet; when they do they reduce the period in which they are recorded.

## Who sees what

`SalesOrderAccess`: the salesperson credited, the people who manage them and holders of `sales.scope_all` work an order; they, and everyone
holding the new **`sales_order.view_all`** (Accounts and Operations, who handle every order though they sell none), and an approver for an
order waiting for them (or one they decided), may open it. Invoices follow the same rule through their order's salesperson. Another
company's or branch's order is invisible.

## Screens

`Sales ▸ Sales orders` (`/sales/orders`: live and per-state tabs with counts, search, approval and branch filters, what is on the books and
what waits for approval) and `Sales ▸ Sales invoices` (`/sales/invoices`). *Make a sales order* is on an accepted quotation's page and on an
approved deal's page, *Direct order* on the orders list. An order's page shows the figures with CGST/SGST or IGST, the credit check, the
approval panel, the status history and the audit trail, with the actions the person may take; the **order acknowledgment PDF** and the
**invoice PDF** download from their pages (`/sales/orders/{id}/pdf`, `/sales/invoices/{id}/pdf`). There is no API for orders or invoices yet.

## Settings (Company settings ▸ Sales)

| Key | Default | What it does |
|---|---|---|
| `sales_order.quotation_pricing` | copy | price an order made from a quotation as quoted, or from the rate master again |
| `sales_order.delivery_days` | 14 | the delivery date a new order starts with |
| `sales_order.direct_min_orders` | 1 | approved orders that make a customer "regular" for a direct order (0 = any customer) |
| `credit_limit.behaviour` | warn | warn and allow, block, or ignore an order over the limit |

## Permissions

`sales_order.{view,create,edit,delete,approve,export,import,comment,view_audit}` and `sales_invoice.{view,edit,approve,export,comment,view_audit}` from
the matrix (BDE: orders view/create/edit and invoices view; Sales Manager: orders all but audit and import; Approver: view/approve/comment; Accounts:
orders and invoices view, export), plus the specials **`sales_order.view_all`** (Accounts, Operations), **`credit_limit.override`** (Sales Manager
and administrators), `quotation.convert_exception` (Phase 11) and `rate.override`. `sales_invoice.approve` and `edit` are reserved: an invoice
is made from an approved order and needs no approval of its own, and an issued invoice does not change. Super Admin holds everything
explicitly; there is no bypass.

## Known gaps and decisions to confirm

* **The credit exposure is an approximation** (orders inside their credit days count as unpaid) until payments are recorded — see above.
* **A won deal is not put back** when the order that won it is cancelled (a deal that is Won cannot move by hand); the quotation and the deal can be
  ordered again, and the deal's history shows it. Reopening a won deal needs its own decision.
* **One full invoice per order**: no partial invoicing, delivery notes, dispatch or stock issue, and **no accounting postings**.
* **No amounts in words, logo or letterhead** on the PDFs, and no e-mailing of the acknowledgment or the invoice yet (Phase 16 brings the channels).
* **No API** for orders and invoices, and no sales-order import or export yet.
* **"Regular customer"** is "has had N approved orders" (a company setting): the ledger carries no separate flag. Say if you want one.
* **Returns and credit notes** do not exist, so a target never goes down for a return.
