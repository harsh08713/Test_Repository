# Rates

What one unit of an item costs at one branch, for a period. Screens: *Masters → Rates* and *Masters → Price lists*. Tables:
[schema.md](schema.md). The approval engine underneath: [approvals.md](approvals.md). Rates are **exclusive of GST**: the item
carries the GST rate and quotations (Phase 11) add the tax.

```
Accounts enters ──submit──▶ awaiting approval ──approve──▶ approved ──(end on a date)──▶ stops applying
      ▲                          │                           │
      └──────── reject ◀─────────┘             re-open (reason) ──▶ back to draft: no longer offered
```

## What a rate is

| Field | Rule |
|---|---|
| **Item** | an approved, active item of the company |
| **Unit** | the item's base unit or one of its conversions. A rate per Box and a rate per Nos are **separate rates**; no conversion is ever guessed |
| **Branch** | one of the branches the person works in, active. There is no "all branches" rate: a rate belongs to one branch |
| **Price list** | optional. Blank is the **standard** rate. A price list ("Dealer", "Project") is a plain master with its own rates |
| **Rate** | more than zero, up to 4 decimals (`0.0750` is fine), stored as exact decimal, never a float |
| **Effective from / to** | inclusive at **both** ends; an empty *to* means "until further notice"; years 2000–2100 |

## Overlaps

Two **pending or approved** rates for the same item, branch, unit and price list may not overlap — even by one day, and even
when one of them is switched off (so switching it back on can never create an overlap). Drafts and rejected rates are not live
and never conflict. This is checked

* when a rate is saved — the message names the rate it clashes with (`from 1 Apr 2026 (approved)`), and
* again when it is submitted, **under a lock on the item**, so two people cannot both slip past the check.
  `tests/Concurrency/RateOverlapConcurrencyTest.php` proves it with real processes submitting overlapping rates at the same
  instant (exactly one gets through) and fails if the lock is removed.

Adjacent periods are fine: one ending 30 Sep and the next starting 1 Oct.

**Changing a price from a future date** therefore takes two steps, on purpose: *end* the old rate on the day before
(*Rates → open it → End on a date…*), then add the new rate starting the day after and have it approved. If the new rate is
not approved in time there is a visible gap — `RateService` says "ended on …" — never a silent fallback to the old price.

**End on a date** shortens an approved rate (or gives an open-ended one an end). It needs the edit right but no new approval,
because it can only reduce what was approved; it needs a reason, may not be in the past, and writes one audit event with the
old and new date and the reason. To change the price itself, *re-open* the rate (reason required, audited): it goes back to
draft and is **not offered** to quotations until approved again — which leaves a gap, so prefer ending it and adding a new one.

Once a rate has been approved, its **item, branch, unit and price list are fixed**; only the price and dates can be corrected
(through a re-open). *Copy as new rate* pre-fills a new draft from an existing one, without its dates.

## `RateService::getRate(item, location, uom, date, priceList = null, fallbackToStandard = true)`

The one way a transaction finds a price. It returns a `RateQuote` — rate id, the rate as a 4-decimal string, its period, the
date asked for and `source` (`standard` or `price_list`) — only if **all** of these hold:

* the rate is **approved**, **active**, not deleted, and the date is inside its period (both ends included);
* the item is approved and active, and sold in that unit; the unit and branch are active;
* the branch is one the caller works in and everything belongs to the caller's company — whatever ids were sent.

Otherwise it throws `RateNotFoundException` with a `RateFailure` reason and a message a person can act on. It never contains an
amount, so it cannot leak a price the caller may not use:

| Reason | Meaning |
|---|---|
| `item_not_available` / `location_not_available` / `unit_not_allowed` / `price_list_not_available` | that input cannot be used (not approved, switched off, another company's, not the item's unit, a branch you do not work in) |
| `not_yet_effective` | nothing in force on that date; the next approved rate starts on … |
| `expired` | nothing in force; the latest approved rate ended on … |
| `inactive` | the rate that covers the date is switched off |
| `not_approved` | a rate has been entered but is not approved (draft, waiting or rejected) |
| `no_rate` | nothing at all for that item, branch, unit and price list |

**Which rate wins**

1. The requested price list's own rate. If it has none that day and `fallbackToStandard` is true, the **standard** rate is used
   and the quote says `source: standard`. With no price list requested only the standard rate is considered; a price list's
   rate is never used as the standard one.
2. Overlaps cannot exist among live rates, so at most one rate qualifies. If data ever holds two anyway (a data fix written
   straight to the table), the tie is broken **the same way every time**: the later `effective_from`, then the more recently
   approved, then the higher id. It never picks arbitrarily.
3. Dates are `YYYY-MM-DD`, a `Carbon`, or today. Anything else — `12/10/2026`, `2026-02-30` — is an `InvalidArgumentException`,
   not a lookup of some other day.

`GET /masters/rates/lookup?item=1&location=2&unit=3&date=2026-10-12&price_list=4` serves the same as JSON
(`{"data": {…}}`, or `422 {"message": "…", "reason": "…"}`); the company is never a parameter. It needs `rate.view` and is
throttled to 60 a minute.

## Overriding a rate on a quotation or order

`RateService::recordOverride($quote, $newRate, $reason, $user, $document)` is what the quotation and order screens (Phases 11–12)
call when a person prices a line differently from the master. It needs the dedicated **`rate.override`** permission (Sales
Manager and administrators; Accounts does not hold it), a reason of at least three characters and a rate above zero that differs
from the master. It writes an audit event on the rate holding the **original and the overridden value, the reason, the date and
the document**; it does not change the rate. Quotations and orders do not exist yet, so nothing calls it so far — it is tested
through the service.

## Who may do what

| | View | Create / edit drafts | Approve | End early, switch on/off, re-open | Override on a document |
|---|---|---|---|---|---|
| Accounts User | ✓ | ✓ | ✓ (not their own) | ✓ | |
| Approver | ✓ | | ✓ | | |
| Company Admin, Super Admin | ✓ | ✓ | ✓ (not their own) | ✓ | ✓ |
| Sales Manager | ✓ | | | | ✓ |
| Purchase, Inventory, Operations, BDE | ✓ | | | | |
| Tele-caller | | | | | |

Maker–checker applies: nobody approves a rate they created or submitted. The Approver is the usual second person for Accounts'
rates. Price lists: Accounts maintains them; the roles that see rates can look.

## Using rates from other modules

Never query `Rate` directly for a price. Ask `RateService::getRate()` (or the lookup route) and keep the quote's `rateId`
with the document line, so an override can be traced to the rate it changed.

## Known limits

* **No "all branches" rate** and no bulk entry or import: each branch's rate is its own row (the `rate.import` / `rate.export`
  permissions exist for when they arrive).
* A customer is not linked to a price list yet; the caller of `getRate()` chooses it. Where a customer gets theirs
  (a ledger field, a customer category) is decided with the quotation in Phase 11.
* Rates are in the company's single currency, exclusive of GST; there is no tax-inclusive flag.
* Correcting an approved price means a re-open, with a gap in pricing until it is approved again; there is no "pending
  revision" that keeps the approved version in force meanwhile (see the owner question in [roadmap.md](roadmap.md)).
* `getRate()` does not convert units: a Box rate is entered as a Box rate.
