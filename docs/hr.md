# HR & workforce

`app/Modules/HR`. None of the briefs specifies HR, so this module is planned here and built one phase at a time (each ends with the gates, a report and a stop for review).

| Phase | Scope | State |
|---|---|---|
| **H1** | **Core HR**: employee master, org chart, change of job with a dated history, confirmation, notice and exit (login switched off on the last day), emergency contacts, papers on file, HR dashboard, "My profile" | **built — awaiting owner review** |
| **H2** | **Leave and attendance** for all staff: holiday calendar, leave types, ledger balances and accrual, leave requests decided by the manager, shifts, punches, the nightly day-close and corrections (the field-sales attendance of Phase 6 stays as it is) | **built — awaiting owner review** |
| H3 | Payroll: salary structures, statutory rules (PF, ESI, professional tax, TDS — to be confirmed by the owner), monthly payroll run, payslips (PDF), loans and advances | planned |
| **H4** | **Recruitment** (openings, candidates, interviews, offers, hiring), **performance** (review cycles, goals, reviews), **training** (programs, enrolment, outcomes) | **built — awaiting owner review** (onboarding / exit checklists are not built) |

## H1 — what is built

* **Employee file** (`employees`): code, name, contact details, date of birth, gender, address, branch, department, designation, **manager** (the org chart is this one column), employment type
  (permanent, probation, contract, intern, consultant), joining date, probation end, confirmation, employment status (**active → on notice → left**), last working day and reason.
  Linked to a login (`user_id`, unique) when the person has one. Files are never deleted: a person who leaves is exited and the history stays.
* **History** (`employment_events`): append-only, one line for each thing that changed (department, designation, branch, manager, type), with the day it took effect, who recorded it, and why.
  A change of job is made with **Change job** (dated, not in the future, not before joining); *Edit details* never changes the job.
* **Who sees what** (`EmployeeAccess`, never a role name): `employee.view_all` (HR, management) sees everyone; everyone else sees **their own file and everyone below them in the reporting line**;
  everyone may open their own file (`/hr/me`). Date of birth, personal e-mail, address, emergency contacts and papers need `employee.view_sensitive` (HR) — except on one's own file.
* **Rules** (`EmployeeService`, the one door): unique employee code; at least 14 years old; joins no more than 90 days ahead; manager is an active employee of the same company, never the person or
  anyone who reports to them (no circles — walked without the branch scope so a manager the actor cannot see cannot hide one); one login per file and only a login of the company; up to 5
  emergency contacts, one of them first to call; a person who has left cannot be changed until re-joined.
* **Notice and exit** (`employee.record_exit`): record notice with the last working day (today = leaves at once), withdraw it, re-join. `hr:sync-exits` (daily 00:30, idempotent) marks everyone
  whose last day has passed as having left, **switches their login off and revokes their API tokens**. A re-joined person's login stays off until a user administrator switches it on.
* **Papers** (`employee.manage_documents`): PDF, image or Word, up to 5 MB, kept on the **private** disk under a random name and handed over only by `EmployeeDocumentController`, which re-checks that
  the person may see that file *and* its sensitive parts every time. Expiry dates feed the dashboard.
* **HR dashboard** (`hr_dashboard.view`, `HrFigures`): headcount (active + on notice), serving notice, joiners and leavers in 30 days, headcount by department / branch / type, birthdays and work
  anniversaries in the next 30 days, probation ending, people reporting to someone who has left, papers expired or expiring. Counted from the files the viewer may see.
* **Screens**: `/hr`, `/hr/employees` (+ add / edit / file), `/hr/org-chart`, `/hr/me`. New role **HR Manager**; Management reads everyone (no sensitive fields); Sales Manager sees their team.

## H2 — leave and attendance

**Leave** (`LeaveService`, the one door; `LeaveAccrual`):

* **The balance is a ledger** (`leave_ledger`, append-only): opening / accrual / taken / reversal / adjustment / lapse, signed days in halves. A balance is the sum of a person's lines for a leave year (the
  financial year, April–March). Every line has a `period_key` under a unique key (`Y2026`, `M2026-10`, `C2026`, `L2025`, `R<request>`), so the scheduler, a retry and a person running it by hand cannot write
  anything twice.
* **Leave types** (`/hr/leave/types`): yearly quota, **yearly** (all at the start of the year, in proportion for someone who joins during it), **monthly** (a twelfth a month, in halves that add up to the quota) or
  **none** (HR grants it); carry-forward limit (the rest lapses); half days allowed; negative balance allowed (unpaid leave); longest single request.
* **A request** counts **working days only** (company holidays and the weekly off — setting `hr.weekly_off`: Sunday, Saturday + Sunday, Sunday + 2nd/4th Saturday, none — are not counted) and must contain one. Half a day
  only on a single day, for a type that allows it. At most 90 days, at most 30 days back and a year ahead, not across a financial year. It may not overlap another waiting or approved request (a first-half and a
  second-half request may share a day). The days must be there: balance **less what other waiting requests of the same type already ask for** (so a person cannot book the same days twice by sending two requests),
  unless the type allows a negative balance.
* **Deciding**: the employee's manager (or anyone above them in the reporting line) with `leave.approve`, or HR with `leave.approve_all`. **Never one's own request.** Approving takes the days from the ledger exactly
  once and checks the balance again; cancelling an approved request gives them back (a person may cancel their own before it starts; their manager line and HR any time). Rejecting needs a reason.
  Leave is decided on its own screen, not in the approval inbox: its approver is the person's manager, which the generic per-document-type levels cannot say.
* HR may apply for anyone (`leave.approve_all`) and give or take away days by hand (`leave.manage_balances`: opening balances, corrections, grants).

**Attendance** (`AttendanceService`):

* **Punches** are the person's own, at the server's time, alternating in and out (`/hr/attendance`), append-only evidence. Shifts (`/hr/shifts`): start, end, grace, minutes for a full and a half day; one is the
  company default; a person may have their own (`employees.shift_id`). With no shift at all, 09:30–18:30, grace 15, full day 480 min, half day 240 min.
* **The day-close** (`hr:attendance-close`, daily 00:45, idempotent, catches up a missed week): approved full-day leave = leave; approved half-day leave = half day; a holiday or the weekly off = holiday / weekly off;
  otherwise by the time from the first punch in to the last punch out: full = present, half = half day, less = absent. Punched in and never out = absent and flagged "no punch out". Late = first in after the shift start
  plus the grace, counted from the start. Approving or cancelling leave and approving a correction close the affected past days again.
* **Corrections** (`/hr/attendance`, decided at `/hr/attendance/corrections`): the person gives the times for a day in the last 30 days; their manager (`staff_attendance.approve`) or HR
  (`staff_attendance.approve_all`) approves, never the person themself; approval adds two punches marked "regularised" and closes the day again.
* **Team attendance** (`/hr/attendance/team`): the month for the people the viewer manages (everyone, for HR): counts and a day-by-day grid.

**Roles**: HR Manager holds everything above; Sales Manager, Operations, Quality/Loss Manager, Finance Controller and Management hold `leave.view`/`approve` and `staff_attendance.view`/`approve` for **their own line only**;
everyone may open and use their own leave and attendance. `holiday.*` guards the HR holiday screen over the same `holidays` table the SLA clocks use.

**Verified**: hand-calculated Pest tests (working days around a holiday and a Sunday, accrual, carry-forward, every refusal, every decision, the day-close, corrections); `tests/Concurrency/HrConcurrencyTest` (five
approvers of one request take the days once; five tabs sending the same leave save one request; five taps of "punch in" punch in once) — each fails when its lock is removed.

## H4 — recruitment, performance and training

**Recruitment** (`RecruitmentService`; HR only, `recruitment.*`; nothing is deleted):

* **Openings** (`/hr/recruitment/openings`): draft → open → closed (it can be re-opened); reference, branch, department, designation, hiring manager, positions. People apply only to an open one, once each.
* **Candidates** (`/hr/recruitment/candidates`): one file per person; the mobile number is unique in the company; a mobile number or an e-mail is needed so the person can be reached.
* **An application** moves applied → screening → interview; rejecting or withdrawing needs a reason and cancels what was planned (interviews, a sent offer). **Offer and hired are reached only by making an offer and hiring**,
  never by moving the stage. Every move is in the append-only application history.
* **Interviews**: booked for a future time with an active login of the company as interviewer; the interviewer is told. **Only that interviewer writes the feedback** (rating 1–5, hire / hold / reject, text), from the day of
  the interview (`/hr/recruitment/interviews`, open to anyone who is an interviewer).
* **Offer**: needs an interview someone recommended *hiring* on; carries no money (salary is payroll, H3); joining date from today to 90 days ahead; accepted or declined (declined withdraws the application).
* **Hire** makes the employee file through `EmployeeService::create` (needs `employee.create`): code, name, mobile, personal e-mail, department, designation, branch and manager from the opening and the offer, **on probation
  for six months** from the joining date. Once only; never more people than the opening has positions (the opening row is locked); when the last position is filled the opening closes itself; the hiring manager is told.

**Performance** (`PerformanceService`; `review_cycle.*` for HR, `performance.view/edit` for a manager's own line, `performance.view_all` and `performance.finalise` for HR):

* **A cycle** is draft → open → closed. Opening it makes one review for every employee who had joined by the cycle's end (once; a person who joins later is given theirs when they open the page).
* **Goals**: the manager line (or HR) sets them, at most ten a person, weights in percent adding up to at most 100; the person updates their own progress. Goals and progress are fixed once the manager has rated.
  The weighted score is Σ(weight × progress) ÷ Σ(weight).
* **Review**: the person rates themself (1–5, with comments) → their manager rates them (never themself, and only after the self-review) → HR makes it final (by default the manager's rating, or another 1–5). A final review does not change.

**Training** (`TrainingService`; `training.*` for HR; `training.enrol_team` for a manager's own line):

* **Programs** with dates, mode, venue, trainer and seats. **Enrolling takes the program lock first**, so two people cannot take the last seat; once each, only people still with the company, only into a planned program that has not ended.
  HR enrols anyone, a manager their own line (never themself). Outcomes (completed with a score of 0–100, absent, dropped) are recorded from the first day; a program is completed only when every outcome is in;
  cancelling drops everyone and tells them. `/hr/training` is each person's own training.

**Notices** (`HrNotifier`, `HrNotice`; in-app and e-mail, never undoing a change if the mail fails): the manager is told of a leave request, a cancelled leave and an attendance correction; the person of the decision;
the interviewer of an interview; the hiring manager of a new joiner; the manager of a self-review; the person of a final review and of an enrolment or cancelled program. (This closes the H2 gap.)

**Verified**: hand-calculated Pest tests for every rule above; `tests/Concurrency/TalentConcurrencyTest` (several people hiring for one position make one employee; five people enrolling on two seats take two) — each fails
when its lock is removed.

## Not in H4 (open for the owner)

* No salary or compensation anywhere in recruitment (payroll, H3); a résumé can be attached to a candidate (see below; papers on the employee file only once hired); no job-portal or e-mail integration.
* Review ratings are a single 1–5 scale with no competency framework, 360° feedback, calibration or pay-linked outcome.
* Training has no cost tracking, certificates, mandatory-training rules or self-enrolment.

## Not in H2 (open for the owner)

* A holiday added or removed later does not rewrite days already closed.
* No leave encashment, compensatory-off, maternity/paternity rules or leave across a financial-year boundary in one request.
* Punches are web only (no biometric device, no geofence for office staff, no import).
* Overtime and shift rosters/rotations are not modelled.

## Not in H1 (open for the owner)

* PAN, Aadhaar and bank details are **not** stored — they arrive with payroll (H3), encrypted and masked like ledger bank accounts.
* No spreadsheet import of employees yet (it would use `Shared/Imports`).
* Leave, attendance, payroll, recruitment, performance: H2–H4 above.
* Changing the manager does not move work already assigned through the sales team or approval levels; those have their own rules.

## H5 — onboarding and exit checklists

* **The standard list** (`/hr/checklists/standard`, `checklist.*`, HR Manager): lines of *what to do*, which **list** (onboarding or exit), **who** does it (HR, the reporting manager, or the employee) and **days after the list starts** it is due. Inactive lines are not copied.
* **A person's list is a copy**, made once by `ChecklistService::start()`: automatically when an employee file is created (onboarding, due from the joining date) and when notice is recorded (exit, due from today), or by hand by HR on the *Onboarding and exit*
  screen (`/hr/checklists`). Starting twice makes nothing twice (employee row lock + `unique(employee, kind, source line)`); a line added to the standard list later is added on the next start; lines already made keep their own wording and dates.
* **Who ticks what** (`ChecklistService::canTick`): HR (`checklist.edit`) any line; the reporting manager (anyone above the person in the line — never the person themself) the manager's lines; the person their own lines. A finished line is never changed or deleted (the model refuses), with who and when kept.
* **Who sees what:** HR (`checklist.view`) every list; everyone else their own and those of the people below them in the reporting line.
* **Late reminders** (`hr:checklist-reminders`, daily 09:00, `ChecklistReminders`): one notice per person (a digest) for the late lines that are theirs — the employee's own, the manager's lines for their reports, and HR's lines to everyone holding
  `checklist.edit`. A line is claimed by a conditional update of `reminded_at`, so it is told about once a week at most and never twice by two runs at the same moment (six-process race test, mutation-checked). People who have left are not reminded.
* **Not built:** a line that blocks the exit from being finalised (a policy for the owner), a separate list per department or employment type, undoing a tick (a finished line is evidence), attachments on a line.

### Candidate résumé

HR (`recruitment.edit`) can attach one résumé (PDF or Word, up to 5 MB) to a candidate on the Candidates screen; a new file replaces the old one and removing it deletes the file. It is kept on the private disk and leaves it only through
`CandidateResumeController` (`recruitment.view` is checked on every download; another company's candidate is a 404). Not built: reading or searching the text of a résumé.
