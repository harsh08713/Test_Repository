# Inquiries from existing customers, and deals (Phase 10)

*CRM → Leads, Deals.* Two things happen between "somebody might buy" and "an order": a customer who is already in the ledger
master **asks** for something (an *inquiry*), and a qualified lead is taken up as a piece of **business** (a *deal*). Both list
the items wanted — with the unit, the quantity and the rate expected — and both go through approval before a quotation or an
order can be made from them (Phases 11–12).

```
New lead ──▶ Contacted ──▶ Qualified ──▶ Deal ──▶ Quotation ──▶ Won      (a lead's stage follows its deal)
Existing customer's inquiry ──▶ (the same stages)                         Lost, from any open point
```

## An inquiry from an existing customer

It is a **lead of a second kind** (`lead_type = existing`), so everything about leads — stages, follow-ups with outcomes such as
*Store Visited*, the calendar, hand-overs, who sees what, the API — works for it unchanged. What differs:

| | New lead | Inquiry from an existing customer |
|---|---|---|
| Who it is | typed in: name, contact, mobile, address | an **approved, active customer ledger**, chosen when the inquiry is entered and **never changed after**; its name, contact, mobile, e-mail and billing address are copied from the ledger *as they were then* (whatever is typed for them is ignored) |
| Duplicate warning, mobile number | yes; a number is needed | no — a customer may ask again and again; the ledger may have no mobile number |
| Items | an item from the master, or words; a rough quantity; never priced | **an approved item from the master** for every line, a unit it is sold in, a quantity (whole numbers for a unit that cannot be split) and the **rate expected** |
| Expected value | typed | the sum of the lines (quantity × rate, rounded half up to paise) — worked out by the server |
| Approval | none (it is simply worked) | **goes through the approval workflow** (`lead.approve`); every line needs a quantity and a rate before it can be sent |

**Approval locks what was signed off** — the customer, the source and the lines — while it waits and once it is approved; the stage,
the owner and the follow-ups keep moving. A rejected inquiry is corrected and sent again; an approved one is **re-opened** with a
reason (back to draft, audited, unusable until approved again) — the same rule ledgers, items and rates follow. A *new* lead is never
sent for approval.

An approved, still-open inquiry is what a quotation can later be made from **directly**, without a deal in between
(`Lead::isReadyForQuotation()`, used by Phase 11). Converting it to a deal does not wait for that approval: the deal has its own.

Who may enter one: `lead.create` **and** `ledger.view` (the customers' names belong to the ledger). Pages: *Leads → Add an inquiry
from a customer*; the lead list tells them apart (*Customer* badge, a *kind* filter); the lead page shows the customer, the
rates and the approval panel.

## Converting a lead to a deal

*Lead page → Convert to a deal* (or `POST /api/v1/leads/{id}/convert`). `DealService::convert()` is the only way a deal is made.

* The lead must be **Qualified**, **have an owner** (a pool lead is handed to someone first), be one the person **works** (their
  own, their team's, everyone's with `sales.scope_all`) in a **branch they work in** — all checked on the server against the *actor*.
* **Once.** `deals.lead_id` is unique, the lead's row is locked while it converts, and the check for an existing deal is a locking
  read; two people converting the same lead at the same moment make one deal and the other is told which. (Proven with separate
  processes in `tests/Concurrency/DealConcurrencyTest.php`: remove the unique key *and* the lock and five deals appear.)
* **Every line of the lead comes across**, priced. A line may be adjusted (quantity, unit, rate) and more lines may be added, but a
  line of the lead cannot be dropped and its item cannot be swapped for another — to leave something out, edit the lead first.
  A line the lead only described in words needs its item chosen. Each deal line keeps the link to the lead's line it came from.
* The deal takes the lead's **branch, owner and team**, a snapshot of who the lead was, and — for an inquiry — the inquiry's
  customer; for a new lead a customer ledger (approved, active) may be linked now or later.
* The **lead moves to the Deal stage** (written to its stage history) and can no longer be edited, transferred or deleted. If the deal
  is lost the lead is lost with it; re-opening the deal brings the lead back. A lead cannot be re-opened on its own while its deal
  is lost.

## A deal

| Field | Notes |
|---|---|
| Deal number | `DL/{branch}/{financial year}/{sequence}` from the branch's own series, taken in the same transaction that saves the deal |
| Party, contact, mobile, e-mail | a snapshot of the lead when it was converted |
| Customer | the ledger, when there is one (always for an inquiry) |
| Lines | item, unit, quantity, expected rate, **amount = quantity × rate** (half up to paise), kept in order with a line number |
| Expected value | the sum of the amounts — **never taken from the client** |
| Expected close date, probability, remarks | close date: not in the past, within five years; probability **1–99** while open (0 when lost, 100 when won); the value weighted by probability is shown for forecasting |
| Stage | below |
| Approval | draft → pending → approved / rejected, through the shared engine (`deal.approve`) |

**Stages.** *Discovery* (starts at 10%) ⇄ *Negotiation* (50%) — chosen by a person; *Lost* — chosen with a **reason**, which also marks
the lead lost and calls off what was planned for it; *Quotation* (70%) and *Won* (100%) — set **only by the documents that follow**
(`DealService::setStageBySystem()`, used by Phases 11–12) and **only for an approved deal**. Bringing a lost deal back needs the
dedicated permission **`deal.reopen`** and a comment. A probability the salesperson has set is left alone when the stage changes;
one that was following the stage follows it. Every move is in the **stage history**, which is append-only.

**What is locked when.** The customer and the lines can change only while the deal is a draft or was returned (and no quotation or
order has been made from it); once it is pending or approved they are locked for everyone, Super Admin included. An approved deal is
**re-opened** with a reason to correct them. The close date, probability and remarks — *where the deal stands* — can be updated at
any time while it is open, approved or not, and are not part of what the approver signs off.

**Handing it over** (`deal.assign`, the owner's manager or `sales.scope_all`): the deal and its lead change hands together through
`LeadTransferService` — same checks, same history, same notice to the new owner, what is planned for the lead goes with it. The lead
of a deal cannot be handed over on its own.

**A deal is never deleted** (its number and its source lead stay accounted for): one that will not happen is marked lost.

**Follow-ups** are planned and recorded on the **lead**, which stays open while its deal is; the deal page lists them and links to
the lead. (Follow-ups on the deal itself arrive with tasks in Phase 14.) The deal page also shows the approval panel, comments and —
for those who hold `deal.view_audit` — the audit trail.

## Who sees and does what

`DealAccess` answers "which deals" the way `LeadAccess` does for leads — the owner, the people who manage them, `sales.scope_all`
for everyone — and `DealPolicy` adds what the deal's state allows. An **approver** (anyone holding `deal.approve` / `lead.approve`)
may open an inquiry or deal that is *waiting for approval*, and afterwards one they decided (`ApproverReach`); that is reading only.

| Role (default grants) | Deals |
|---|---|
| BDE / Field Salesperson | view, convert, edit and send for approval **their own**; no hand-over, no re-opening a lost deal |
| Sales Manager | their teams' deals; approve (not their own submissions); hand over; re-open a lost deal (`deal.reopen`) |
| Company Admin, Super Admin | everyone's; the audit trail |
| Approver | the deals and inquiries waiting for them; approve and comment |
| Tele-caller, Accounts, Purchase, Inventory, Operations | no access to deals |

## The mobile API

`GET /deals`, `GET /deals/{id}`, `PATCH /deals/{id}`, `POST /deals/{id}/stage`, `/submit`, `/reassign`, `POST /leads/{id}/convert`,
`GET /lookups/customers`, and `customer_ledger_id` / `expected_rate` on leads. The same services, the same rules; what the state forbids
is a **409** with the reason. See [api.md](api.md) and [api/openapi.yaml](api/openapi.yaml). Deciding an approval is done on the web.

## What is not here (decisions to confirm)

* **What an inquiry's approval gates.** The brief says an inquiry "once approved, may convert to a Quotation". It is built as a
  gate on making a quotation **directly** from it (Phase 11); converting it to a deal — which has its own approval — does not wait for it.
* **No deal-level follow-ups, tasks or attachments** yet (Phases 14 and 19). Quotations are made from an approved deal ([quotations.md](quotations.md)).
* **The expected rate is typed**, not looked up: it is the customer's or salesperson's expectation, not a price. Prices on quotations
  and orders come only from `RateService` (quotations do this since Phase 11).
* **No notice when a manager converts someone's lead** — the owner sees the deal on their list.
* **Deal stages are fixed** (Discovery, Negotiation, Quotation, Won, Lost), not company-configurable.
