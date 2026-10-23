<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;

/** Who did what to which record, from the audit log. What changed (the values) is on the audit screen, not here: this is the activity, not the data. */
final class AuditActivity extends Report
{
    public function key(): string
    {
        return 'audit-activity';
    }

    public function title(): string
    {
        return 'Audit activity';
    }

    public function description(): string
    {
        return 'Who created, changed, approved or deleted which kind of record, and when — for this company.';
    }

    public function group(): string
    {
        return 'Control';
    }

    public function permission(): string
    {
        return 'audit_log.view';
    }

    public function filters(): array
    {
        return ['date', 'person', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'When';
    }

    public function choiceLabel(): string
    {
        return 'Event';
    }

    public function choices(): array
    {
        return ['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'override' => 'Override or exception'];
    }

    public function note(): string
    {
        return 'The old and new values are not copied into this report; open the audit log for them.';
    }

    public function columns(): array
    {
        return [
            new C('created_at', 'When', C::DATETIME), new C('who', 'Who'), new C('event', 'Event'), new C('record', 'Kind of record'), new C('record_id', 'Record id', C::NUMBER),
            new C('description', 'Description'), new C('log_name', 'Area'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = ActivityLog::query()
            ->leftJoin('users', fn ($j) => $j->on('users.id', '=', 'activity_log.causer_id')->where('activity_log.causer_type', '=', 'user'))
            ->select([
                'activity_log.id', 'activity_log.created_at', 'activity_log.event', 'activity_log.description', 'activity_log.log_name', 'activity_log.subject_type',
                'activity_log.subject_id as record_id', 'users.name as who',
            ]);

        $this->between($query, 'activity_log.created_at', $input, true);
        $input->peopleIds === null || $query->whereIn('activity_log.causer_id', $input->peopleIds);

        match ($input->choice) {
            null => null,
            'override' => $query->where(fn ($q) => $q->where('activity_log.event', 'like', '%override%')->orWhere('activity_log.event', 'like', '%exception%')),
            default => $query->where('activity_log.event', $input->choice),
        };

        return $query->orderByDesc('activity_log.created_at')->orderByDesc('activity_log.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['who'] ??= 'The system';
        $out['record'] = ucfirst(str_replace('_', ' ', (string) $row->getRawOriginal('subject_type')));

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, count(distinct activity_log.causer_id) as people');

        return ['Entries' => number_format((int) ($t->total ?? 0)), 'People' => number_format((int) ($t->people ?? 0))];
    }
}
