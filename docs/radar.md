# Profit Leakage Radar (R1, first slice)

Code: `app/Modules/Loss/Radar`, screen `/losses/radar`, command `radar:sweep` (daily 02:30, only while the scheduler runs).
Origin: the "Industrial ERP 2026" brief, module 1. This is the first slice of its Phase 2; the rest of that brief is planned below.

## What it does

A daily sweep looks through each company's stock, receipts and scrap for records that look like a loss and keeps one **finding** for each.
A finding is a **proposal**: it creates no loss incident and adds to no loss figure. A person either

- **reports it** — the loss form opens with the source filled in (`/losses/report?source=…&id=…`); the person checks it and saves it, and the next
  sweep or page load links the finding to the incident (`syncReported`), or
- **dismisses it** with a reason of at least 10 characters (`radar_finding.edit`). A dismissed finding is never proposed again.

Findings are graded low / medium / high at 5× and 20× the company's minimum (`radar.min_amount`), and listed largest first with the evidence
(source, formula, inputs, assumptions, method, first seen / data as of).

## Detectors (v1)

| Detector | Looks at | Kind of figure |
|---|---|---|
| `expired_stock` | a batch past its expiry date with stock on hand | actual (stock can be sold to nobody) |
| `scrap` | stock scrapped or adjusted out in the look-back period | actual |
| `rejected_receipt` | goods rejected on an approved goods receipt in the look-back period | exposure (until the supplier settles) |
| `production_variance` | a production order **completed** in the look-back period that used more material than the standard for what it made (good plus rejected); each component's excess at its average issue cost, components that used less do not offset it | actual; formula `manual` (the sum is worked out in `LossSources`) |
| `price_variance` | an approved receipt line bought more than `radar.price_variance_percent` (5%) above the item's **current** standard cost; accepted quantity × the difference | actual; formula `material_cost`. Items with no standard cost are left out |

A detector only *names* records. Each record is described by `LossSources` and valued by `LossCalculationService::calculate()` — the Radar works out
no formula of its own, so a finding's amount is exactly what a person would get by reporting the same record.

## Rules in force

1. **One finding per detector and source record** (`unique(company, detector, source_type, source_id)`); a repeat sweep refreshes it. An overlapping sweep that loses the race is skipped.
2. A record that already has a loss incident (whatever its state) is never proposed; an open finding whose record then gets an incident becomes *reported*.
3. An open finding not seen by a later sweep (stock used up, scrap aged out of the look-back, amount now below the minimum) becomes *no longer applies*; if the condition returns it reopens.
4. **Detections only propose; nothing is created automatically** (owner decision, 2026-10-22).
5. Company settings (Settings → Loss Intelligence): `radar.enabled` (on), `radar.min_amount` (₹1,000), `radar.lookback_days` (30), `radar.price_variance_percent` (5).
6. Permissions: `radar_finding.view` / `.edit` (Quality/Loss Manager, Company Admin, Super Admin); reporting needs `loss_incident.create`. Branch scope applies.

## Open for the owner

- Thresholds are examples; the real ones for the pilot (plastics / packaging) are needed.
- A **new finding graded high** tells everyone who holds `radar_finding.view` once (in-app and e-mail, `LossNotifier`); a refresh never tells again, and a failed notice is logged, not thrown.
- Price variance compares with the standard cost as it is **today**, not on the receipt date (`item_cost_history` could refine this); rejected production output (yield loss) is not a finding yet.
- More detectors need data that is not yet wired: margin erosion, repeat breakdowns, downtime, stock-outs.
- Sales still post nothing to stock, so stock-out and shrinkage against sales cannot be detected (open assumption 5).

## Programme this belongs to (owner answers, 2026-10-22)

| Question | Answer |
|---|---|
| Pilot industry | Plastics / packaging (real thresholds and workflows still to come) |
| Finance depth | **Integrate with Tally / another package** instead of building a ledger — the interface, mapping and what is read (balances, bills, receipts) still to be agreed |
| AI provider | **Self-hosted / other provider**, behind a provider-independent interface; the provider and data policy are still to be named; disabled until configured |
| Radar | Proposes drafts only; Radar first |

Next steps of the plan: verified-savings ledger (baseline, normalisation, finance approval, recurrence), then the Tally integration and cash-flow
forecast, then the governed AI copilot.
