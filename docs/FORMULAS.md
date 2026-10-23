# Formulas & Calculation Rules

> Single source of truth for every financial, loss and KPI calculation. Each formula maps to one method in a service class and has a unit test with hand-calculated fixtures. When a formula changes, bump its **version**, keep the old version for records already calculated under it, and log the change at the bottom of this file.

Conventions: amounts `decimal(18,2)`, unit cost/rate `decimal(18,4)`, quantity `decimal(18,3)`. Use PHP `bcmath` (or brick/math) — never floats. Round only at the final stored amount (half-up, 2 dp). All period filters use the document date in the company timezone.

---

## 1. Sales documents

| Key | Formula | Service |
|---|---|---|
| `line.base_qty` | qty × UOM conversion factor to base UOM | `UomService::toBase()` |
| `line.gross` | qty × rate | `GstCalculator` |
| `line.discount` | gross × discount % ÷ 100 (or fixed discount amount) | `GstCalculator` |
| `line.taxable` | gross − discount | `GstCalculator` |
| `line.tax` | intra-state: CGST = SGST = taxable × (GST % ÷ 2) ÷ 100 · inter-state: IGST = taxable × GST % ÷ 100. Intra-state when place-of-supply state = company location state. | `GstCalculator` |
| `line.total` | taxable + tax | `GstCalculator` |
| `doc.total` | Σ line.total + freight/other charges ± round-off | `GstCalculator` |
| `rate.lookup` | latest **approved** Rate Master row where item, location, UOM match and `effective_from ≤ date ≤ COALESCE(effective_to, ∞)`; tie-break: latest `effective_from`, then highest id. No row → validation error (no silent zero). | `RateService::getRate()` |
| `credit.exposure` | outstanding invoices + approved-not-invoiced SOs + this SO total | `CreditLimitService` |
| `credit.check` | exposure > credit limit → warn or block (company setting) | `CreditLimitService` |
| `grn.max_qty` | PO qty × (1 + item PO tolerance % ÷ 100) − already received | `StockService` |

---

## 2. Revenue & profitability (approved documents only)

| Key | Formula |
|---|---|
| `gross_sales` | Σ invoice `line.gross` (pre-tax) for approved invoices in period |
| `discounts` | Σ invoice `line.discount` |
| `returns` | Σ approved sales return / credit note taxable value in period |
| **`net_revenue`** v1 | gross_sales − discounts − returns |
| `cogs` | Σ invoice `line.cost_amount` − Σ returned `line.cost_amount` |
| `line.cost_amount` | base_qty × unit cost at posting (moving average or standard per company setting) — snapshot, never recalculated |
| **`gross_profit`** v1 | net_revenue − cogs |
| **`gross_margin_pct`** v1 | net_revenue = 0 → `null` (display "—"); else gross_profit ÷ net_revenue × 100 |
| `brand_profitability` | net_revenue, cogs, actual_loss, gross_profit, margin grouped by `items.brand_id` |
| `top_brand` | brand with the highest net_revenue in the period (tie → higher gross_profit) |
| `total_products` | count of **approved, active** items in scope |
| `active_templates` | count of industry templates enabled for the company |

Tax is excluded from all revenue figures.

---

## 3. Loss values (kept separate — never added together silently)

| Key | Formula |
|---|---|
| `impact_line.amount` | quantity × unit_cost (or formula key result) — stores `formula_key`, `formula_version`, assumptions |
| **`actual_loss`** | Σ `amount` of impact lines with `impact_type = actual` and `validation_state = validated`, on incidents not Rejected |
| **`estimated_exposure`** | Σ `amount` with `impact_type = exposure` (unconfirmed) |
| **`recoverable_opportunity`** | Σ `amount` with `impact_type = opportunity`; must carry assumptions, confidence %, target period |
| **`realised_savings`** | Σ `verified_result_amount` of CAPA with status Verified — improvement vs approved baseline, adjusted for volume/mix where configured |
| `loss_rate` | loss ÷ configured denominator (output qty, net revenue, material input, operating hours); denominator = 0 → `null` |
| `recurrence` | count of incidents with same category + location + item within rolling 90 days |

**Double-counting rules**
1. Totals sum impact **line ids** (DISTINCT), never incident × line joins.
2. Incidents linked to the same `source_type + source_id` are de-duplicated: only the primary incident's lines count; duplicates are linked and excluded.
3. Recalculation supersedes old lines (`superseded_at` set); only non-superseded lines count.

---

## 4. Default impact formulas (by formula key)

| Formula key | Calculation |
|---|---|
| `material_cost` | qty_lost × item unit cost |
| `scrap_net` | qty_scrapped × unit cost − salvage value |
| `expiry` | expired qty × unit cost |
| `downtime` | downtime hours × cost per hour (location setting) + lost output × contribution margin per unit |
| `rework` | rework hours × labour rate + extra material cost |
| `warranty` | claim count × average claim cost |
| `markdown` | (original price − markdown price) × qty sold |
| `shrinkage` | (book qty − physical qty) × unit cost |
| `billing_leakage` | billable value − billed value |
| `energy_excess` | (actual units − standard units) × tariff |
| `sla_penalty` | penalty per breach × breaches |
| `manual` | user-entered amount with mandatory assumption note |

---

## 5. KPI definitions (seeded per industry template)

Formula: **value = numerator ÷ denominator × multiplier**; thresholds green / amber / red by `direction` (higher-is-better or lower-is-better).

| KPI | Numerator / denominator |
|---|---|
| OEE % | availability × performance × quality × 100 |
| Availability % | run time ÷ planned production time × 100 |
| First-pass yield % | units passed first time ÷ units started × 100 |
| Process yield % | good output qty ÷ input qty × 100 |
| Fabric utilisation % | fabric used in garments ÷ fabric issued × 100 |
| Expiry exposure | Σ (qty × unit cost) of batches expiring within N days |
| Fill-weight variance % | (avg actual − target) ÷ target × 100 |
| Recovery rate % | metal recovered ÷ metal in feed × 100 |
| Energy per tonne | kWh (or fuel) ÷ tonnes produced |
| Distribution loss % | (energy input − energy billed) ÷ energy input × 100 |
| Cost variance % | (actual cost − budget) ÷ budget × 100 |
| On-time delivery % | on-time deliveries ÷ total deliveries × 100 |
| Damage rate % | damaged units ÷ units shipped × 100 |
| Yield per acre | harvest qty ÷ acres |
| Shrinkage % | shrinkage value ÷ net revenue × 100 |
| Bed / room occupancy % | occupied bed/room-nights ÷ available × 100 |
| Billable utilisation % | billable hours ÷ available hours × 100 |
| SLA compliance % | tickets within SLA ÷ total tickets × 100 |
| CAPA overdue | count of open CAPA past due date |

Any denominator = 0 → `null`, shown as "—" with tooltip "No data for period".

---

## 6. CRM metrics

| Key | Formula |
|---|---|
| Lead conversion % | leads converted to deal ÷ leads created in period × 100 |
| Quotation conversion % | quotations converted to SO ÷ approved quotations × 100 |
| Target achievement % | approved SO value (or qty) ÷ target × 100 |
| Route adherence % | planned stops visited (within geofence) ÷ planned stops × 100 |
| Geofence distance | haversine(lat1, lng1, lat2, lng2), Earth radius 6,371,000 m; inside if distance ≤ stop radius |

---

## Change log

| Date | Key | Old → new version | Reason | Approved by |
|---|---|---|---|---|
| | | | | |

---

## Implementation notes (added as built)

| Key | Rule as built | Version | Where |
|---|---|---|---|
| KPI status (green / amber / red) | Higher-is-better: value ≥ green → green; else value ≥ amber → amber; else red. Lower-is-better: value ≤ green → green; ≤ amber → amber; else red. No value, or no green/amber threshold → no status. `threshold_red` is stored for display and ordering only (green is the good side of amber, amber of red; the service refuses thresholds out of order). Comparisons use bcmath at 4 decimals. The thresholds in the 15 seeded templates are **starting examples, not industry standards**; a company sets its own. | 1 | `IndustryTemplateService::status()` / `setKpiThresholds()` |
| Template field validation | `number` whole (≤15 digits), `decimal` ≤4 decimals, optional `min`/`max` (bcmath), `date` `YYYY-MM-DD` and a real calendar date, `datetime` `YYYY-MM-DD HH:MM`, `select`/`multiselect` only from the listed options, `boolean` yes/no, `text` ≤ `max_length` (default 500). An unknown key is refused, not dropped. | 1 | `IndustryTemplateService::validateFields()` |

| Date | Key | Old → new | Reason | Approved by |
|---|---|---|---|---|
| 2026-10-18 | KPI status | – → v1 | first implementation (Loss Intelligence L1) | pending owner review |

| Key | Rule as built | Version | Where |
|---|---|---|---|
| `line.cost_amount` | quantity × `unit_cost`, half-up paise, where `unit_cost` = the item's standard cost per base unit × the line unit's conversion factor (4 decimals, half up), copied once when the invoice is made. No item cost → empty, never zero. | 1 | `CostResolver::unitCost()`, `InvoiceGenerator` |
| Return line figures | `TaxService::line()` on the returned quantity with the invoice line's rate, discount % and GST rate and the invoice's supply type; so a whole line returned equals the invoice line. Cost = returned quantity × the invoice line's `unit_cost`. | 1 | `SalesReturnService::preview()` |
| `net_revenue`, `cogs`, `gross_profit`, `gross_margin_pct` | As §2 above, over issued invoices and **approved** returns dated in the period; margin `null` at zero revenue (2 decimals, half up away from zero); lines with no cost are counted, not read as zero. | 1 | `NetRevenueCalculator::forPeriod()` |

| Date | Key | Old → new | Reason | Approved by |
|---|---|---|---|---|
| 2026-10-18 | cost, return and net-revenue figures | – → v1 | first implementation (Loss Intelligence L2) | pending owner review |

| Key | Rule as built | Version | Where |
|---|---|---|---|
| `grn.max_qty` | per order line: base quantity × (1 + tolerance % ÷ 100), **truncated** to 3 decimals (the tolerance never rounds in the supplier's favour), less the base quantity received (accepted + rejected) on receipts that are pending or approved; never below zero. The tolerance is the item's, copied onto the order line when the order is made. | 1 | `PurchaseOrderService::remaining()` |
| GRN unit cost | order line rate ÷ the line unit's conversion factor, half up to 4 decimals (cost per base unit) | 1 | `GrnService` |
| Moving average (per pool = branch × item × batch) | in: value += round2(quantity × unit cost); out: value −= round2(pool value × quantity ÷ pool quantity), and the **whole** remaining value when the pool empties; unit cost shown = pool value ÷ pool quantity (4 decimals). A transfer arrives at the value that left. | 1 | `StockService::apply()` |
| Moving-average cost of a sale | Σ value ÷ Σ quantity of the item's pools with stock at the invoice's branch, else in the whole company; else the standard cost (half up, 4 decimals), × the sold unit's factor | 1 | `StockQuery::averageCost()`, `CostResolver` |
| `expiry_exposure` | Σ value of balances whose batch expires within N days of today, **or has already expired** (still in stock) | 1 | `StockQuery::expiryExposure()` |
| Reorder suggestion | when on hand + due on approved open orders ≤ reorder level: maximum − (on hand + due); due = Σ max(0, ordered base − received base) | 1 | `ReorderSuggestions` |

| Date | Key | Old → new | Reason | Approved by |
|---|---|---|---|---|
| 2026-10-18 | grn.max_qty, moving average, expiry_exposure, reorder | – → v1 | first implementation (Loss Intelligence L3) | pending owner review |

| Key | Rule as built | Version | Where |
|---|---|---|---|
| §4 impact formulas | as the table in §4; inputs: quantity 3 dp, cost 4 dp, money 2 dp, counts whole; bcmath at 8 dp, rounded once, half up to paise; a negative result is refused. `downtime` without lost output is hours × cost per hour; `shrinkage` quantity is book − physical | 1 | `LossCalculationService::calculate()` |
| §3 `actual_loss`, `estimated_exposure`, `recoverable_opportunity` | current (not superseded) lines of incidents that are not duplicates and not rejected, occurred in the period; actual = validated only; exposure and opportunity = not rejected. Summed from the lines table by incident id | 1 | `LossFigures::forPeriod()` |
| §3 `realised_savings` | Σ `verified_result_amount` of actions **verified** in the period, on incidents that are not duplicates and not rejected | 1 | `LossFigures::forPeriod()` |
| Loss Pareto | validated actual by category, largest first; cumulative % = running total ÷ total × 100 (2 dp, half up); none when the total is 0 | 1 | `LossFigures::pareto()` |
| Budget variance | variance = actual − budget; variance % = variance ÷ budget × 100 (2 dp, half up away from zero), none when budget = 0. Budget = approved budgets for the months in the period; actual = validated current actual lines of incidents charged to the cost centre (not duplicates, not rejected), by category, or all categories for a no-category budget | 1 | `BudgetVsActual::forPeriod()` |
| `brand_profitability` / profitability by product, brand, site | §2 per row: net revenue = gross − discounts − approved returns; cost = invoiced − returned line cost; profit = net − cost; margin = profit ÷ net × 100 (none at 0); actual loss beside, not subtracted; rows with an unknown cost are flagged | 1 | `Profitability::by()` |

| Date | Key | Old → new | Reason | Approved by |
|---|---|---|---|---|
| 2026-10-18 | §3, §4, Pareto | – → v1 | first implementation (Loss Intelligence L4) | pending owner review |
| 2026-10-18 | budget variance, profitability | – → v1 | first implementation (Loss Intelligence L5) | pending owner review |

## Maintenance (M1)

| Figure | Formula | Where |
|---|---|---|
| Downtime of a breakdown | minutes from `stopped_at` to `restored_at` (an open one: to now) | `MaintenanceFigures::forOrder` |
| Breakdowns (an asset, a period) | completed breakdown work orders with both moments, completed in the period | `MaintenanceFigures::forAsset`, report *Asset downtime* |
| Mean time to repair (MTTR) | total downtime ÷ number of those breakdowns (blank with none) | `MaintenanceFigures::forAsset` |
| Next due date of a schedule | the day the job was done + N days / weeks / months (month ends: 31 Jan + 1 month = 28 Feb) | `MaintenancePlan::dueAfter` |
| Spare-part value | what the stock ledger says the part issues were valued at (moving average at the time) | `MaintenanceFigures::partsValue` |
