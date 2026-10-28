# Territories and route suggestions

Screens: **Field sales → Territories**, the *Suggest an order* button on **Route plans → a plan's form**, and a *Territory* line on a lead's page.
Tables: [schema.md](schema.md). Brief sections: 6.13 "Territory and route optimization" and B2 (route plan) of the master brief.

Both are **advice**. A territory says who *should* work a place; a suggestion says which order of stops is *shorter*. Neither moves a lead, a plan, a
target or a person by itself, and the person who plans keeps the control.

## Territories

A territory is a named part of the map with **one owner** (optional) and, optionally, **one branch** it is worked from (none = company-wide). It is a plain
master — not an approval document — with a code, a name, a status and remarks.

### What it covers

Coverage is a list of pieces, each one of: a **state**, a **district**, a **block**, a **PIN code** or a **customer** (an approved, active customer ledger).

| Rule | Why |
|---|---|
| **A piece belongs to one territory.** The database holds it (a unique key per company, kind and reference); the screen says which territory has it when you try to give it twice | so "who covers this PIN code?" has one answer |
| **The most specific cover wins** when a place is looked up: customer, then PIN code, then block, district, state | a district can go to one territory with a few PIN codes, or one big customer, carved out for another |
| **Only switched-on territories are looked up.** A switched-off territory keeps its coverage, and it stays taken | switching off is a pause, not a release; take the pieces off to release them |
| A **PIN code** is covered by its six digits, not by one locality row | one PIN can sit on several localities, blocks even (the demo geography has 382110 in two) |
| A PIN code must be in the company's geography master; a district, block or state must be active and the company's own; a customer must be usable (`LedgerLookup`) | nothing from outside the company, nothing unapproved |
| Coverage is removed **for real** (not soft-deleted) and the change is written to the territory's audit trail (`coverage_added`, `coverage_removed`) | a soft-deleted row would still hold the key |
| A territory with coverage cannot be deleted — switch it off, or take its coverage off first | |

**How a lead is placed.** `TerritoryResolver::forLead()` reads the lead's customer, PIN code, block, district and state and returns the best cover. The lead's
page shows it as *Territory: North Gujarat — Ravi Patel (by District)*, to people who may view territories. That is all it does: **leads are not assigned,
transferred or counted by territory** (see *Not built*).

### Who may do what

| | See | Add / edit (owner, branch, status) | Give and take off coverage | Delete |
|---|---|---|---|---|
| `territory.view` | ✓ (the territories of their branches and the company-wide ones) | | | |
| `territory.create` / `territory.edit` | | ✓ | | |
| `territory.assign` | | | ✓ (on a switched-on territory) | |
| `territory.delete` | | | | ✓ (empty ones only) |

Defaults: **Sales Manager** has view, create, edit and assign; **BDE / Field Salesperson** can view; **Company Admin** and **Super Admin** hold everything. An
owner must be an active user of the company — of the branch, when the territory names one. A branch is one the person saving works in.

> **On an installation that already has its roles**, the new permissions reach only Super Admin automatically: the seeder gives a default role its permissions
> *when the role is first created*, so an administrator's edited matrix is never overwritten. Grant `territory.*` to Sales Manager, BDE and Company Admin on
> the Roles screen (or run a fresh seed on a demo database). Until then those roles get a 403 on the territory screens.

## Route suggestions

On a route plan's form, **Suggest an order** looks at the stops that have a place on the map and shows a shorter order, if there is one. Nothing is saved by
asking. **Use this order** moves the stops in the form; the plan is still saved by the ordinary *Save*, through `RoutePlanService`, with every rule it has.

How it works (`RouteSuggestion`, pure arithmetic — no data access, no permission, no network):

* **Distances are straight lines** over the earth (the same haversine the geofence uses), not roads. A river, a ring road or a one-way street is invisible to it.
  No mapping provider is wired in: a provider's terms, keys, rate limits and privacy are the owner's to accept first; it would be one more class behind this one.
* **Start:** the plan's **branch**, when it has coordinates; otherwise the planner's **first stop**, which then stays first.
* **Method:** visit the nearest stop that is left, then try swapping stretches of the route (2-opt) while that makes it shorter. Ties go to the earlier stop, so the
  same stops always give the same order. It is **never longer** than the order the planner has — when it cannot do better it says *already the shortest*.
* **A stop with no place on the map** cannot be placed: it keeps its position and is listed as left where you put it.
* **Visit windows are not forced.** If the new order would visit a stop before a stop whose window starts later, the panel warns and names the stops. The planner decides.
* A suggestion is **forgotten** the moment the stops, their places, their windows or the branch change (a fingerprint of those); it cannot be applied stale, and the
  property that holds it cannot be written from the browser. Applying checks that every stop is there exactly once.

## Not built (and the owner's decisions)

* **Assigning leads by territory** (a new lead goes to its territory's owner), **territory targets**, **territory reports**, a **coverage-gap list** (leads no territory covers).
  Decide whether territory should *move* work or only *advise*; today it only advises.
* **A map** to draw or pick coverage; blocks, PIN codes and customers carry **no coordinates** of their own, so suggestions use the coordinates typed on each stop.
* **Suggesting stops** (which customers to visit in a territory today) — only the order of stops the planner chose.
* **Road distances and travel times** (a provider), and **traffic**.
* **GPS consent and purpose.** The brief asks that GPS capture be consented, purpose-limited and visible to authorised roles. Visibility exists (attendance and the
  violation log); there is **no recorded consent or purpose notice** for a salesperson's position yet — that is an owner decision (what to tell people, whether to
  record acceptance).
* **Importing** territories from a spreadsheet, and **history** of who owned a territory over time (the audit trail shows changes; there is no dated ownership).
