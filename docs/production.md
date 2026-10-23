# Production (P1)

`app/Modules/Production`. The owner chose **core production** first (bills of materials, orders, material issue, output, rejects, yield), then Quality management (Q1, next). Routing, work centres,
machine capacity, labour/overhead costing and scheduling are **not** built (see the end).

## Bill of materials (`/production/boms`)

* What it takes to make `output_quantity` of a finished item: component lines with a quantity (per that output) and a **wastage %** (0–50), all in each item's **base unit**.
* An **approval master** like a rate: made by one person (`bom.create`), approved by another (`bom.approve`, maker–checker), then **locked** — the model refuses any change while it is waiting or approved. A change is a *new* bill (a new
  code) and the old one is made *inactive* (`bom.edit`). Only an approved, **active** bill can start an order.
* Items must be **approved and active** (`ItemLookup`); a bill may not contain its own item, nor loop back to it through another bill (checked up to 8 levels; a draft counts).
* **Multi-level:** a component that has a bill of its own is made by its own production order and issued like any stock item. `BomService::explode()` follows those bills (the latest approved, active one) down to raw materials for the
  "Down to raw materials" panel; it cuts a loop rather than follow it.

## Production order (`/production/orders`)

`MO/<branch>/<year>/<n>` (number series, taken first in the transaction). **States:** draft → released → in progress → completed, or cancelled.

1. **Plan (draft)** — branch (one the person works in), bill, quantity, dates. The components it requires are **copied from the bill** now: `quantity × planned ÷ output × (1 + wastage)`, rounded to the thousandth. A later change to the
   bill (a new one) moves nothing already planned. A draft can be changed; once released only the entries move.
2. **Release** (`production_order.release`) — re-checks the bill and every item are still approved and active.
3. **Issue material** (`production_order.execute`) — takes a component out of stock at the order's branch through `StockService::issue` (once per entry; value out at the pool's moving average). Up to the required quantity **plus
   the allowance** (company setting `production.over_issue_percent`, default 10). A batch-tracked component needs its batch (the form offers usable batches, soonest expiry first).
4. **Report output** (`production_order.execute`) — good units are received into stock; rejects are recorded with a reason. Totals (good + rejected) may reach the plan **plus the allowance**
   (`production.over_production_percent`, default 10). A batch-tracked finished item needs a batch number (and an expiry if it has one); a batch is found by number or made.
5. **Complete** (`production_order.release`) — needs output; **short of the plan it needs a note**. **Cancel** — only before any material was issued or output reported (after that: complete it).

### Cost of the finished goods

Finished goods are received at **material cost only**: the value that left stock for the order (what the stock ledger says, at the moving average when each issue was made) ÷ the good units made so far including this entry. Rejects raise
the cost of the good units; an earlier output keeps the cost it was received at. **Labour and overhead are not counted.**

### What is worked out, never stored (`ProductionFigures`)

Issued per component, produced, rejected, **yield** = good ÷ (good + rejected), how much more may be issued or produced, the material value and the unit cost, and the **material variance** per component =
issued − (required × made ÷ planned), where *made* is good + rejected. The order page, the list and the *Production orders* report all read the same entries.

## Safety

* The **order row is locked first** for every change, so people issuing at the same moment take turns: with 100 required and a 10% allowance, six people each issuing 30 get **three** entries (90) and three refusals — proved with six
  real processes (`tests/Concurrency/ProductionConcurrencyTest.php`, fails without the lock).
* Every issue and output carries an **entry key** made when the form opened, `unique(order, key)`: a double click or a retried request is one entry and one stock movement.
* Issues and outputs are **append-only evidence** (the models refuse update and delete). A mistake is put right by a stock correction and a note on the order, never by editing an entry.
* Stock is changed only through `StockService` (`issue`, `receive`); a refusal (not enough on hand, an expired or quarantined batch) rolls the entry back.

## Who may do what

| Role | Can |
|---|---|
| Production Manager | make bills (another person approves them), plan, release, complete and cancel orders, issue and report output; see stock; reports |
| Production Operator | see orders and bills; issue material and report output |
| Approver | approve bills (like any document) |
| Operations, Management, Quality/Loss Manager | see bills and orders |

Reports: **Production orders** (`report.view` + `production_order.view`, exportable).

## Not built (decisions for the owner)

Routing and work centres, machine capacity and a schedule; labour and overhead costing; returning unused material to stock; material reservation / MRP; by-products and co-products; alternative components; a quality hold on output (Q1);
automatic loss incidents from rejects (Q1); production orders from sales orders; barcode scanning; units other than the base unit on a bill.
