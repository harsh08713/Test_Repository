# Loss management (Loss Intelligence L4)

Code: `app/Modules/Loss`. The decisions and phase plan: [loss-intelligence.md](loss-intelligence.md). Formulas: [FORMULAS.md](FORMULAS.md) §3–4.
Industry templates (which supply categories, extra fields and the workflow): `app/Modules/Industry`.

## Reporting a loss

*Report a loss* (`/losses/report`, `loss_incident.create`): under an industry template the company has **switched on**, at the template version the company is
pinned to — the incident keeps that version, so its fields and workflow do not change under it. A loss category **of that version**; the template's extra
fields, **validated on the server** (a required one missing, a value of the wrong kind or a field the template does not have are refused); a branch the person
works in; when it happened (not in the future); severity; whether it is a safety, quality or compliance risk; optionally the item, batch, quantity, asset,
process and department. Numbered `LI/<branch>/<FY>/00001`. A first figure can be added in the same step.

**From a record.** A loss can be reported straight from: stock written off (scrap / adjustment out, on the stock ledger), an **expired batch** with stock, goods
**rejected** on an approved receipt, an **approved sales return**. The form is filled in from the record and a first figure suggested (its cost, at the cost the
stock left at). The record must be one the person can see. If an incident already covers the same record, the new one is kept but **linked to it as a
duplicate**, and its money is not counted.

**Escalation.** A safety, quality or compliance risk, or a **critical** one, tells everyone in the company holding `loss_incident.approve` at once (in-app and
e-mail), **once** (claimed with `escalated_at`). The incident page warns until containment is recorded.

## The workflow

The template's own workflow, or the default one: Draft → Submitted → Triage → Investigation → Action required → Verification → Closed, with Rejected, On hold and
Reopened. A move must be one of the workflow's transitions from where the incident is; the person must hold the permission the transition names; some need a
comment; **closing needs approval by someone other than the reporter** (`loss_incident.approve`, maker–checker). Conditions that keep the record honest:

| To | Needs |
|---|---|
| Investigation | an investigator named |
| Action required | a root-cause analysis recorded |
| Verification | at least one corrective/preventive action, none still open or in progress |
| Closed | every action verified or rejected, every current figure validated or rejected |

Every move is in `workflow_events` (append-only) and the audit log. A closed or rejected incident cannot be changed and takes no new actions until it is
**re-opened** (with a reason).

## The money — four values, kept apart

Each figure is an **impact line** of one kind: **actual loss**, **estimated exposure** or **recoverable opportunity**, worked out by a named formula (material cost,
scrap net of salvage, expiry, downtime, rework, warranty, markdown, shrinkage, billing leakage, energy excess, SLA penalty, or entered by hand) whose key, version
and inputs are kept with it. An opportunity must state its assumptions, confidence and target period; a hand-entered figure its assumptions. A figure below zero is
refused. The fourth value, **verified savings**, comes from corrective actions.

* A figure never changes: **recalculating** marks it *superseded* (pointing at its replacement) and adds a new one, both amounts audited.
* A figure is **validated or rejected by someone other than the person who entered it** (`loss_incident.approve`). Only validated actual figures are actual loss.
* The four values are **never added together** — on the list, the incident page or anywhere else. `LossFigures` works them out (and the Pareto by category):
  duplicates, rejected incidents, superseded and rejected figures count for nothing; lines are summed by incident id, never through a join that could repeat them.

## Root cause

**5 Whys** (one to five whys and the root cause) or **fishbone** (a cause category — people, machine, method, material, measurement, environment, other — contributing
factors, the root cause), with findings. Evidence goes on the incident as comments/attachments.

## Corrective and preventive actions (CAPA)

Numbered `CA/<branch>/<FY>/00001`. An owner (who can work actions in this company), an optional named verifier, a due date, the expected saving and baseline.
open → in progress → done (with what was done) → **verified** (with the verified saving, 0 if none) — or sent back, or rejected, with a reason. The **owner** works it;
someone holding `corrective_action.approve` who is **not its owner** (and is the named verifier, if any) verifies it. The saving counts only once verified, in the
period it was verified. **Overdue** is an open or in-progress action past its due date: `loss:escalate` (hourly) tells its owner and verifier **once**.

## Roles (default grants)

| Role | Loss incidents | Actions |
|---|---|---|
| Quality/Loss Manager | everything: report, work, assign, validate figures, approve moves, export, audit | everything, including verify |
| Investigator | view, report, work (figures, containment, root cause), comment | view, create, work |
| CAPA Owner | view, comment | view, comment — and works the actions they own |
| Inventory / Operations User | view, report, comment | — |
| Purchase User | view, report | — |
| Accounts User | view, export | view |

Company Admin and Super Admin hold everything. Everything is limited to the company and the branches the person works in.

## Known gaps / for the owner

* **Regulated industries**: the pharma, food and healthcare templates carry fields and history only. Nothing here makes a site GMP, FSSAI, NABH or 21 CFR Part 11
  compliant; that needs a qualified review.
* No attachments of their own yet (evidence goes in comments); no "on hold" timer; containment is a note, not a checklist.
* Loss figures are in the company's currency only (INR); no conversion.
* A loss is not raised **automatically** from scrap, expiry or rejection — a person reports it from the record (one click). Say if some should be automatic.
* The scheduled escalation covers overdue actions; an incident left untriaged is not escalated on a timer (the SLA module could time it — Phase 14).
