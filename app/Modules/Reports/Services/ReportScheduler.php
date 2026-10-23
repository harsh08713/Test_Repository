<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Notifications\ScheduledReportNotice;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Support\RelativeRange;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reports sent to a person on a schedule — the one door.
 *
 *   - A person schedules only a report they may **export** (the right is checked again every time it runs), at most ten, with the filters they could pick on the screen and a **relative** date range.
 *   - `runDue()` (every fifteen minutes) takes each due schedule with a conditional UPDATE of `next_run_at` — so two overlapping runs cannot both send — then asks `ReportExporter` for the file as that
 *     person; the file is e-mailed to them when it is ready (`deliver()`), and also stays under "My exports".
 *   - A schedule whose owner has left, been switched off, or lost the right to export **pauses itself and says why** (once); one that fails for another reason tells the owner and keeps its place.
 */
final class ReportScheduler
{
    public function __construct(private readonly ReportCatalog $catalog, private readonly ReportExporter $exporter, private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input  report, format, frequency, weekday, month_day, send_hour, date_range, filters (branch, person, team, source, choice)
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function create(array $input, User $user): ReportSchedule
    {
        $report = $this->catalog->find((string) ($input['report'] ?? ''));

        if ($report === null || ! $report->canExport($user)) {
            throw new AuthorizationException('You may not schedule that report.');
        }

        $errors = [];
        $format = (string) ($input['format'] ?? 'xlsx');
        $frequency = (string) ($input['frequency'] ?? 'weekly');
        $weekday = (int) ($input['weekday'] ?? 1);
        $monthDay = (int) ($input['month_day'] ?? 1);
        $hour = (int) ($input['send_hour'] ?? 7);
        $range = (string) ($input['date_range'] ?? 'none');
        $filters = is_array($input['filters'] ?? null) ? array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $input['filters']), fn ($v) => $v !== '') : [];

        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            $errors['format'] = 'Choose Excel or PDF.';
        }

        if (! array_key_exists($frequency, ReportSchedule::FREQUENCIES)) {
            $errors['frequency'] = 'Choose how often.';
        }

        if ($frequency === 'weekly' && ($weekday < 1 || $weekday > 7)) {
            $errors['weekday'] = 'Choose the day of the week.';
        }

        if ($frequency === 'monthly' && ($monthDay < 1 || $monthDay > 28)) {
            $errors['month_day'] = 'Choose a day from 1 to 28, so every month has it.';
        }

        if ($hour < 0 || $hour > 23) {
            $errors['send_hour'] = 'Choose the hour, 0 to 23.';
        }

        if (! array_key_exists($range, RelativeRange::RANGES)) {
            $errors['date_range'] = 'Choose a date range.';
        } elseif ($range !== 'none' && ! in_array('date', $report->filters(), true)) {
            $errors['date_range'] = 'This report has no date to narrow.';
        }

        if (ReportSchedule::query()->where('user_id', $user->id)->count() >= ReportSchedule::MAX_PER_PERSON) {
            $errors['report'] = 'You already have '.ReportSchedule::MAX_PER_PERSON.' scheduled reports: remove one first.';
        }

        // the filters must be ones this person could have chosen; checked now with today's dates, and again every time it runs
        if ($errors === []) {
            try {
                ReportInput::resolve($report, $this->withDates($filters, $range, CarbonImmutable::today()), $user);
            } catch (InvalidReportFilters $e) {
                $errors = $e->problems;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $schedule = new ReportSchedule;
        $schedule->forceFill([
            'company_id' => $this->tenant->activeCompanyId(), 'user_id' => $user->id, 'report_key' => $report->key(), 'format' => $format, 'frequency' => $frequency,
            'weekday' => $frequency === 'weekly' ? $weekday : null, 'month_day' => $frequency === 'monthly' ? $monthDay : null, 'send_hour' => $hour, 'date_range' => $range, 'filters' => $filters,
            'next_run_at' => $this->nextRun($frequency, $weekday, $monthDay, $hour, CarbonImmutable::now()), 'active' => true,
        ])->save();

        return $schedule;
    }

    public function setActive(ReportSchedule $schedule, bool $active, User $user): ReportSchedule
    {
        if ($schedule->user_id !== $user->id) {
            throw new AuthorizationException('That is not your scheduled report.');
        }

        $schedule->forceFill($active
            ? ['active' => true, 'paused_reason' => null, 'next_run_at' => $this->nextRun($schedule->frequency, (int) $schedule->weekday, (int) $schedule->month_day, $schedule->send_hour, CarbonImmutable::now())]
            : ['active' => false, 'paused_reason' => 'Paused by you.'])->save();

        return $schedule;
    }

    /** Make and send it now, as well as at its times (the next scheduled time is unchanged). The exporter's cap on waiting exports still applies. */
    public function sendNow(ReportSchedule $schedule, User $user): void
    {
        if ($schedule->user_id !== $user->id) {
            throw new AuthorizationException('That is not your scheduled report.');
        }

        $this->runOne($schedule, CarbonImmutable::now());
    }

    public function remove(ReportSchedule $schedule, User $user): void
    {
        if ($schedule->user_id !== $user->id) {
            throw new AuthorizationException('That is not your scheduled report.');
        }

        $schedule->delete();
    }

    /** The first moment after `$after` at which a schedule is due. */
    public function nextRun(string $frequency, int $weekday, int $monthDay, int $hour, CarbonImmutable $after): CarbonImmutable
    {
        $at = fn (CarbonImmutable $day) => $day->setTime($hour, 0);

        if ($frequency === 'daily') {
            $candidate = $at($after->startOfDay());

            return $candidate->gt($after) ? $candidate : $at($after->startOfDay()->addDay());
        }

        if ($frequency === 'weekly') {
            $candidate = $at($after->startOfDay()->addDays(($weekday - $after->dayOfWeekIso + 7) % 7));

            return $candidate->gt($after) ? $candidate : $candidate->addWeek();
        }

        $candidate = $at($after->startOfMonth()->addDays($monthDay - 1));

        return $candidate->gt($after) ? $candidate : $at($after->startOfMonth()->addMonth()->addDays($monthDay - 1));
    }

    /**
     * The schedules that are due: each is taken once, then made and sent as its owner.
     *
     * @return int schedules started
     */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $started = 0;

        $due = $this->tenant->withoutTenancy(fn () => ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->where('active', true)->where('next_run_at', '<=', $now)->orderBy('id')->limit(200)->get());

        foreach ($due as $schedule) {
            $next = $this->nextRun($schedule->frequency, (int) $schedule->weekday, (int) $schedule->month_day, $schedule->send_hour, $now);

            $claimed = ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($schedule->id)->where('active', true)->where('next_run_at', $schedule->next_run_at)
                ->update(['next_run_at' => $next, 'last_run_at' => $now]);

            if ($claimed === 0) {
                continue;   // another run took it
            }

            $started++;
            $this->tenant->withCompany((int) $schedule->company_id, fn () => $this->runOne($schedule->fresh() ?? $schedule, $now));
        }

        return $started;
    }

    /** Make one schedule's file as its owner. */
    public function runOne(ReportSchedule $schedule, CarbonImmutable $today): void
    {
        $user = User::query()->find($schedule->user_id);
        $report = $this->catalog->find($schedule->report_key);

        if ($user === null || $report === null || ! $user->is_active || ! $this->tenant->userCanAccessCompany($user, (int) $schedule->company_id) || ! $report->canExport($user)) {
            $this->pause($schedule, $user, $report, 'You can no longer export this report (or the report is gone).');

            return;
        }

        $previous = Auth::user();
        Auth::setUser($user);
        $this->tenant->flush();

        try {
            $this->exporter->request($report, $this->withDates($schedule->filters, $schedule->date_range, $today->startOfDay()), $schedule->format, $user, $schedule->id);
        } catch (InvalidReportFilters $e) {
            $this->tell($user, 'scheduled_report_failed', 'Your scheduled report could not be made: '.$report->title(), implode(' ', array_values($e->problems)).' It will be tried again at the next time.', route('reports.schedules'));
        } catch (AuthorizationException) {
            $this->pause($schedule, $user, $report, 'You can no longer export this report.');
        } catch (Throwable $e) {
            report($e);
            $this->tell($user, 'scheduled_report_failed', 'Your scheduled report could not be made: '.$report->title(), 'It will be tried again at the next time.', route('reports.schedules'));
        } finally {
            $previous === null ? Auth::forgetGuards() : Auth::setUser($previous);
            $this->tenant->flush();
        }
    }

    /** The file is ready: send it to its owner. Called by the exporter; a mail that cannot be sent never undoes the export. */
    public function deliver(ReportExport $export, int $scheduleId): void
    {
        $schedule = ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->find($scheduleId);
        $user = User::query()->find($export->user_id);
        $report = $this->catalog->find($export->report);

        if ($schedule === null || $user === null || $report === null || $export->file_path === null) {
            return;
        }

        $this->tell($user, 'scheduled_report', 'Your scheduled report: '.$report->title(), $report->title().' for '.now()->format('d M Y').' is attached ('.number_format((int) $export->row_count).' rows). It is also under “My exports” for '.ReportExporter::KEEP_DAYS.' days.',
            route('reports.exports'), ReportExporter::DISK, $export->file_path, str($report->key())->slug().'-'.now()->format('Y-m-d').'.'.$export->format);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function withDates(array $filters, string $range, CarbonImmutable $today): array
    {
        $dates = RelativeRange::resolve($range, $today);

        return $dates === null ? $filters : $filters + $dates;
    }

    private function pause(ReportSchedule $schedule, ?User $user, ?Report $report, string $why): void
    {
        $fresh = ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($schedule->id)->where('active', true)->update(['active' => false, 'paused_reason' => $why]);

        if ($fresh === 1 && $user !== null) {   // told once: only the run that paused it speaks
            $this->tell($user, 'scheduled_report_paused', 'A scheduled report was paused', ($report?->title() ?? 'A report').' will not be sent any more: '.$why, route('reports.schedules'));
        }
    }

    private function tell(User $user, string $kind, string $subject, string $message, string $url, ?string $disk = null, ?string $path = null, ?string $fileName = null): void
    {
        try {
            $user->notify(new ScheduledReportNotice($kind, $subject, $message, $url, $disk, $path, $fileName));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
