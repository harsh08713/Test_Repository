# Leads and follow-ups (Phase 7)

*CRM → Leads, Follow-ups, Calendar, Lead masters.* A **lead** is somebody who might buy: who they are, where the inquiry came
from, who is working it, how far it has got, and what happens next. A **follow-up** is one thing to do with a lead at a time —
a call, a visit, an e-mail, a WhatsApp message, a meeting — planned, then done with an outcome, or called off.

A **new** lead is not an approval document: it is worked, not signed off. An **inquiry from an existing customer** (Phase 10) is a
lead of a second kind that names an approved customer ledger and goes through approval (`lead.approve`) — see
[deals.md](deals.md), which also covers converting a qualified lead to a **deal**.

## A lead

| Field | Notes |
|---|---|
| Lead number | `LD/{branch}/{financial year}/{sequence}`, issued from the branch's own series in the transaction that saves the lead. Never reused, even after a delete. |
| Lead date | Today by default; not in the future. Chooses the financial year of the number. |
| Branch | One of the branches the person works in. Fixed once the lead is entered (it numbers the lead). |
| Source | From *Lead masters → Lead sources*: Reference, Website, Social Media, Tele-caller, IndiaMART, Just Dial, Field, Excel Import, Other, plus the company's own. |
| Name, contact person, mobile, other phone, e-mail | The **mobile** is stored as its ten digits (`+91`, a leading `0`, spaces and dashes are accepted when typing). It is required for a lead that is typed in or imported; a marketplace lead may arrive without one ([integrations.md](integrations.md)) and is then *incomplete*. |
| Address, city, state, district, block, PIN code | A PIN code that is in the geography master fills the state, the district and — when the PIN has only one — the block. A state or district that contradicts the PIN is refused. |
| Requirement, expected value, remarks | Free text; the value is rupees with at most two decimals. |
| Items of interest | Up to 30 rows: an item from the master (**approved and active only**, with a unit the item is sold in) or a description in words, and a rough quantity. Not a price and not an order line. |
| Owner, team | The person the lead is assigned to and the sales team they are on *at that moment* (kept on the lead, so a past month can be read as it was). |
| Stage | See below. |
| Next follow-up, last contacted | Derived: the earliest planned follow-up; the last time an outcome that *counts as contact* was recorded. |
| Entry channel | How it got in: typed in, spreadsheet import, field (mobile, Phase 8), IndiaMART / Just Dial ([integrations.md](integrations.md)), website form (Phase 16). Not the same thing as the source. |
| Complete? | `false` for a lead that arrived without a name or mobile number; it cannot be qualified until completed. |

### Duplicates: warned about, not blocked

The same mobile number on another **open** lead of the company — in any branch, whoever owns it — stops the save with a
message naming that lead (number, stage, owner, branch). The form shows the matches as soon as the number is typed. If it
really is a separate inquiry, tick *"This is a separate inquiry — save as a separate lead"* and save again. Leads that are
won or lost do not count: their number is free. The warning names a lead the person may not be able to open; it links to it
only when they can.

### Stages

```
New ⇄ Contacted ⇄ Qualified            people move a lead between these
  ↘        ↓         ↙
          Lost  ──(re-open, with a comment)──▶ New | Contacted | Qualified
Qualified ─▶ Deal ─▶ Quotation ─▶ Won      set only by a conversion, never by hand: Deal when the lead becomes a deal (Phase 10,
                                           deals.md); Quotation and Won follow the documents made from the deal (Phases 11–12)
```

- **Lost** needs a reason from *Lead masters → Lost reasons* (so reasons can be counted later); what was planned for the lead
  is called off. **Re-opening** needs a comment.
- The **first real contact** moves a *New* lead to *Contacted* by itself: recording a follow-up with an outcome that counts as
  contact is enough.
- A lead that has been converted (Deal and beyond) or lost is no longer edited; a lost one is re-opened first. A lead whose **deal
  was lost** is lost with it and comes back only when the deal is re-opened; a lead that has become a deal is handed over with its deal.
- Converting: *Lead page → Convert to a deal* for a qualified lead that has an owner. The lead keeps its follow-ups: they are planned
  and recorded on the lead, which stays open while its deal is.
- Every move is written to the **stage history** (from, to, who, when, comment, lost reason), which is append-only.

## Who sees and does what

Permissions say what a person may do with leads at all; **`LeadAccess`** says *which* leads.

| | May **work** it (edit, move between stages, hand over) | May **see** it (open it, follow it up) |
|---|---|---|
| A lead assigned to somebody | that person, the manager of a team they are on, holders of `sales.scope_all` | the same — **and anyone with a planned follow-up on it** |
| An unassigned lead (the pool) | whoever entered it, anyone who may hand leads out (`lead.assign`), holders of `sales.scope_all` | the same |

A tele-caller asked to ring somebody else's lead can therefore open it and record the call — for as long as their follow-up is
planned, and no longer. They cannot edit the lead, move its stage or hand it over. The company and branch boundaries come
first, as everywhere: a lead of another company is not found (404), nor one in a branch the person does not work in.

| Role (default grants) | Leads | Follow-ups |
|---|---|---|
| BDE / Field Salesperson, Tele-caller | view, enter, edit their own | view, plan, record their own |
| Sales Manager | + delete, **hand over** (`lead.assign`), **import**, export — within the teams they manage | + their teams' |
| Company Admin, Super Admin | everyone's (`sales.scope_all`), the lead masters (`crm_master.*`), the audit trail (`lead.view_audit`) | everyone's |
| Approver | the inquiries from customers waiting for their decision, and the ones they decided ([deals.md](deals.md)) | — |

Someone who may not hand leads out **always owns what they enter**. Someone who may can give a new lead to themselves, to a
person on a team they manage (who works in the lead's branch), or leave it in the pool.

## Follow-ups

- **Plan**: a time in the future (up to two years ahead), a type, and who is to do it — the lead's owner unless someone else is
  chosen; otherwise the planner or a person they manage, who must work in the lead's branch. Only an open lead gets new
  follow-ups.
- **Done**: needs an outcome from *Lead masters → Follow-up outcomes*. Two switches give an outcome its meaning:
  - *the person was actually reached* — stamps the lead as contacted now, and makes a *New* lead *Contacted*;
  - *needs a date for the next follow-up* (Call Back Later, Meeting Fixed) — the outcome is refused without one, and the next
    follow-up is planned in the same step, linked to this one, for the same person.
- **Log what was done**: something done without having been planned, recorded as done now by the person who did it.
- **Move** re-arms the reminder and the escalation; **call off** needs a reason and stays on record as cancelled. A follow-up
  that is done never changes, and no follow-up is ever deleted.
- A person works their own follow-ups, a manager their teams', `sales.scope_all` everyone's.

**Follow-ups** (the list) shows what is planned, overdue, due today and in the next seven days, with the numbers on the tabs;
**Calendar** shows planned and done follow-ups by day, week or month for one person, a team, or everyone the person may see.
Overdue is red, planned blue, done green. An entry opens its lead; a planned one can be dragged to another time (the server
decides, and puts it back if it says no). The calendar is [FullCalendar](https://fullcalendar.io) (the brief's choice), loaded
only on that page; all times are the application's (Asia/Kolkata), whatever the browser's time zone.

### Reminders and escalation

`php artisan crm:followup-reminders` runs from the scheduler **every five minutes** (it needs `schedule:run` in cron, or
`php artisan schedule:work` locally) and sends in-app and e-mail notices:

| | When | To | Company setting (CRM group) |
|---|---|---|---|
| Reminder | the follow-up is due within the lead time, or already late (but not more than a day late) | the person it is assigned to | `followups.remind_minutes_before`, default 30 |
| Escalation | the follow-up is still planned this long after it was due (but not more than 30 days) | the manager of the team that person is on | `followups.escalate_after_hours`, default 24; 0 = off |

Each is sent **once per follow-up**: the command claims a follow-up with a single conditional `UPDATE` before telling anybody,
so overlapping or repeated runs cannot remind twice (`tests/Concurrency` runs four at once). Moving a follow-up re-arms both.
Closed leads and deactivated people are skipped. This is a deliberately small escalation rule; SLA policies arrive in Phase 14.

## Handing leads over

One lead (from its page) or many at once (tick them in the list, up to 200). Every hand-over is checked on the server, lead by
lead, whatever the browser sent:

- the person must hold `lead.assign`, and each lead must be one they may *work* — a follow-up on somebody else's lead is not
  enough — in a branch they work in, and still open;
- the new owner must be an active user of the company, must be the person themselves or someone on a team they manage (anyone,
  with `sales.scope_all`), and must work in each lead's branch;
- a reason is required.

**All or nothing**: if one of the selected leads fails, none is moved and the message says which and why. Each lead moved gets
a row in its **ownership history** (from, to, their teams, reason, by whom, when — append-only), its planned follow-ups go to
the new owner with it, and the new owner is told once. Two hand-overs of the same lead at the same instant are serialised by a
row lock, so the history always reads as a chain.

## Importing leads from a spreadsheet

*Leads → Import from Excel* (`lead.import`). It is the shared import wizard ([imports.md](imports.md)) with three additions:

1. **The headings need not be the template's.** Common alternatives are recognised ("Company Name", "Mobile No", "Pincode",
   "Budget", "Notes"…), and after the check every column of the file is listed with the field it was read as: change any of
   them, or set it to *Ignore*, and check again. A file whose headings were not recognised at all can be mapped the same way.
2. **Choices for rows that do not say**: the branch, the source (Excel Import by default) and the owner (the uploader by
   default). A row can name its own branch (code), source (name or code) and owner (e-mail address).
3. **The uploader's reach is applied to every row**, again, when the file is checked and when it is imported — in the
   background, where nobody is signed in: only branches they work in, only people they may give leads to.

| Column | Required | Notes |
|---|---|---|
| Name | yes | |
| Mobile | yes | Ten digits; `+91` / `0` in front is fine. |
| Lead Date | no | `DD-MM-YYYY`, `YYYY-MM-DD`, `DD/MM/YYYY` or an Excel date cell. Blank = today. |
| Contact Person, Email, Address, City, Requirement, Expected Value, Remarks | no | |
| State, District, PIN Code | no | By name; the PIN fills what is blank and may not contradict what is given. |
| Source, Assigned To, Branch | no | Otherwise the choices made on the upload screen. |
| Next Follow-up | no | Plans a call for the owner (`DD-MM-YYYY`, optionally `HH:MM`; 10:00 otherwise). Must be in the future. |

- A row goes through **the same `LeadService` as the form**, so an imported lead obeys every rule a typed-in one does.
- **Duplicates**: a mobile number already on an open lead is *not imported again* — the row is shown as "Already a lead: LD/…"
  and skipped — which is what makes uploading the same file twice harmless. The same number twice in one file is a mistake in
  the file: the second row is rejected.
- **Valid rows are imported, invalid rows are reported** with their row number, the reason and the cells as uploaded; the
  error report downloads as a spreadsheet to fix and upload again. Nothing is hidden and nothing is saved before *Confirm*.
- Each owner is told once what the import gave them (not once per lead); the import is one audit entry.

## What is recorded

| What | Where |
|---|---|
| Every change to a lead's own fields, with old and new values and who made it | audit log (`leads`), shown on the lead's page to holders of `lead.view_audit` |
| Every move between stages | `lead_stage_history` (append-only) |
| Every change of owner | `lead_transfers` (append-only) |
| Every follow-up, done or called off | `followups` (never deleted) |
| Notes people leave | comments on the lead |
| An import | `import_batches` + one `import_completed` audit entry; rejected rows in `import_batch_errors` |

Deleting a lead (`lead.delete`, for one entered by mistake) is a soft delete: it disappears from every screen, its history
stays in the database, its number is not used again and what was planned for it is called off.

## For developers

```
app/Modules/CRM/
  Enums/        LeadStage · LeadChannel · FollowupType · FollowupStatus
  Models/       Lead · LeadInterest · LeadTransfer · LeadStageEntry · Followup · LeadSource · FollowupOutcome · LostReason
  Services/     LeadService        create / update / changeStage / delete / duplicates / validate
                LeadAccess         works() / sees() / manages() / restrict(query) / openable(leads)
                LeadTransferService transfer(ids, toUser, reason, actor)
                FollowupService    schedule / complete / log / reschedule / cancel / assignable
                FollowupAgenda     query(actor, person?, team?) / between(from, to) / teams — lists, calendar, the API later
  Policies/     LeadPolicy · LeadChildPolicy · FollowupPolicy · CrmMasterPolicy
  Imports/      LeadImporter · LeadImportSession
  Console/      SendFollowupReminders (crm:followup-reminders)
  Actions/      SeedCrmDefaults (sources, outcomes, lost reasons for a company)
  Notifications/ LeadsAssignedNotification · FollowupDueNotification · FollowupOverdueNotification
  Http/Livewire/ Leads/{LeadList,LeadForm,LeadShow,LeadImport} · Followups/{FollowupList,FollowupCalendar}
                 Masters/{LeadSourceList,FollowupOutcomeList,LostReasonList}
resources/js/followup-calendar.js   the FullCalendar set-up, imported on demand by resources/js/app.js
```

- **Lead data has one door: `LeadService`.** The form, the import and (Phase 8) the mobile API and (Phase 9) the marketplace
  integrations all call `create()` / `update()` with the same input shape, so the rules cannot drift. It checks the branch
  against the *actor* explicitly (not the ambient scope), because an import runs in a job where nobody is signed in.
- **"Which leads" is always `LeadAccess`** — policies call `works()` / `sees()`, lists use `restrict($query, $user)`, and a
  page of rows asks `openable()` once instead of the policy per row. Never compare role names, and never widen a list with a
  filter: filters only narrow what `restrict()` allows.
- **Follow-ups hang on a morph** (`subject_type` / `subject_id`): a lead today; deals, quotations and customers get theirs
  without a new table. `FollowupAgenda` is the read side for every screen that lists them.
- **Stage and ownership history are evidence**: plain models with `updating` / `deleting` guards that throw.
- **The mobile API (Phase 8)** calls these services ([api.md](api.md)). A person who may mark attendance enters leads from the
  field through `FieldLeadService`: `FieldPositionGate` checks the position against today's published plan first (a refusal is
  logged with `attempt_type = lead_save` and nothing is saved), then `LeadService::create()` runs with channel `field`, the
  plan's branch and the accepted position. Lists use `LeadAccess::restrict()` and `LeadFilters` — the same filters as the screen.

### Known limits

- Leads cannot be exported yet (`lead.export` / `followup.export` are reserved for the reports of Phase 13), and there is no
  merge of duplicate leads.
- Attachments on follow-ups arrive with document management (Phase 19).
- Reminders and escalation need the scheduler to be running; e-mail needs a real `MAIL_*` transport (local development logs
  mail instead).
- A lead's branch cannot be changed after it is entered; enter it again in the right branch and delete the other.
- The calendar needs JavaScript (the *Follow-ups* list shows the same information without it). Dragging moves a follow-up
  within the visible week or month only.
