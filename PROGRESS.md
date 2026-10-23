# Build Progress — FormEzy + Loss Intelligence ERP

> Claude Code: read this at the start of every session. Update it at the end of every phase (status, what was built, test results, open issues). Never mark a phase **Done** unless its tests have been run and pass.

**Current phase:** 1 — Foundation
**Last updated:** _(date)_ by _(name / Claude Code)_

---

## Phase tracker

| # | Phase | Status | Tests | Notes |
|---|---|---|---|---|
| 1 | Foundation — install, auth, layout, roles, tenancy, number series, approval engine, comments, attachments, audit | ⬜ Not started | – | |
| 2 | Core masters — Company, CoA, Location, Department, Designation, Unit, Item Group, Brand, geography + Tent Studio import | ⬜ Not started | – | |
| 3 | Ledger master + approvals | ⬜ Not started | – | |
| 4 | Item master — 3-stage workflow | ⬜ Not started | – | |
| 5 | Rate master + RateService | ⬜ Not started | – | |
| 6 | Industry template engine + 15 seeds | ⬜ Not started | – | |
| 7 | Sales team, targets, route plan, attendance | ⬜ Not started | – | |
| 8 | New lead — manual, import, follow-ups, calendar, transfer | ⬜ Not started | – | |
| 9 | Mobile API + geofencing | ⬜ Not started | – | |
| 10 | IndiaMART / Just Dial sync | ⬜ Not started | – | |
| 11 | Existing lead & deal | ⬜ Not started | – | |
| 12 | Quotation | ⬜ Not started | – | |
| 13 | Sales order → auto invoice; returns/credit notes | ⬜ Not started | – | |
| 14 | Inventory & procurement | ⬜ Not started | – | |
| 15 | Loss management — incidents, impact, workflow, RCA, CAPA | ⬜ Not started | – | |
| 16 | Finance & costing | ⬜ Not started | – | |
| 17 | Analytics & dashboards | ⬜ Not started | – | |
| 18 | Industry pilots (4) → remaining 11 templates | ⬜ Not started | – | |
| 19 | Reports & exports | ⬜ Not started | – | |
| 20 | Production readiness | ⬜ Not started | – | |

Status legend: ⬜ Not started · 🟨 In progress · ✅ Done · ⛔ Blocked

---

## Decisions & assumptions (from CLAUDE.md §14)

| # | Decision | Assumption used | Confirmed by client? |
|---|---|---|---|
| 1 | Approval levels | Single level per document, configurable | ☐ |
| 2 | Item approver | Accounts (Operations comment-only) | ☐ |
| 3 | IndiaMART / Just Dial | Pull API on a 15-min schedule; credentials per company in Settings | ☐ |
| 4 | Geofence | 500 m radius; no manager override | ☐ |
| 5 | Sales invoice scope | Document + analytics; no GL postings yet | ☐ |
| 6 | Costing method | Moving average | ☐ |
| 7 | Mobile client | Flutter app consuming `/api/v1` | ☐ |
| 8 | UI theme | Lookalike Bootstrap 5 components (no Metronic licence assumed) | ☐ |

---

## Phase log

Copy this block for each completed phase.

```
### Phase N — <name>  (YYYY-MM-DD)
Built:
- …
Files / migrations added:
- …
Commands:
- php artisan migrate --seed
- php artisan test --filter=…
Test result:  X passed, 0 failed   (paste real output summary)
PHPStan: level 6, 0 errors   |   Pint: clean
Open issues / follow-ups:
- …
```

---

## Environment checklist

- [ ] PHP 8.3+ and Composer installed
- [ ] MySQL 8 database created; `.env` → `DB_*` set
- [ ] `php artisan key:generate`
- [ ] Queue worker running: `php artisan queue:work`
- [ ] Scheduler cron: `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1`
- [ ] `npm install && npm run build` (Bootstrap, Alpine, Chart.js, FullCalendar)
- [ ] `.env` keys present (values not committed): `INDIAMART_*`, `JUSTDIAL_*`, `MAIL_*`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`
