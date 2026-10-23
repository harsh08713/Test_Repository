# Spreadsheet imports

One framework, used for the Tent Studio **geography** file (Masters → Geography → Import) and for **leads**
(CRM → Leads → Import from Excel; its columns and rules are in [leads.md](leads.md)). It lives in
`app/Modules/Shared/Imports`; an importer only describes its columns and what a row means.

## For the person importing

1. **Download the template** (`Data` sheet = headings only; `Instructions` sheet = every column, the rules and examples
   that are *not* imported). A `.csv` with the same headings also works and is much faster for very large files.
2. **Upload** the filled file (≤ 10 MB, ≤ 200,000 rows). It is opened and checked immediately; **nothing is saved yet**.
3. **Review the check**: rows read / new / updated / no change / rejected, importer-specific totals, a preview of the first
   25 rows ("what will happen"), and the rejected rows with the exact spreadsheet row number and reasons.
   *Download error report* gives an `.xlsx` with the same columns as the template plus **Row** and **Problem**, ready to fix
   and upload again.
4. **Confirm.** Valid rows are imported in the background; rejected rows are skipped and stay in the report.

Re-uploading a corrected file, or the whole file again, is always safe: the import is idempotent.

### When the file's headings are not the template's

Headings are matched ignoring case, spacing and punctuation, in any order, and an importer can name **alternatives** for a
column (the lead import reads "Company Name" as *Name*, "Mobile No" as *Mobile*, "Pincode" as *PIN Code*…). After the check,
**Columns in your file** lists every heading of the file with the field it was read as. Change one, or set it to *Ignore
this column*, and press **Check again with these columns**. This also rescues a file the check could not start on
("Missing: Name, Mobile"): its headings are listed so they can be mapped. Two columns cannot feed the same field. Extra
columns are simply ignored (and listed).

Some imports ask a few questions on the upload screen — the lead import asks for the branch, source and owner to use for
rows that do not name their own. The answers are checked when the file is uploaded and kept with it.

### Geography columns (Tent Studio)

| Column | Required | Notes |
|---|---|---|
| State | yes | Name as in *Masters → Geography → States*; the 2-letter code (`GJ`) or GST code (`24`) also works. States are **never created** by an import. |
| District | yes | Created if missing (matched within the state). |
| Block | one of Block / PIN | Created if missing (matched within the district). |
| PIN Code | one of Block / PIN | 6 digits, first digit 1–9. |
| Locality | no | Post office / locality; needs a PIN. One PIN can have many localities (one row each). |

Tent Studio's own file layout has not been supplied, so these headings are ours. Headings are matched ignoring case,
spacing and punctuation, in any order; extra columns are ignored (and listed in the check).

### Rules the geography import follows

- A PIN row is identified by **PIN + district + locality**. Names match ignoring capitals, accents and extra spaces
  (the same way the database's unique indexes do), so `SURAT`, ` surat ` and `Sürat` are the existing district.
- **Idempotent**: the same file twice changes nothing the second time; the wizard says "nothing to import".
- **A blank Block cell never erases** a recorded block. A different, non-blank block replaces it (shown as an update).
- **Deactivated** districts, blocks and PIN rows are never reactivated: rows touching them are rejected with a message.
  **Deleted** (soft-deleted) ones are restored, because their unique key would otherwise block the row.
- The same PIN + district + locality twice in one file must agree on the block; the later contradicting row is rejected,
  and an exact repeat is counted as "repeated" and skipped.
- A later row sees what earlier rows of the same file would create, so a file may introduce a district and its blocks
  and PIN codes together.

## For developers

```
app/Modules/Shared/
  Imports/    Importer · ImportSession · CommitsInBatches · FinishesImport · ImportColumn · RowOutcome · RowStatus · ImporterRegistry
              SpreadsheetReader (xlsx chunks / streamed csv) · ImportRunner (state machine + the pass over the file)
  Jobs/       ProcessImportBatch (RunsInCompanyContext)
  Models/     ImportBatch · ImportBatchError        Policies/ImportBatchPolicy
  Exports/    ImportTemplateExport (+ Data / Instructions sheets) · ImportErrorReportExport
  Http/       Livewire/ImportWizard (abstract) · Controllers/ImportDownloadController
app/Modules/Masters/
  Imports/    GeographyImporter · GeographyImportSession      Http/Livewire/Geography/GeographyImport
app/Modules/CRM/
  Imports/    LeadImporter · LeadImportSession                Http/Livewire/Leads/LeadImport
```

**Adding an importer**: implement `Importer` (key, label, permission, columns, rules, examples, counter labels, `begin()`)
and `ImportSession` (`prepareChunk`, `process`, `counters`); register it in a module provider
(`app(ImporterRegistry::class)->register(new …)`); add a one-class Livewire page extending `ImportWizard` and a route.
`process($rowNumber, $cells, $apply, $userId)` must behave identically for `$apply = false` (the check) and `true`
(the import) apart from writing — that is what makes the preview trustworthy. Plan the whole row first, write, and only
then update in-memory maps, so a row that fails halfway leaves nothing behind.

- **Alternative headings**: `new ImportColumn('mobile', 'Mobile', true, '…', aliases: ['Mobile No', 'Phone', 'Contact No'])`.
  The template still shows the one official heading.
- **Column mapping** needs nothing from the importer: the runner records the file's headings (`summary.headings`) and how
  each was read (`summary.mapping`), the wizard lets the person change it (`summary.column_map`, through
  `ImportRunner::mapColumns()`), and the next check reads the file that way. What the person chose wins over aliases.
- **Choices on the upload screen**: override `optionsView()` (a Blade partial inside the upload form), `optionsData()` and
  `options()` on the page. `options()` returns the validated choices (throw a `ValidationException` to refuse the upload);
  they are stored with the batch and arrive in `Importer::begin(int $companyId, ?int $userId, array $options)` together with
  `_batch_id`. **Treat them as a request, not a fact**: the import runs later, in a job where nobody is signed in, so the
  session must apply the uploader's own reach to every row (see `LeadImportSession`).
- **Rows that take a lock other work waits on**: a chunk's transaction holds every lock its rows take until it commits.
  Implement `CommitsInBatches::commitEvery()` to commit a few rows at a time instead. The lead import does (50): each
  lead takes its branch's number series, a lead takes about 25 ms to import, and a 2,000-row chunk in one transaction
  would keep anyone typing a lead into that branch waiting close to a minute — longer than the database's lock wait.
  Reading the file is unaffected.
- **Something to do once the import is over** (telling people what they were given): implement `FinishesImport::finished()`
  on the session. It runs once, after the last chunk of a real import; it must not write business data and cannot undo
  the import.
- **In the browser a Livewire action cannot be called `upload`** (nor `set`, `get`, `call`, `dispatch`, `watch`…): Livewire's
  own `$wire.upload` is run instead and the button does nothing. The wizard's action is `uploadAndCheck()`;
  `tests/Feature/LivewireConventionsTest.php` keeps every component clear of the reserved names.

**State machine** (every transition is one atomic conditional `UPDATE`, so double clicks and duplicate queue
deliveries cannot start a phase twice):

```
uploaded → checking → checked → queued → processing → completed
              ↘ failed ↙                      ↘ failed   (retry is safe: apply is idempotent)
any idle state → cancelled
```

- **Apply** runs in chunks (`formezy.imports.chunk_rows`, default 2,000), each chunk in one transaction — or, for an
  importer that implements `CommitsInBatches`, a few rows per transaction — and each row in a savepoint; a unique-key
  clash on one row rejects that row only. A bulk import writes **one** audit entry
  (`import_completed`, with counts) instead of one per created row; the batch, its counts and every rejected row are kept.
- **One import per company and kind** may be queued/running at a time.
- **Tenancy**: the job runs through `RunsInCompanyContext`; the batch, the downloads and the wizard actions go through the
  tenant-scoped query and `ImportBatchPolicy` (which asks the importer for its permission).
- **Files** are stored on the private disk under `imports/{company}/{ulid}.{ext}`; they are checked on upload (a real
  xlsx with a workbook, no NUL bytes in a csv, unpacked size ≤ `max_unpacked_mb`) and only cell *values* are read
  (no formulas evaluated). The error report writes everything as text so a `=HYPERLINK(…)` in an uploaded file stays inert.
- **Config**: `config/formezy.php` → `imports` (disk, `max_file_kb`, `max_unpacked_mb`, `max_rows`, `chunk_rows`, `preview_rows`).
- **Queue**: production needs a worker (`php artisan queue:work`); local development can use `QUEUE_CONNECTION=sync`.
  A batch whose worker died can be freed with *Mark as failed* after 30 minutes without progress.

### Known limits

- Uploaded files are kept (they back the audit trail); a retention/prune job is not built yet.
- `.xlsx` is re-read per chunk to keep memory flat, which is slow beyond ~50,000 rows; use CSV for very large loads.
- A very large import has not been benchmarked against a full national PIN directory (~150,000 rows).
