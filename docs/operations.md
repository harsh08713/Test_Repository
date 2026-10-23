# Operations: running, backing up, restoring and securing FormEzy ERP

Phase 20, first slice. What was **run and verified** is marked so; what is **only advice** or **not done** is said plainly in the last two sections.

## 1. What must be running

| Piece | How | If it stops |
|---|---|---|
| Web server | PHP 8.3 (Apache / nginx + PHP-FPM) with the web root at `public/` over HTTPS | nothing works |
| Database | MariaDB 10.4+ / MySQL 8, InnoDB | nothing works |
| **Scheduler** | cron, every minute: `* * * * * php /path/artisan schedule:run` | reminders, SLA checks, marketing, report schedules, maintenance and attendance jobs, backups stop. **System health** (`/admin/health`) shows whether it is alive |
| **Queue worker** | `php artisan queue:work --tries=3` under Supervisor / systemd / a Windows service; `QUEUE_CONNECTION=database` or `redis` | e-mails, exports and imports wait in the queue |
| Cache every process shares | `CACHE_STORE=database` or `redis` (not `array`) | the one-lead-per-buyer lock and rate limits stop working across processes |
| Mail | a real `MAIL_*` transport | no e-mail leaves |

Deployment steps are in [README.md](../README.md#deployment). After each release: `php artisan migrate --force`, re-run `RolePermissionSeeder` if permissions were added, `php artisan optimize`, restart the queue worker (`php artisan queue:restart`).

## 2. Backups (built and verified)

`php artisan formezy:backup` writes a **gzip dump of the whole database** to `storage/app/backups/<database>-<date>-<time>.sql.gz` and keeps the newest 14 (`BACKUP_KEEP`).

* One consistent snapshot (`mysqldump --single-transaction`, with routines and triggers, UTF-8, binary columns as hex) — the application can keep running while it is made.
* The database password is given to the tool through its environment, never on a command line, and is never written to a log or a message.
* `storage/app/backups` is **not** served by the web server. The dump holds everything (customers, staff, pay-related details when payroll exists): **copy it off the server** (another machine or object storage), encrypt that copy, and limit who can read it. Nothing here does the off-site copy for you.
* Turn on the schedule with `BACKUP_ENABLED=true`: a nightly backup at 02:15 and, on Sundays at 03:00, a backup **with proof** (below). If `mysqldump` / `mysql` are not on the PATH, set `BACKUP_MYSQLDUMP` and `BACKUP_MYSQL` to their full paths (XAMPP: `C:/xampp/mysql/bin/mysqldump.exe`).
* What a backup does **not** hold: uploaded files (`storage/app/private`: employee papers, résumés, report exports, attachments) and `.env`. Back those up too (a file copy).

## 3. Proving a backup restores (built and verified)

A backup nobody has restored is a hope. `php artisan formezy:restore-check` (or `formezy:backup --verify`, which does both):

1. creates a scratch database next to the live one (`<database>_restorecheck`),
2. loads the newest backup (or `--file=`) into it with the real `mysql` program,
3. compares it with the live database **table by table**: the number of columns and of rows,
4. drops the scratch database again — whatever happens.

Verdicts per table: **OK** (same rows); **WARN** (fewer rows restored than live: the live database has grown since the backup — normal; more rows restored: some were removed since — look at it); **FAIL** (a table missing from the restore, a different number of columns, or restored empty although the live table has rows); sessions, cache and queue tables are listed but not judged. Exit code 1 and the words `RESTORE CHECK FAILED` on any FAIL.

**Verified here** (20 October 2026, the demo database): 167 tables restored in about 10 seconds, 160 identical, 7 volatile skipped, 0 warnings, 0 failures. The automated tests (`tests/Concurrency/BackupRestoreTest.php`) also prove a missing table is caught as a FAIL, a file that is not a backup is refused, and the scratch database never stays behind.

This proves the **dump restores into a database with the same tables and rows**. It does not prove the application then runs on it — after a real restore, sign in and open the main screens (see below).

## 4. Restoring for real (disaster recovery)

1. Stop the web server, the queue worker and the scheduler (so nothing writes while you restore).
2. Keep the damaged database if you can: rename it (`create database industrial_crm_old; ...`) or dump it, so nothing is lost for good.
3. Create an empty database with the same name, character set `utf8mb4` and collation `utf8mb4_unicode_ci`.
4. Load the backup: `gunzip -c industrial_crm-YYYYMMDD-HHMMSS.sql.gz | mysql -u root -p industrial_crm` (on Windows: `php -r "..."` is not needed; use 7-Zip to unpack, then `mysql ... < file.sql`).
5. Restore `storage/app/private` from its file backup.
6. `php artisan migrate --status` (nothing should be pending if the backup is from this release; otherwise `php artisan migrate --force`), then `php artisan optimize:clear`.
7. Start the scheduler and the worker; sign in; open **System health** (`/admin/health`), the dashboard, one lead, one invoice and the stock screen; run `php artisan formezy:restore-check` is *not* needed here — instead compare a few counts with what you expect.
8. Everything done between the backup and the failure is lost. Say so to the people who worked in that time.

Targets to agree with the owner (not decided): how much work may be lost (the backup interval: nightly = up to a day), and how long the system may be down while restoring.

## 5. Security review

**Verified (a test or a command shows it):**

* Dependencies: `composer audit` and `npm audit --omit=dev` found **no advisories** on 20 October 2026; CI runs both on every push.
* **Headers on every response**, pages, API, errors and unknown paths (`tests/Feature/SecurityHeadersTest.php`): no content-type sniffing, no framing by other sites, a short referrer, a permission policy that leaves only location (the field-sales check) and denies camera, microphone, payment and USB; HSTS on an HTTPS request in production only.
* Sign-in: the web form allows 5 attempts and then makes the person wait; the API login has its own limit; a password needs 10+ characters with upper and lower case and a number, and in production must not be in a known breach.
* Authorisation: every record goes through a policy built on the permission matrix; there is **no** super-admin bypass (Super Admin holds every permission explicitly, and pending/approved documents are locked for it too); a record of another company or branch is a 404/403 (tenancy tests, and each module's "other company" test).
* Secrets: provider keys and signing secrets are encrypted company settings, never shown again, never in the audit log or an error message; bank account numbers are encrypted and masked; employee papers and résumés are on the private disk and leave it only through a controller that checks the right every time.
* Uploads are checked for type and size on the server; spreadsheets are read as inert text.
* Webhooks: an unknown or switched-off address is a plain 404; providers' signatures are checked where they can sign.
* Concurrency: money, stock, numbers and approvals are protected by row locks and unique keys, with real-process tests that fail when a safeguard is removed.

**Settings you must make in production (advice, not enforced):** `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, `SESSION_SECURE_COOKIE=true` (the cookie then travels only over HTTPS), `APP_URL` as the public HTTPS address, `QUEUE_CONNECTION` and `CACHE_STORE` not `sync`/`array`, a database user with only the rights the application needs (not `root`), and the web root at `public/` so `.env` and `storage` are never reachable.

**Deliberately not done:** a **Content-Security-Policy** — Livewire and Alpine evaluate expressions inside the page, so a policy strict enough to matter would break them; the other headers above are what can be done without that.

## 6. Not done (be honest about it before launch)

* **No load or soak test** has been run; page speed on a large company's data is unmeasured. List screens are paged and the common filters are indexed, but nothing here proves it at volume. Do this before the launch with realistic data.
* **No two-factor sign-in**, no password expiry, no IP allow-list, no lockout beyond the throttle.
* **No off-site backup**, no encryption of the backup file, no alert when a backup or its weekly proof fails (it shows in the scheduler output / logs only; watch `storage/logs`).
* **No malware scan** of uploads.
* **No automated dependency updates**; `composer audit` / `npm audit` run in CI, but someone must act on them.
* **Restore of uploaded files** is by file copy only; there is no tool for it.
* The CI pipeline (`.github/workflows/ci.yml`) is written but **has not been run on GitHub** (this project has no repository here): the same steps were run by hand on this machine.
