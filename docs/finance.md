# Finance and costing (Loss Intelligence L5)

Code: `app/Modules/Finance`. Decisions and plan: [loss-intelligence.md](loss-intelligence.md). Formulas: [FORMULAS.md](FORMULAS.md).

## Cost centres

A place costs are collected (a plant, a department, a production line): code, name, an optional parent, branch and department of the same company. A plain
master (`/finance/cost-centres`). It cannot be deleted while another sits under it or a budget or loss incident uses it — deactivate it instead. A loss incident
can be **charged to a cost centre** (a field on the loss form); that is what budget against actual measures.

## Budgets

A budget is for one **active cost centre**, one **month**, optionally one **loss category** of an industry template the company uses (no category = the cost
centre's losses of every category together), and an amount in rupees. One budget per cost centre, category and month — a second is refused. An approval
document: Accounts makes it, someone else holding `budget.approve` (the Finance Controller) approves it; **pending and approved budgets never change** (the model
refuses) until re-opened through the approval workflow.

## Budget against actual (`/finance/variance`)

For the months in the period, per cost centre and category (`BudgetVsActual`):

| Figure | Rule |
|---|---|
| budget | Σ **approved** budgets for the period |
| actual | Σ **validated** actual figures (current, not superseded) of incidents charged to the cost centre that occurred in the period, are not duplicates and not rejected — of that category; for an all-categories budget, of every category of the cost centre |
| variance | actual − budget (positive = over budget) |
| variance % | variance ÷ budget × 100, 2 decimals half up away from zero; **none** when the budget is 0 |

A category with actual loss and no budget of its own is shown as *No budget* (it is never hidden), even beside an all-categories budget. Exposure and
opportunity are not actual losses and are not counted. A parent cost centre does **not** add up its children (flat). Rows are limited to the company and
the branches the person works in.

## Item cost history

Every change of what an item costs is written down, append-only: the **standard cost** edited on the item, and the **moving average** after each receipt
(branch, old and new average, the receipt it came from). It is shown on the item page to those who may see stock. (`costing.method` and how a sale takes its
cost are in [returns-and-costs.md](returns-and-costs.md) and [inventory-and-procurement.md](inventory-and-procurement.md).)

## Profitability (`/finance/profitability`, `profitability.view`)

By **product**, **brand** or **site**, for a period (`Profitability`):

* net revenue = gross sales − discounts − approved returns (issued invoices and approved returns dated in the period; tax excluded);
* cost of sales = cost of the invoiced lines − cost of the returned lines (the cost copied onto the lines when they were made);
* gross profit = net revenue − cost; margin % = profit ÷ net revenue × 100, **none** at zero revenue;
* **actual loss** (validated, not duplicates, not rejected) sits **beside** the profit and is not taken off it — the loss values stay apart. A loss with no item is
  the *No item* row (or *No brand*); a sale of an item with no brand is *No brand*.
* a row whose lines had no known cost says so (*cost incomplete*) and the page warns that the profit is not complete.
* The totals of the three views equal each other and `NetRevenueCalculator`.

**Industry as a dimension (changed in L6)**: an item may now belong to an industry (`items.industry_template_id`), so revenue can be split by industry; items with none are
"Unassigned" ([analytics.md](analytics.md)).

## Roles (default grants)

| Role | Cost centres | Budgets | Profitability |
|---|---|---|---|
| Finance Controller | view, create, edit, export | everything, **approve** | view, export |
| Accounts User | view, create, edit, export | view, create, edit, export | view, export |
| Management | view | view | view |
| Company Admin / Super Admin | everything | everything | everything |

Everyone else sees none of it. Everything is limited to the company (and, for sales and losses, the branches) the person works in.

## Known gaps / for the owner

* **Budget scope is losses only.** There are no revenue, expense or capital budgets, and no chart-of-accounts budgets; the dictionary's `account_group_id` on a budget
  is not used. Say if you want budgets for expense heads too.
* A budget is for a month: no quarterly / yearly budget (twelve monthly ones), no copy-forward, no phasing.
* Cost centres do not roll up to their parents.
* Profitability uses the standard / moving-average cost copied onto each line; overheads, freight and discounts allowed after invoicing are not allocated.
* No Plant/Site Head role yet (it needs "my site" data scoping, which the branch scope already gives, but no screen is specific to it).
