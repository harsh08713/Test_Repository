# Quality management (Q1)

`app/Modules/Quality`. The owner chose **inspections + non-conformances + release** first. SPC / control charts, instrument calibration and supplier quality ratings are **not** built (see the end).

## Inspection plans (`/quality/plans`)

* What to check on an item at one **stage** — incoming goods, in process, or final — as a list of checks: a **measurement** between limits (either limit may be left open, not both; either is inclusive) or a **pass / fail** check, each optionally
  **critical**.
* An **approval master** like a bill of materials: made by one person (`inspection_plan.create`), approved by another (`inspection_plan.approve`, maker–checker), then **locked** (the model refuses any change while it is waiting or approved). A change
  is a new plan; the old one is made inactive. Only an approved, **active** plan can start an inspection.

## Inspections (`/quality/inspections`)

`QI/<branch>/<year>/<n>`. Started against a plan, a branch the person works in, the size of the lot and — for an item tracked by batch — the batch. It can be started from a **production order** ("Inspect the output") or a **goods receipt**
("Inspect the goods"), which fill in the branch and source.

1. **Start** copies the plan's checks as the results to fill in (with their limits, as evidence) and **puts the batch on hold** (status *quarantine*): `StockService` then refuses to issue or move it — it can still be scrapped. Only **one** inspection
   of a batch is open at a time. A batch already on hold because an earlier inspection failed is **taken over** (a re-inspection can release it); one on hold for any other reason, or expired, is refused.
2. **Record** — measurements and pass / fail. **Whether each passed is worked out by the service from the limits, never typed.** Allowed while the inspection is open.
3. **Complete** — needs every check recorded. All within limits → *accepted* and the hold is **released**. Any outside → *failed*; the batch **stays on hold**.
4. **Decide a failed inspection:** **reject** (`inspection.decide`: raises a non-conformance report — *critical* if a critical check failed, else *major* — and the batch stays on hold) or **accept with a deviation**
   (`inspection.deviate`: a reason of at least 10 characters, **never by the person who did the inspection**; the batch is released). **Cancel** an open inspection (reason required) to release its hold.
5. A finished inspection and its results are **a record**: the models refuse changes.

## Batch holds (`BatchHoldService`)

Every hold and release is a row in `batch_holds` (append-only: who, when, why, which inspection / report). The status change is a conditional update, so two people holding or releasing the same batch at once change it once.

## Non-conformance reports (`/quality/non-conformances`)

`NCR/<branch>/<year>/<n>`. Raised by a rejected inspection (with the failed checks in its words, the lot quantity and the batch) or by hand. States: **open → decided → closed**.

* **Decision** — rework, scrap, **use as is**, return to the supplier, or sort. *Use as is* is a deviation: it needs `inspection.deviate` and releases the batch.
* **Links** — to the **loss incident** and the **corrective action** (the same company's; a corrective action brings its incident with it; if both are given they must belong together).
* **Close** (`non_conformance.close`) — only once decided; a **critical** report only with a corrective action linked. A closed report is a record.
* Scrapping the stock itself is still done on the Stock screens (an NCR records the decision; it does not move stock).

## Who may do what

| Role | Can |
|---|---|
| Quality/Loss Manager | make plans (another person approves them), approve others' plans, start and record inspections, reject or accept with a deviation, decide and close reports; reports |
| Quality Inspector | start and record inspections, raise reports; see plans |
| Approver | approve plans |
| Production Manager, Operations, Management | see inspections, plans and reports (a Production Manager can also raise a report) |

Reports: **Inspections** and **Non-conformances** (`report.view` + the view right, exportable with `report.export`).

## Safety

* The inspection's row lock is taken first for every change; the batch row is locked, and the "is it already being inspected / is it held by us" checks are **locking reads** (a plain read inside a transaction is a snapshot and would miss the winner). Proved with six real
  processes in `tests/Concurrency/QualityConcurrencyTest.php`: only one of six starts an inspection of a batch, and only one of six rejects a failed inspection (one report) — both fail without the locks.

## Not built (decisions for the owner)

SPC / control charts and capability (Cp, Cpk); an instrument calibration register; supplier quality ratings and supplier corrective-action requests; sampling plans by lot size (AQL tables); an automatic hold on every goods receipt or production output
(today an inspection is started by a person); manual hold / release of a batch from the batch screen; photos or attachments on results; scrapping stock from a report; a loss incident raised from a report in one click.
