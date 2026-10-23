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

## L6 — dashboard figures

* **Change on a card** = (this period − previous period) ÷ previous period × 100; blank when the previous period is 0. The previous period is the same number of days ending the day before.
* **Share** = amount ÷ sum of the listed amounts × 100 (rounded half up, 2 places).
* **KPIs measured today:** `expiry_exposure`, `capa_overdue`, `open_count`, `gross_margin_pct` (gross profit ÷ net revenue of the industry's items × 100), `shrinkage_pct` (validated actual shrinkage loss ÷ the industry's net revenue × 100). All others are "Not measured yet".

## H2 — leave and attendance

* **Working days of a request** = the days from the first to the last, less company holidays and the weekly off; a half day is 0.5 on a single working day.
* **Monthly accrual** for month *i* (0 = first month of the leave year) = round-half(quota × (i+1) ÷ 12) − round-half(quota × i ÷ 12), so the twelve months add up to exactly the quota. **Yearly accrual** for
  someone who joins mid-year = round-half(quota × months left ÷ 12), the joining month counted whole. round-half = nearest 0.5.
* **Carry-forward** = min(last year's balance, the type's limit rounded down to 0.5), if the balance is positive; the rest lapses.
* **Balance available to a new request** = ledger balance − days of other waiting requests of the same type in that leave year.
* **Attendance day**: worked = last punch out − first punch in; present ≥ the shift's full-day minutes, half day ≥ its half-day minutes, otherwise absent; late = first in − shift start, if after the grace.

## H4 — performance

* **Weighted goal progress** = Σ(weight × progress) ÷ Σ(weight), one decimal; the weights of one person's goals in a cycle add up to at most 100. Example: weights 60 and 40 at 70 % and 90 % = (4200 + 3600) ÷ 100 = 78.0 %.
* **Final rating** = HR's chosen 1–5, or the manager's rating when HR does not choose another.
