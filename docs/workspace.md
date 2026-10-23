# Customer 360, tasks and SLAs (Phase 14)

Three things that sit around the records the other modules keep: one page per customer, a task list for the working day, and service-level
clocks that notice when something waits too long. They live in `app/Modules/Workspace`.

## Customer 360

`/crm/customers/{id}` — reached from a customer's ledger page and from the lead, deal, quotation and sales-order pages ("Customer 360").

* **Who opens it**: anyone who may open the customer's ledger (`ledger.view`); only customer ledgers (a supplier is a 404), only in the company
  they work in.
* **What is on it**: the master record (name, type, GSTIN, status), contacts, addresses, **credit terms** (limit, days, what is out on orders
  within the credit days, what is left, and a status: within / close to / over the limit / no limit), totals (approved sales net of tax,
  open deals, open quotations, leads), and lists of the customer's **deals, quotations, sales orders, sales invoices, leads and inquiries,
  follow-ups, tasks and the deals' stage history**, with a note and a link to add a task.
* **Each section is read through its own screen's access class** (`DealAccess`, `QuotationAccess`, `SalesOrderAccess`, `LeadAccess`,
  `FollowupAgenda`, `TaskAccess`), so a salesperson sees their own records for this customer, a manager their teams', an administrator all of
  them; and a section the person has no right to at all (no `deal.view`, say) is **left out**, not shown empty. Accounts, for example, see
  orders and invoices but not leads and deals.
* **Never shown**: bank details. Contacts are what the ledger screen already shows to the same people.
* **What is linked**: records whose customer is this ledger, and the leads that became those deals. A new lead that was never tied to a
  customer record is *not* found by name or phone number — it is not this customer until somebody links it.
* **Credit is a company-level figure** (an aggregate over all of the customer's orders, whoever owns them), as on the order screen. Payments are
  not recorded yet, so "out" counts pending and approved orders made within the credit days.
* **Not built, and the page says so**: communication history and campaign attribution (Phases 15–16), documents and e-signatures, support
  tickets (Phase 19).

## Tasks

`/tasks` — a list, a board (to do / in progress / done in the last 14 days) and a month calendar of deadlines, each filtered by *with me / given
by me / team / everything I may see*, person, priority and a search.

* A task has a title, details, a **priority** (low, normal, high, urgent), an optional **deadline** and **reminder**, an optional **repeat**
  (daily, weekly, monthly, with a last date), and optionally the **record it is about** (lead, deal, quotation, sales order, customer — set from the
  "Add a task" link on those pages; the service checks that the person may open the record).
* Its life: *to do → in progress → done* (with a note) or *called off* (with a reason). A task that is done or called off is a record and never
  changes. A task is never deleted.
* **Who sees and works what** (`TaskAccess`): the tasks given to a person and the ones they made; a team manager also the tasks of the people they
  manage, and works them; `sales.scope_all` sees everyone's. A label of the record a task is about is shown only to someone who may open that record.
* **Giving a task to somebody** needs `task.assign` and a person in reach (yourself, or the people you manage; everyone for `sales.scope_all`). Every
  giving is a row of the append-only assignment history ("Who has had it"). The new person is told, in-app and by e-mail.
* **A repeating task** makes the next one when it is finished: one repeat after the deadline just served, and, if that is already past, the first
  that is still ahead; the reminder keeps its distance from the deadline; a month never overflows (31 Jan → 28 Feb); it stops after the last date.
* **Reminders** (`tasks:remind`, every five minutes): the person is told once at the reminder time and once when the task is overdue. Each is
  *claimed* with a conditional UPDATE, so overlapping runs never tell anyone twice; moving the deadline or the reminder (or handing the task over)
  re-arms them. Stale ones (a day past the reminder, a week past the deadline) are left alone.
* **Comments** use the shared comment thread (`task.comment`). **Attachments are not built** (documents are Phase 19).
* Follow-ups with a lead stay on the lead; tasks are the general to-do list.

## SLA and escalation

`/admin/sla` (rules and holidays, `sla_policy.view`; changing needs `sla_policy.create/edit`) and `/sla/breaches` (the log).

* A **policy** says how long a kind of thing may wait: **first response to a new lead**, **a planned follow-up done on time**, **a task done by its
  deadline**. It applies by **lead source**, **team** and (tasks) **priority**; the **most specific** matching policy wins. It applies only to things
  made **after** the policy was (so switching one on does not put a month of old leads in breach). A policy can be switched off, never deleted
  (the clocks and breaches point at it).
* **Business hours**: a policy can count only working time. Working days (Mon–Fri / Mon–Sat / every day) and the day's start and end are company
  settings ("SLA and tasks"); **holidays** are listed on the policies page. A lead made at 6 pm on a Friday with a 4-hour policy falls due on Monday
  at 1:30 pm (Mon–Fri, 9:30–18:30).
* **The sweep** (`sla:check`, every five minutes, `SlaSweep`) does four idempotent things:
  1. **starts** a clock for each lead, planned follow-up and task that a policy covers and that has none (due = the lead's creation / the follow-up's
     planned time / the task's deadline + the policy's minutes);
  2. **settles** running clocks from the facts: a lead that was **contacted** (its `last_contacted_at`, or a stage beyond *New*), a follow-up that
     was **done**, a task that was **finished** have *met* theirs — at the moment it happened, so being late shows even if the sweep ran later;
     a lead that was **won or lost**, a follow-up or task **called off** or **moved**, a lead **handed over** have *stopped* theirs (a moved
     follow-up or task gets a new clock for its new time; a handed-over lead gets a fresh one for the new owner from the hand-over);
  3. **notices** clocks that ran out: claims the breach with one conditional UPDATE, writes the **breach log** (append-only), tells the person
     and — if the policy says so — their **team manager**, once each, and leaves an **audit entry** (`sla` / `breached`);
  4. a thing answered after its time but before anyone noticed is logged as **answered late** (no notice).
* **Nothing is reassigned automatically.** An escalation tells a manager; it does not move work. A lead in the pool (no owner) is logged but nobody is
  notified.
* There is **no pause**: a clock runs or stops. The brief's "pause/stop rules" are the stops above.
* **Reports and dashboard**: the *SLA compliance* report (per policy: timed, on time, late, still running, stopped, on-time %) and *Tasks and
  overdue tasks* report in [reports.md](reports.md); the sales dashboard shows "My open tasks" and "Service levels met". Counted *actual*.
* The follow-up reminder/escalation of Phase 7 (`crm:followup-reminders`, a manager told 24 hours after) is unchanged and independent. A company
  that also adds a follow-up SLA policy gets both; leave the policy off to keep the old behaviour alone.

## Permissions and settings

New permission forms: **`task`** (`view`, `create`, `edit`, `comment`, `assign`, `export`, `view_audit`) and **`sla_policy`** (`view`, `create`, `edit`,
`delete`). Granted: BDE and Tele-caller — tasks view/create/edit/comment; Accounts and Operations — the same; Sales Manager — all task rights and
`sla_policy.view`; Company Admin and Super Admin — everything. Settings: `sla.business_days`, `sla.business_start`, `sla.business_end`.

## Gaps closed after the Phase 14 review

* **SLA pause** — a person holding `sla.pause` (Sales Manager, administrators) pauses a *running* clock from the **Running** tab of `/sla/breaches` with a
  reason; a paused clock cannot run out, and resuming moves its deadline on by the time it was paused. Every pause and resume is audited.
* **SLA for quotations and approvals** — two new policy kinds: *Quotation answered* (a clock starts when a quotation is sent and is met when it is accepted
  or declined, stopped when it is replaced or withdrawn) and *Approval decided* (starts when a document is submitted; the pending approver is told on
  a breach; met when it is decided).
* **Breach log for salespeople** — anyone who works leads, follow-ups or tasks can open `/sla/breaches`; they see only their own breaches (a manager
  sees their teams', `sales.scope_all` everyone's).
* **Overlap with Phase 7 follow-up alerts** — when a follow-up SLA policy covers a follow-up, the older 24-hour manager alert stands down for it (one
  alert, not two); when no policy covers it, the old behaviour is unchanged.
* **Task attachments** — private files on a task (type, size and count limits, content sniffed rather than trusting the name, handed only to people who may
  open the task, removable while the task is live).
* **Tasks about a record** — the task form offers a record picker listing only records the person may open. Excel export of tasks goes through the
  *Overdue tasks* report (queued, authorised when asked and when downloaded).
* **Customer 360 search** — the page suggests leads that share the customer's phone number or e-mail, clearly marked as *not yet linked*, for a person to
  link; they are never counted as the customer's.
* **Scheduler and queue** — `schedule:heartbeat` writes a pulse every minute; **System health** (`/admin/health`, `setting.configure`) shows whether the
  scheduler and each job are running, and the queue, mail, storage and cache; the dashboard warns an administrator when the scheduler has gone quiet.
  The scheduler and a queue worker are still required outside local development ([README.md](../README.md)).

## Known gaps and decisions to confirm

* No automatic **reassignment** on a breach, and no per-priority SLA for leads (leads have no priority). Approval deadlines are still also the separate
  `approvals.due_at`.
* Task filters cannot be saved.
* Customer 360 has no communication history yet (Phase 16 brings the providers; the Phase 15 message log is linked from the lead) and its credit figure is
  the company-level one described above.
* A pause has no maximum length and no automatic resume; a manager must resume it.
