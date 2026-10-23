# Ledgers

One central `ledgers` table holds customers, suppliers, banks, cash, expense and general accounts. It is a **master**
(who you deal with, and where they sit in the chart of accounts); it posts nothing and is not a double-entry ledger.
Screens: *Masters → Ledgers* and *Masters → Payment methods*. Tables and relationships: [schema.md](schema.md).

## What each type holds

| | Customer | Supplier | Bank | Cash | Expense | General |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Account code, name, legal name, group, status, e-mail, phone, PAN, GSTIN | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| B2B / B2C | ✓ | | | | | |
| Credit days | ✓ | ✓ | | | | |
| Credit limit | ✓ | | | | | |
| Lead time (days) | | ✓ | | | | |
| Payment preferences | ✓ | ✓ | | | | |
| Addresses (billing / shipping) and contacts | ✓ | ✓ | optional | optional | optional | optional |
| Bank account | optional | optional | **required** | – | – | optional |

Fields that do not belong to a type are dropped on save, not stored. The **account code** is typed in or generated
(`CUS-00001`, `SUP-`, `BNK-`, `CSH-`, `EXP-`, `GEN-`); codes are never reused, even after a ledger is deleted.

Key rules (all enforced by `LedgerService`, which the form, the demo seeder and any future import share):

* **GSTIN** is required for a B2B customer, its check character is verified, it must be unique within the ledger's type,
  its PAN must agree with the ledger's PAN, and it must belong to the state of the default billing address.
  An address's own GSTIN must belong to that address's state.
* A name is unique within a **type** (a party that is both customer and supplier has two ledgers).
* The ledger must be filed under the right part of the chart of accounts for its type (see [schema.md](schema.md)).
* One default billing and one default shipping address; a lone address becomes the default. Contacts need a name and a
  way to be reached; one is primary. At most 25 of each.
* Payment preferences are codes from *Payment methods* (CASH, CHEQUE, NEFT, RTGS, IMPS, UPI and whatever the company adds).

## Who can do what

| | Purchase | Accounts | Approver | Operations / Sales | Tele-caller | Company Admin |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| See ledgers | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| Create / edit **suppliers** | ✓ | ✓ | – | – | – | ✓ |
| Create / edit **other types** | – | ✓ | – | – | – | ✓ |
| Approve | – | ✓ | ✓ | – | – | ✓ |
| See the full bank account number | – | ✓ | – | – | – | ✓ |
| Delete (drafts only) | – | – | – | – | – | ✓ |

Suppliers are onboarded by Purchase: `ledger.create` / `ledger.edit` is enough for a supplier. Every other type also needs
the special permission **`ledger.manage_all_types`**. **`ledger.view_sensitive`** shows the full bank account number
(everyone else sees `XXXXXX1234`). The matrix is editable at *Administration → Roles & permissions*.

## Approval

A ledger starts as a **draft** and is submitted from its page. Before it can be submitted a customer or supplier needs a
billing address and a contact, a B2B customer needs its GSTIN, and a bank ledger needs its account.
Approval follows [approvals.md](approvals.md): maker–checker (the author cannot approve), levels configurable per company,
and any change after submission (to the ledger, an address, a contact or the bank account) blocks approval until the
owner resubmits.

* **Pending and approved ledgers are locked.** An approved ledger can be switched active/inactive (no re-approval), or
  **re-opened for editing**: a reason is required, it goes back to draft, drops out of every picker until approved again,
  and the step is audited. A ledger that has ever been approved cannot be deleted, only deactivated.
* **Only approved, active ledgers can be used.** Transactions and APIs must go through `LedgerLookup`
  (`search()`, `usable($id)`), never query ledgers directly:
  `GET /masters/ledgers/lookup?type=customer&q=shree&limit=20` returns id, code, name, type, GSTIN and credit terms of
  approved, active ledgers of the user's company — never bank details; the company is never a parameter.

## Bank account data

The number is **encrypted at rest**, stored with its last four digits and a keyed hash (used for the approval fingerprint
and, later, duplicate checks), hidden from JSON, kept out of the audit log (a change is one event carrying only the
last four digits and the IFSC) and sent to the browser only for people holding `ledger.view_sensitive`. Someone without that
permission cannot see, add, change or remove the bank section: their form carries none and the service ignores it.

## Known limits

* One bank account per ledger (a supplier with several accounts needs a later extension).
* No export or import of ledgers yet (the `ledger.export` / `ledger.import` permissions exist for when they arrive).
* Transactions that use ledgers arrive in Phases 10–12; until then "in use" checks (deleting, re-opening) cannot look at them.
* The credit limit is stored and offered by the lookup; the check against it (`credit_limit.override`) arrives with Sales Orders.
