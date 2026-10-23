# Field sales: teams, targets, route plans, attendance

Screens: the *Field sales* section of the menu. Tables: [schema.md](schema.md). The mobile API ([api.md](api.md), Phase 8) calls
the same services: attendance through `AttendanceService`, and a lead saved from the field through `FieldLeadService`, both
asking `FieldPositionGate` whether the position is inside today's geofence.

```
Sales Manager ──runs──▶ team ──has──▶ salespeople
      │                                   │
      ├── sets targets (month / quarter)  │
      └── plans a day ──publish──▶ route plan ──▶ the person marks attendance from inside a stop's geofence
                                                   └─ refused? → geofence violation log (→ a manager may accept it)
```

**The rule everything hangs on: no route plan, no attendance.** A person can mark attendance only against a *published*
route plan for that day, from a position the server finds inside the geofence of one of its stops.

## Whose work a person may plan, measure and see

| Who | Reach |
|---|---|
| A salesperson | their own plan, attendance, refused attempts and targets (and their team's target) |
| A **team manager** | also the *current* members of the *active* teams they manage |
| A holder of **`sales.scope_all`** (Company Admin, Super Admin) | every salesperson of the company |

This is `SalesScope`. It is a permission, never a role name: grant `sales.scope_all` to someone and they see everyone; a
manager without it sees only their teams. A manager never plans, targets or accepts attendance for *themselves* unless they
hold `sales.scope_all` — and nobody ever accepts their own refused attendance. Branch restrictions apply on top: a plan
belongs to a branch, and people who do not work in that branch do not see it.

## Sales teams

* A team has a code, a name, **one manager** and members. A manager can run several teams; a manager without
  `sales.scope_all` can only create and change teams of which they are the manager.
* **A person is on one team at a time.** Memberships have a *joined* day and a *left* day (the last day, inclusive) and never
  overlap; leaving ends the membership — rows are never deleted — so "who was on this team last month" stays answerable.
  The database backs the rule with a generated column and a unique key (`team_members_one_open_membership`).
* Switching a team off removes the manager's reach through it. A team that has had members, or has targets, is not
  deleted — only switched off.

## Targets

A target is for **one salesperson or one team**, for **a calendar month or a quarter of the company's financial year**
(Q1 = the first three months from the year's start month, April by default), by **value** (rupees, net of tax) and/or
**quantity**, optionally narrowed to one **item group** and/or one **branch**.

* **No overlapping duplicates.** Two targets for the same person (or team) with the same item group and branch may not
  cover any day in common — not October twice, and not October next to the quarter that contains it. A target can be marked
  **"allow overlap"** explicitly (a campaign target alongside the regular one): it then coexists, and is ignored when others
  are checked. The check runs under a lock on the person or team — `tests/Concurrency/FieldSalesConcurrencyTest.php` proves
  it with real processes and fails if the lock is removed — and an exact duplicate is also refused by a unique key.
* A target whose period is over is history: it is not changed or removed.
* A manager sets targets for their people and their teams; `sales.scope_all` for anyone.

### What counts as achieved

Achievement comes from **approved sales orders** (`SalesOrderAchievement`, Phase 12): a target with no approved order yet shows 0 %. The
policy (the `AchievementSource` contract) is:

| Question | Answer |
|---|---|
| Which orders? | **Approved** ones only. Drafts, pending, rejected and **cancelled** orders do not count; an approved order that is cancelled later stops counting from then |
| When and for whom? | on the **order date**, for the order's **owner** (the salesperson credited) |
| What value? | the order total **net of tax** — after discount, before GST |
| Partial orders? | an order counts **in full when approved**, whether or not it has been invoiced, delivered or paid |
| Returns? | returns and credit notes reduce the period **in which they are recorded**, never an earlier one |
| Quantity? | in each item's **base unit** — so use an item group whose items share a unit |
| Team targets? | the team's members added up, **each only for the days they were on the team** |

Progress is worked out in exact decimals and rounded half up to one decimal; it can exceed 100 %.

## Route plans

One plan per **salesperson, day and branch** — one per person and day, ever. States: **draft → published → cancelled**
(a cancelled plan can be reinstated as a draft while its day is not over).

* The planner must manage the person; the person must be able to work in that branch; a plan is made for **today or later**.
  A day that is over is history: its plan is not edited, cancelled or deleted.
* **Stops** (up to 50), in order: an **area** (free text), a **block**, a **PIN code** or a **customer** — the last three must
  exist in the company's masters, and a customer must be an approved, active customer ledger. Each stop may have a **place on
  the map** (latitude and longitude, both or neither), its own **radius** (50–5000 m) and a **visit window**.
* A stop with a place on the map is a **geofence**; one without is a reminder. **Publishing needs at least one geofence**,
  and a published plan cannot lose its last one.
* Once attendance has been marked against a plan its person, day and branch are fixed, it cannot be cancelled, and a stop
  that attendance or a refused attempt was measured against cannot be removed.
* Only an unused draft can be deleted (its day then becomes free).

## Attendance

`AttendanceService::mark($user, $latitude, $longitude, $accuracy)` — called by *My day* and by the mobile API
(`POST /api/v1/attendance`). The device only reports a position and how accurate it says it is; **the server decides
everything**, in this order:

1. Today is not already marked — that is refused but is **not** a violation ("already marked at 09:48").
2. There is a **published** plan for the person for today. No plan, a draft or a cancelled plan → refused.
3. The position is a real one: latitude −90…90, longitude −180…180, not 0,0, with an accuracy above zero.
4. The accuracy is no worse than **`geofence.max_accuracy_m`** (150 m by default).
5. The position is within the radius of **any stop** of the plan that has a place on the map. The radius is the stop's own,
   else the plan's, else the company's **`geofence.radius_m`** (500 m). Distance is the great-circle distance (haversine).

Steps 2–5 are `FieldPositionGate::verify()` — the one decision shared with **a lead saved from the field**, so the two can
never drift apart (the wording of a refusal says which of the two was attempted).

If all hold, an attendance record is written with the **server's clock**, the position, accuracy, distance, radius and the
stop that matched. One record per person and day (a unique key backs it; five simultaneous taps give one record and four
"already marked"). Records are **append-only**: never edited, never deleted.

### The geofence violation log

Every refused attempt (2–5 above) is logged: who, when (server time), what was reported, how far from the nearest stop, the
radius, the accuracy and the message the person saw. Readings that are not a position are kept as text. The log is
append-only. Reasons: `no_route_plan`, `plan_not_published`, `plan_cancelled`, `invalid_gps`, `low_accuracy`,
`outside_geofence`. Each entry also says what was attempted — **`attendance`**, or **`lead_save`** when the mobile app tried
to save a lead from the field outside the geofence (the lead is then *not* saved; the screen shows "Lead saved in the field /
Lead not saved"). Only a refused attendance can be accepted by a manager; a refused lead is entered again from where the
person should be.

### A manager accepting a refused attempt

Off by default. When **Company settings → Field sales → "Allow a manager to override a geofence violation"** is on, someone
who holds **`geofence.override`** can accept a refused attempt of a person **they manage** (never their own), with a **reason**,
within **7 days**, if the refusal was about the position (`low_accuracy` or `outside_geofence` — never a missing plan or an
unreadable reading), the plan is still published and the day has no attendance yet. The attendance is recorded as *accepted
by a manager* with the position and time the person actually reported; the refused attempt stays in the log and points at it;
the act is written to the audit log.

## Who may do what

| | My day (mark attendance) | Route plans | Targets | Teams | Attendance & violation log | Accept a refused attempt |
|---|---|---|---|---|---|---|
| BDE / Field Salesperson | ✓ | own, read-only | own, read-only | | own | |
| Sales Manager | | plan their teams | set for their teams | run their own | their teams | ✓ if the company allows it |
| Company Admin, Super Admin | ✓ | everyone | everyone | every team | everyone | ✓ if the company allows it |
| Tele-caller, Accounts, Purchase, Inventory, Operations, Approver | | | | | | |

Deleting a team or removing a target needs the delete permission, which the Sales Manager does not hold by default (they
switch a team off, or edit a target, instead).

## Settings (Company settings → Field sales)

| Setting | Default | Meaning |
|---|---|---|
| `geofence.radius_m` | 500 | radius of a stop that sets none, when the plan sets none either |
| `geofence.max_accuracy_m` | 150 | a reading less accurate than this is refused |
| `geofence.manager_override` | off | whether a manager may accept a refused attempt |

## Known limits

* Attendance is a single check-in per day; there is no check-out and no per-stop visit confirmation yet (stops carry a
  visit status for the lead and visit screens of Phases 7–8).
* *My day* uses the browser's location; the phone app uses the mobile API ([api.md](api.md)). A browser on a desktop is often
  far less accurate than 150 m — that is the rule working, not a fault.
* Stop coordinates are typed in (copied from a map); there is no map picker, and blocks, PIN codes and customers carry no
  coordinates of their own.
* A position is whatever the device reports: a faked GPS on the device cannot be detected by the server.
* Achievement is read from approved sales orders (Phase 12); returns and credit notes do not exist yet, so a target never goes down for one. "Target vs achievement" reports are Phase 13.
* Teams are flat (a manager and members); there is no team-of-teams hierarchy. Route-plan import is not built yet (the
  `route_plan.import` permission exists for when it is).
