# Maintenance (M1)

`app/Modules/Maintenance`. The owner chose **assets + preventive schedules + work orders** (spare parts from stock, hours, downtime) and a **loss incident linked by hand**. Meter-based triggers (running hours, kilometres) and a calibration
register are **not** built (see the end).

## Assets (`/maintenance/assets`)

A machine, vehicle, instrument, building or other thing that is maintained: a typed **code** (unique in the company), name, kind, make and model, serial number, installed date, **criticality** (low / medium / high: stops production), the
**branch** that owns it (one the person works in) and an optional cost centre. **Never deleted**: it is made inactive — and not while it has open work orders. The asset page shows the last twelve months: breakdowns, downtime, mean time to repair,
hours worked and the value of spare parts, then its schedules and work orders.

## Preventive schedules (`/maintenance/schedules`)

"This job, on this asset, every *N* days / weeks / months", with a checklist (one task per line), the hours it takes, and how many days **ahead** its work order is made (`lead_days`, default 3). `maintenance:generate-orders` (daily 05:30) makes the
work order once the due date is within the lead time — **once for each due date** (`unique(schedule, due date)`) and **never while the schedule still has an open one**. When that order is completed the schedule moves on **from the day it was done**
(1 month after 31 January is 28 February). A schedule can be switched off; one on an inactive asset makes nothing.

## Work orders (`/maintenance/orders`)

`MW/<branch>/<year>/<n>`. A **breakdown** (records when the asset *stopped*), a **preventive** job (made by a schedule) or a **corrective** job. A breakdown on a high-criticality asset reported at normal priority becomes *urgent*.

1. **Report or plan** — anyone who may create (maintenance, production, quality) can report. Only someone who may assign can name a technician (an active user who may do maintenance work).
2. **Do the work** (`maintenance_order.execute`) — only on an open order that is **unassigned, assigned to you, or you may assign**. *Start* (also done by the first tick, part or hours), **tick tasks**, **take spare parts from stock**
   (`StockService::issue` at the order's branch, approved active items only, once per entry key), **log hours** (more than 0, at most 24, a day within the last 30).
3. **Complete** — needs **every task ticked** and a note of what was done; a breakdown records when the asset was **running again** (not before it stopped, not in the future). A preventive order moves its schedule on.
4. **Cancel** — only before any part was issued or hours logged (after that, complete it).
5. **Link a loss incident** — by hand, from a list of recent incidents (a breakdown that cost output). Nothing is created for you.
6. Entries (parts, hours) and tasks done are **records**: the models refuse update and delete. A completed or cancelled order does not change.

## What is worked out, never stored (`MaintenanceFigures`)

* **Downtime** of a breakdown = from when it stopped to when it was running again (for one still open, to now), in minutes.
* **Mean time to repair** (an asset, a period) = total downtime of the **completed** breakdowns ÷ their number.
* **Hours worked** = the sum of the hours logged; **spare-part value** = what the stock ledger says the parts were issued at (the moving average at the time).

The order page, the asset page and the two reports (**Maintenance work orders**, **Asset downtime**) read the same entries; a test holds the report equal to the asset page.

## Safety

* The order's **row lock is taken first** for every change; every part and hours entry has an `entry_key`, `unique(order, key)` — a double click or retried request is one entry and one stock movement.
* Proved with six real processes (`tests/Concurrency/MaintenanceConcurrencyTest.php`): six generator runs make **one** order for a due date; six identical spare-part entries make **one** entry and take the stock down once. Both fail without their safeguards.

## Who may do what

| Role | Can |
|---|---|
| Maintenance Manager | assets, schedules, plan and assign work orders, do the work, cancel, link incidents; stock view; reports |
| Maintenance Technician | see assets and schedules; report work; do the work on orders assigned to them or to nobody |
| Production Manager / Operator, Quality/Loss Manager | see assets; report a breakdown (and see work orders) |
| Operations, Management | see assets, schedules and work orders |

## Not built (decisions for the owner)

Meter readings and meter-based triggers (running hours, kilometres, counts); a **calibration register** for instruments (due dates, out-of-calibration holds — the item left out of Quality too); labour cost (no hourly rates, so only hours are shown);
parent / child assets and bills of spare parts per asset; a checklist template shared by schedules; attachments and photos; notifications when a work order is assigned or overdue; mobile / offline use; a loss incident created for you from a breakdown.
