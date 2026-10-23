<?php

namespace App\Modules\Shared\Imports;

/**
 * One kind of spreadsheet the system can ingest. The framework (upload, template, check, queued apply, error
 * report, progress) is generic; an importer only says what its columns are and what a row means.
 *
 * Rules every importer must keep:
 *  - idempotent: loading the same file twice changes nothing the second time;
 *  - a blank cell never erases existing data;
 *  - never touches another company's data (the session runs inside the batch's company context);
 *  - the check and the apply share one code path (`ImportSession::process`), so what the preview promises is
 *    what the import does.
 */
interface Importer
{
    /** Stable key stored on the batch and used in URLs, e.g. "geography". */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** Permission needed to download the template and run the import, e.g. "geography.import". */
    public function permission(): string;

    /** @return list<ImportColumn> */
    public function columns(): array;

    /**
     * Plain-language rules, shown in the wizard and on the template's Instructions sheet.
     *
     * @return list<string>
     */
    public function rules(): array;

    /**
     * Example rows for the Instructions sheet only (never in the Data sheet, so nobody imports them by accident).
     *
     * @return list<array<string, string>> keyed by column key
     */
    public function examples(): array;

    /**
     * Names for the importer-specific counters, shown in the result.
     *
     * @return array<string, string> counter key => label
     */
    public function counterLabels(): array;

    /**
     * Start one pass over a file for a company.
     *
     * `$userId` is the person who uploaded the file — the same for the check and for the import, so both can apply
     * that person's reach (their branches, the people they may assign to). `$options` are the choices made on the upload
     * screen for importers that have any (a default branch, a default owner); `_batch_id` is always present.
     *
     * @param  array<string, mixed>  $options
     */
    public function begin(int $companyId, ?int $userId = null, array $options = []): ImportSession;
}
