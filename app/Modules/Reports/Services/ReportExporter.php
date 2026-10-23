<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Exports\ReportSheet;
use App\Modules\Reports\Jobs\GenerateReportExport;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Exports of reports. Asking for one is checked now (may this person export this report; are the filters theirs to use; is it small enough),
 * the file is made later by a queue worker **as that person** — so the same access rules narrow it as narrow the screen — and kept on a
 * private disk for a few days. It is handed over only to the person who asked, and only while they may still export that report.
 */
final class ReportExporter
{
    /** The most rows an Excel export holds; past it the person narrows the filters. */
    public const MAX_ROWS = 50000;

    /** A PDF is a print-out, not a data dump: the first rows only. */
    public const PDF_ROWS = 1000;

    public const KEEP_DAYS = 7;

    /** How many exports one person may have waiting or running at once (and for how long a stuck one counts): one person cannot flood the queue. */
    public const MAX_WAITING = 5;

    public const WAITING_WINDOW_MINUTES = 60;

    public const DISK = 'local';

    public function __construct(private readonly ReportCatalog $catalog, private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $filters  as typed; they are checked again when the file is made
     *
     * @throws AuthorizationException when the person may not export this report
     * @throws InvalidReportFilters
     */
    public function request(Report $report, array $filters, string $format, User $user, ?int $scheduleId = null): ReportExport
    {
        if (! $report->canExport($user)) {
            throw new AuthorizationException('You may not export this report.');
        }

        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw new InvalidReportFilters(['format' => 'Choose Excel or PDF.']);
        }

        $input = ReportInput::resolve($report, $filters, $user);

        if ($format === 'xlsx' && ($rows = $report->count($input, $user)) > self::MAX_ROWS) {
            throw new InvalidReportFilters(['filters' => 'This report has '.number_format($rows).' rows; an Excel export holds at most '.number_format(self::MAX_ROWS).'. Narrow the dates, branch or person.']);
        }

        $waiting = ReportExport::query()->where('user_id', $user->getKey())->whereIn('status', [ReportExport::QUEUED, ReportExport::RUNNING])
            ->where('created_at', '>=', now()->subMinutes(self::WAITING_WINDOW_MINUTES))->count();

        if ($waiting >= self::MAX_WAITING) {
            throw new InvalidReportFilters(['filters' => 'You already have '.$waiting.' exports waiting to be made. Wait for them to finish under “My exports”, then ask again.']);
        }

        $export = new ReportExport;
        $export->forceFill([
            'company_id' => $this->tenant->activeCompanyId(), 'user_id' => $user->getKey(), 'report' => $report->key(), 'format' => $format,
            'status' => ReportExport::QUEUED, 'filters' => $input->toArray(), 'schedule_id' => $scheduleId,
        ])->save();

        GenerateReportExport::dispatch($export->id, (int) $export->company_id);

        return $export;
    }

    /**
     * Make the file. Runs in the queue worker, inside the export's company, signed in as the person who asked — never as "nobody", which
     * would see every branch. Safe to run twice: only a queued export starts.
     */
    public function generate(ReportExport $export): void
    {
        $claimed = ReportExport::query()->whereKey($export->id)->where('status', ReportExport::QUEUED)
            ->update(['status' => ReportExport::RUNNING, 'started_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $export->refresh();
        $previous = Auth::user();
        $user = User::query()->find($export->user_id);

        try {
            $report = $this->catalog->find($export->report);

            if ($user === null || $report === null || ! $user->is_active || ! $this->tenant->userCanAccessCompany($user, (int) $export->company_id) || ! $report->canExport($user)) {
                throw new AuthorizationException('The person who asked may no longer export this report.');
            }

            Auth::setUser($user);
            $this->tenant->flush();

            $input = ReportInput::resolve($report, $export->filters, $user);
            $path = 'report-exports/'.$export->company_id.'/'.$export->id.'-'.Str::slug($report->key()).'.'.$export->format;
            $count = $export->format === 'pdf' ? $this->makePdf($report, $input, $user, $path) : $this->makeSpreadsheet($report, $input, $user, $path);

            $export->forceFill([
                'status' => ReportExport::READY, 'file_path' => $path, 'row_count' => $count, 'file_size' => Storage::disk(self::DISK)->size($path),
                'finished_at' => now(), 'expires_at' => now()->addDays(self::KEEP_DAYS), 'error' => null,
            ])->save();

            // a scheduled report goes to its owner as soon as it is ready; a mail that fails never undoes the export
            if ($export->schedule_id !== null) {
                app(ReportScheduler::class)->deliver($export, (int) $export->schedule_id);
            }
        } catch (Throwable $e) {
            $this->fail($export, $e);
        } finally {
            $previous === null ? Auth::forgetGuards() : Auth::setUser($previous);
            $this->tenant->flush();
        }
    }

    /** Mark an export failed with a sentence a person can read — never a stack trace, a query or a path. */
    public function fail(ReportExport $export, Throwable $e): void
    {
        $export->forceFill([
            'status' => ReportExport::FAILED, 'finished_at' => now(),
            'error' => $e instanceof AuthorizationException || $e instanceof InvalidReportFilters ? mb_substr($e->getMessage(), 0, 400) : 'The export could not be made. Try again, or narrow the filters.',
        ])->save();

        if (! $e instanceof AuthorizationException && ! $e instanceof InvalidReportFilters) {
            report($e);
        }
    }

    /** Remove the files that have passed their time (the rows stay as a record, marked expired). */
    public function expire(): int
    {
        $count = 0;

        $this->tenant->withoutTenancy(function () use (&$count) {
            ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('status', ReportExport::READY)->where('expires_at', '<', now())->orderBy('id')
                ->chunkById(200, function ($exports) use (&$count) {
                    foreach ($exports as $export) {
                        $export->file_path === null || Storage::disk(self::DISK)->delete($export->file_path);
                        $export->forceFill(['status' => ReportExport::EXPIRED, 'file_path' => null])->save();
                        $count++;
                    }
                });
        });

        return $count;
    }

    private function makeSpreadsheet(Report $report, ReportInput $input, User $user, string $path): int
    {
        $count = 0;
        $rows = (function () use ($report, $input, $user, &$count) {
            foreach ($report->rows($input, $user, self::MAX_ROWS) as $row) {
                $count++;

                yield $row;
            }
        })();

        Excel::store(new ReportSheet($report->title(), $report->columns(), $rows), $path, self::DISK);

        return $count;
    }

    private function makePdf(Report $report, ReportInput $input, User $user, string $path): int
    {
        $rows = iterator_to_array($report->rows($input, $user, self::PDF_ROWS + 1), false);
        $truncated = count($rows) > self::PDF_ROWS;
        $rows = array_slice($rows, 0, self::PDF_ROWS);

        $pdf = Pdf::loadView('reports.pdf', [
            'title' => $report->title(), 'columns' => $report->columns(), 'rows' => $rows, 'summary' => $report->summary($input, $user), 'note' => $report->note(),
            'company' => (string) Company::withoutGlobalScopes()->whereKey($this->tenant->activeCompanyId())->value('name'),
            'filters' => $this->describe($report, $input), 'madeAt' => now()->format('d M Y H:i'), 'madeBy' => $user->name,
            'truncated' => $truncated, 'limit' => self::PDF_ROWS,
        ])->setPaper('a4', 'landscape')->setOption('isRemoteEnabled', false)->setOption('defaultFont', 'DejaVu Sans')->output();

        Storage::disk(self::DISK)->put($path, $pdf);

        return count($rows);
    }

    /** The filters in words, for the head of a printout. */
    public function describe(Report $report, ReportInput $input): string
    {
        $parts = [];

        if ($input->from !== null || $input->to !== null) {
            $parts[] = $report->dateLabel().': '.($input->from ?? 'start').' to '.($input->to ?? 'today');
        }

        $input->choice === null || $parts[] = $report->choiceLabel().': '.($report->choices()[$input->choice] ?? $input->choice);
        $input->branch === null || $parts[] = 'one branch';
        $input->peopleIds === null || $parts[] = 'chosen people';
        $input->source === null || $parts[] = 'one source';

        return $parts === [] ? 'no filters' : implode(', ', $parts);
    }
}
