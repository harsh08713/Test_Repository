<?php

namespace App\Modules\Reports\Support;

use App\Models\User;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Reports\Report;
use App\Modules\Shared\Models\Location;
use Illuminate\Support\Carbon;

/**
 * The filters of one run of a report, checked against what the signed-in person may pick. Anything typed or posted by a browser is a
 * request: a person, team, branch or source that the user could not have chosen from the lists is refused rather than trusted, so a
 * tampered address cannot widen a report beyond the person's own reach (the report's own query is restricted again, whatever is asked).
 */
final class ReportInput
{
    public const KEYS = ['from', 'to', 'branch', 'person', 'team', 'source', 'choice'];

    /**
     * @param  list<int>|null  $peopleIds  the salespeople asked for (a person, a team, or both), or null for "not narrowed"
     */
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $branch = null,
        public readonly ?int $person = null,
        public readonly ?int $team = null,
        public readonly ?int $source = null,
        public readonly ?string $choice = null,
        public readonly ?array $peopleIds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidReportFilters
     */
    public static function resolve(Report $report, array $raw, User $user): self
    {
        $declared = $report->filters();
        $problems = [];
        $text = fn (string $key): string => is_scalar($raw[$key] ?? null) ? trim((string) $raw[$key]) : '';

        $from = $to = null;

        if (in_array('date', $declared, true)) {
            foreach (['from' => 'The “from” date', 'to' => 'The “to” date'] as $key => $label) {
                $value = $text($key);

                if ($value === '') {
                    continue;
                }

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! Carbon::hasFormat($value, 'Y-m-d') || Carbon::parse($value)->format('Y-m-d') !== $value) {
                    $problems[$key] = "{$label} is not a date.";

                    continue;
                }

                $key === 'from' ? $from = $value : $to = $value;
            }

            if ($from !== null && $to !== null && $from > $to) {
                $problems['to'] = 'The “to” date is before the “from” date.';
            }
        }

        $branch = null;

        if (in_array('branch', $declared, true) && $text('branch') !== '') {
            $id = $text('branch');

            if (! ctype_digit($id) || ! Location::query()->whereKey((int) $id)->exists()) {
                $problems['branch'] = 'That branch is not one you can report on.';
            } else {
                $branch = (int) $id;
            }
        }

        $person = $team = $source = null;
        $peopleIds = null;

        if (in_array('person', $declared, true) && $text('person') !== '') {
            $id = $text('person');

            if (! ctype_digit($id) || ! array_key_exists((int) $id, self::people($user))) {
                $problems['person'] = 'That person is not one you can report on.';
            } else {
                $person = (int) $id;
                $peopleIds = [$person];
            }
        }

        if (in_array('team', $declared, true) && $text('team') !== '') {
            $id = $text('team');

            if (! ctype_digit($id) || ! array_key_exists((int) $id, app(FollowupAgenda::class)->teams($user))) {
                $problems['team'] = 'That team is not one you can report on.';
            } else {
                $team = (int) $id;
                $members = app(FollowupAgenda::class)->teamUserIds($user, $team);
                $peopleIds = $peopleIds === null ? $members : array_values(array_intersect($peopleIds, $members));
            }
        }

        if (in_array('source', $declared, true) && $text('source') !== '') {
            $id = $text('source');

            if (! ctype_digit($id) || ! LeadSource::query()->whereKey((int) $id)->exists()) {
                $problems['source'] = 'That lead source does not exist.';
            } else {
                $source = (int) $id;
            }
        }

        $choice = null;

        if (in_array('choice', $declared, true) && $text('choice') !== '') {
            $value = $text('choice');

            if (! array_key_exists($value, $report->choices())) {
                $problems['choice'] = 'That is not one of the choices.';
            } else {
                $choice = $value;
            }
        }

        if ($problems !== []) {
            throw new InvalidReportFilters($problems);
        }

        return new self($from, $to, $branch, $person, $team, $source, $choice, $peopleIds);
    }

    /**
     * The salespeople a person may pick: everyone they manage and themselves, or all active staff for someone who sees everyone.
     *
     * @return array<int, string> id => name
     */
    public static function people(User $user): array
    {
        $staff = app(SalesTeamService::class)->staff();
        $visible = app(SalesScope::class)->visibleUserIds($user);

        return $visible === null ? $staff : array_intersect_key($staff, array_flip($visible));
    }

    /**
     * What was asked for, as the plain values a stored export keeps and resolves again when it is made.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'branch' => $this->branch === null ? null : (string) $this->branch,
            'person' => $this->person === null ? null : (string) $this->person,
            'team' => $this->team === null ? null : (string) $this->team,
            'source' => $this->source === null ? null : (string) $this->source,
            'choice' => $this->choice,
        ], fn ($v) => $v !== null);
    }
}
