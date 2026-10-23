# Verified Savings Ledger (R2)

Code: `app/Modules/Loss` (`SavingsService`, `SavingsMeasurement`, `SavingsMeasurementPolicy`, `LossFigures::actualFor()` / `financeVerifiedSavings()`),
screens `/losses/savings` and `/losses/savings/{measurement}`, command `savings:check-recurrence` (daily 03:00, only while the scheduler runs).
Origin: module 8 of the "Industrial ERP 2026" brief.

## What it answers

*Did a corrective action really save money, and has Finance verified it?* Until now a corrective action was "verified" by someone typing a number
(`corrective_actions.verified_result_amount`, the **realised saving**). That figure stays as it is. A **savings measurement** is the evidence-based
second step: Finance-verified savings are a different figure, shown beside it and **never added to it**.

## A measurement

One per corrective action (`unique(corrective_action_id)`), made only from an action that someone else has already verified.

| Part | Rule |
|---|---|
| Loss measured | the validated **actual** loss of the incident's loss category, at its branch (and item, if it has one) — `LossFigures::actualFor()`, counted exactly as the loss figures are: duplicates, rejected incidents, rejected and superseded lines count for nothing. Exposure and opportunity are never part of a saving |
| Baseline | a period before the action was done (default: the 30 days before) |
| Window | a period after it was done, ended, not in the future (default: from the day it was done until today); at least 7 days each when submitted |
| Normalisation | the baseline is scaled by **volume** (both volumes entered: baseline × window volume ÷ baseline volume) or else by **days** (× window days ÷ baseline days). Half-up to the paisa |
| Saving | scaled baseline − actual loss in the window = *gross*; − what the action cost = **net** (it can be negative: the loss got worse) |
| Evidence | what supports the figures, 10 to 2,000 characters, required to submit |

The losses are read from the loss records when the draft is saved and again at submission; the submitted figures are the evidence the approver saw.

## Steps

`draft` → (submit) `submitted` → (Finance) `verified` or `rejected`; a rejected one is re-opened to `draft`.

- **Measure and submit:** `savings_measurement.create` / `.edit` (Quality/Loss Manager, Company Admin). Only a draft can be edited.
- **Verify or reject:** `savings_measurement.approve` (Finance Controller, Company Admin) — **never the person who measured it and never the owner of the action**; a note is required.
- **Nothing changes after submission**: the model refuses an edit of its figures, a verified measurement changes only to say it recurred, and nothing is deleted.
- **The same loss cannot be claimed twice**: two live (submitted or verified) measurements of the same category and branch (and item) may not have overlapping windows; checked at submission under a lock on the branch. A rejected one no longer claims the loss.
- **Recurrence** (`savings:check-recurrence`): a verified measurement whose category recurs at the same branch (and item) after its window is flagged once (claimed by a conditional UPDATE) with the first incident that came back. The saving stays verified; it is shown as *recurred*.

## Figures

`LossFigures::financeVerifiedSavings(from, to)` → `net_saving`, `count`, `recurred` for measurements decided in the period. `forPeriod()['realised_savings']` is the corrective actions' own verified result. They are different stages of the same idea and are not summed anywhere.

## Not built / open for the owner

- **Notices:** sending a measurement tells everyone who holds `savings_measurement.approve` once, except the maker and the action's owner; a saving flagged as recurred tells the maker and the person who verified it once. A failed notice is logged, never thrown (`LossNotifier`).
- The window rules are fixed (7 days minimum); no seasonality adjustment or price/mix normalisation beyond one volume.
- Savings are on the executive dashboard and in the report list (40 reports in all), but not in the API.
- A saving is scoped by loss category, branch and item; a saving that spans branches needs one measurement per branch.
- The overlap rule is tested for one process; no multi-process race test for the branch lock.
