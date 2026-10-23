# Quotations (Phase 11)

A quotation is the offer made to a customer for the items of a deal (or of an approved inquiry): priced from the rate master, GST worked
out by place of supply, valid until a date, **approved inside the company before it is sent**, then sent as a PDF, answered by the
customer, and revised — never edited — if the offer changes. Everything goes through `QuotationService`; the screens and the read-only
API share it.

```
approved deal ──▶ Draft ──▶ (approval) ──▶ Sent ──▶ Accepted ──▶ (Phase 12: sales order)
approved inquiry ─┘   Rev 0                  │  ├──▶ Declined / Expired ──▶ Rev 1 (new draft) …
                                              │  └──▶ Superseded (when Rev 1 is made) / Cancelled (withdrawn)
```

## What a quotation is

* **One row per revision.** `QT/AHD/26-27/00001` is the quotation; *Rev 0*, *Rev 1*… are its revisions, each a row of `quotations` with
  the same number (`unique(company, number, revision)`), a `previous_id`, its own lines, its own approval and its own state.
* **Made from** an **approved, open deal** (the usual way) or **directly from an approved, open inquiry** from an existing customer that
  has not been converted (an inquiry that has a deal is quoted through the deal). A new lead has to become a deal first. A deal needs
  **no quotation at all**: an approved deal can be ordered without one (`Deal::isReadyForOrder()`, used by Phase 12).
* **Owned by the lead's owner** — `QuotationAccess` asks who owns the lead the quotation comes from, so a quotation follows a hand-over of
  its lead or deal. The owner, the people who manage them and holders of `sales.scope_all` work it; an approver may *open* one waiting
  for their decision (or one they decided). Another company's or another branch's quotation is invisible.
* **Snapshots.** The customer's name, contact, billing address and GSTIN, the place of supply and the supplier's state, the terms, the
  rate and tax of every line and every total are **copied onto the revision** when the draft is saved. The masters can change; the
  document does not.
* Never deleted: a quotation that will not happen is **withdrawn** (cancelled) with a reason.

## Prices and tax

* **Prices come only from `RateService::getRate()`**: approved + active + in-period, for the line's item, unit and branch, in the
  quotation's **price list** (falling back to the standard rate — the line says which it used: `rate_source`). A line with no rate on the
  master cannot be quoted, and the message says why (*"There is no rate for Nail 2 inch per Nos at AHD Branch on 12 Oct 2026. Ask
  Accounts to add one."*); nothing is saved and no number is used up.
* **Where the customer gets a price list** (open since Phase 5): chosen **per quotation** (default: standard rates). Customers are not
  linked to a price list on the ledger yet; that stays a possible later change.
* A line that is unchanged **keeps the price it was saved with** when the master moves on; a new or changed line, a new price list, or
  *"Refresh every line to today's rates"* looks the rate up again (and moves the price date to today).
* **Changing a rate** needs `rate.override`, a reason, and leaves an audit entry on the *rate* (old and new value, reason, the quotation).
  Typing the master's own rate is not an override.
* **Tax** (`TaxService`, pure bcmath): the supply is **intra-state** when the supplier's GST state code (the branch's, else the company's)
  equals the place of supply's — CGST and SGST, half the item's GST rate each — and **inter-state** otherwise — IGST. Per line:
  gross = quantity × rate, discount = gross × discount %, taxable = gross − discount, tax = taxable × GST rate, every figure rounded
  **half up to paise** where it is worked out (CGST and SGST are always equal). The document adds the lines' own figures and rounds the
  total to the **nearest rupee**, showing the round-off. An item with no GST rate on the master is refused rather than quoted tax-free.

## The life of a revision

| State | Meaning | What can still happen |
|---|---|---|
| **Draft** | being made; `approval_status` draft/pending/approved/rejected | edit (until it is submitted), submit, approve, send, withdraw |
| **Sent** | out with the customer (e-mail, or recorded as handed over) | accepted, declined, expired by the scheduler, revised, withdrawn |
| **Accepted** | the customer said yes (a note, e.g. their PO number) | ordered (Phase 12), withdrawn |
| **Declined** | the customer said no (a reason is required) | revised |
| **Expired** | sent and not answered by the end of its valid-until day | revised |
| **Superseded** | a newer revision replaced it | nothing — it is kept exactly as the customer saw it |
| **Cancelled** | withdrawn (a reason is required) | nothing |

* **Only a draft changes.** `Quotation` and `QuotationItem` refuse (a `LogicException`) any change to the content of a revision that has
  left draft, and any delete, even from code that goes round the service; only its state, and the dates and notes that go with the state,
  move on. A change of mind is a **new revision**.
* **Approval before sending.** Submit → approve through the shared approval workflow (maker–checker, configured levels). The fingerprint
  covers the customer, the address, the place of supply, the terms, the validity and every line with its price, discount and tax. An
  approved draft is locked; *Re-open for editing* (a reason, audited) takes it back to a draft that must be approved again. A draft
  whose valid-until date has passed cannot be submitted.
* **Sending** (`send`): only an approved draft that is the latest revision, still valid, whose deal (if any) is still open and approved.
  The revision is **claimed first** (draft → sent, in one locked step, so two people cannot both send it), then the e-mail goes out; if the
  mail transport refuses it the revision goes **back to draft**, the failure is on the dispatch log and the person is told. The e-mail
  carries the PDF, the sender as Reply-To and an optional message. *"I have handed it over myself"* records a manual dispatch.
  Every attempt — sent or failed, with a message that never carries a credential — is in `quotation_dispatches` (append-only).
* **Revising** (`revise`): from a sent, declined or expired revision (not a draft — change it; not an accepted one). A new draft with
  the next revision number, copying the customer, terms, lines and prices exactly; the old one becomes *superseded*. The number series
  is not touched. Two people revising at once get one new revision (the row lock, and `unique(company, number, revision)`).
  A revision of an expired quotation starts with a fresh validity.
* **The deal follows.** When a quotation is sent the **deal moves to Quotation** (70%) — an inquiry quoted directly moves from Qualified to
  Quotation — and the lead with it. When **no quotation of it is left standing** (every one declined, expired, withdrawn or being
  revised) the deal goes back to **Negotiation**. Accepting does not win the deal: *Won* is set by the sales order (Phase 12).
* **A follow-up is planned** on the lead for its owner (`quotation.followup_days` days after sending, 11:00, a call) to ask what the
  customer thinks.
* **Validity** (`sales:quotation-expiry`, daily at 06:00): a sent quotation not answered by the end of its valid-until day becomes
  *Expired*, the deal falls back, and the owner is told once. A sent quotation within `quotation.expiry_warning_days` of running out
  warns its owner once (claimed with `WHERE expiry_warned_at IS NULL`, so overlapping runs never tell anybody twice).

## Ordering from a quotation

`QuotationConversionGuard::assertConvertible($quotation, $actor, $exceptionReason)` is what a sales order asks before it is made *from a
quotation* (`SalesOrderService::createFromQuotation`, [sales-orders.md](sales-orders.md)). **Hard "no", never waived:** not the latest revision, a draft, declined, withdrawn or superseded, not approved, or its deal
is no longer open. **Soft "no", waivable:** the customer has not accepted it (it is only sent), or it ran past its valid-until date.
Someone holding **`quotation.convert_exception`** may go ahead with a **reason**; the exception is audited on the quotation
(`conversion_exception`) and the order carries the note. A sales order can also be made from an approved deal with no quotation.

## Screens and API

* `Sales ▸ Quotations` (`/sales/quotations`): live / per-state tabs with counts, search, approval, validity and branch filters, and what is
  out with customers. *Make a quotation* is on an approved deal's page, *Quote directly* on an approved inquiry's page; each lists its
  quotations. A quotation page shows the figures with CGST/SGST or IGST, the approval panel, revisions, the dispatch log, the status
  history and the audit trail, with the actions the person may take. `GET /sales/quotations/{id}/pdf` downloads the PDF (whoever may
  open the quotation).
* Read-only API: `GET /api/v1/quotations` and `/quotations/{id}` ([api.md](api.md)). Making, approving, sending and revising are
  screens-only for now.

## Settings (Company settings ▸ Sales)

| Key | Default | What it does |
|---|---|---|
| `quotation.validity_days` | 30 | the valid-until a new quotation starts with |
| `quotation.followup_days` | 3 | days after sending that a follow-up call is planned (0 = none) |
| `quotation.expiry_warning_days` | 2 | days before expiry that the owner is warned (0 = off) |
| `quotation.default_terms` | a short standard text | what "Terms and conditions" starts with |

E-mail goes through the installation's own mail transport (`MAIL_MAILER`, `MAIL_FROM_*` in `.env`): nothing is configured in code.
Locally `MAIL_MAILER=log` writes the message to the log instead of sending it.

## Permissions

`quotation.{view,create,edit,delete,approve,export,import,comment,assign,view_audit}` from the matrix (BDE: view/create/edit; Sales
Manager: all but audit; Approver: view/approve/comment), plus the special **`quotation.convert_exception`** (Sales Manager and
administrators) and the existing **`rate.override`**. Super Admin holds them all explicitly; there is no bypass.

## Known gaps and decisions to confirm

* **PDF is plain**: one A4 layout, no company logo or letterhead, no amount in words, no per-item images. Fonts are DejaVu Sans (it carries
  the ₹ sign). A branded template needs the owner's artwork.
* **E-mail only, no WhatsApp/SMS** (Phase 16). The mail goes through whatever transport is configured; delivery to the customer's inbox
  is whatever that provider does — "sent" means *accepted by the mail server*.
* **Rate overrides are per quotation line**; there is no approval threshold for a discount yet (everything is approved as a whole).
* **A customer's price list is chosen on the quotation**, not stored on the ledger.
* **A lost deal does not withdraw its quotations**: a draft or sent quotation of a deal that was lost can no longer be sent or changed,
  and the scheduler expires it when its time comes; withdraw it by hand to tidy up.
* **The deal's stage flips back to Negotiation while a revision is being made** (no quotation is standing in between) and returns to
  Quotation when the revision is sent; each move is in the deal's history.
* **No quotation attachments** (documents are Phase 19) and no PDF download over the API.
