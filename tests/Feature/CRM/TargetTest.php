<?php

use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\CRM\Enums\TargetPeriod;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\CRM\Services\TargetAchievement;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Sales\Services\SalesOrderAchievement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->globexBranch = makeLocation($this->globex, 'GLB');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $this->group = itemGroupId($this->acme);
    $this->targets = app(TargetService::class);

    // a monthly value target for the BDE for October 2026, saved the way the form does
    $this->save = fn (array $target = [], $actor = null, $existing = null) => inCompany($this->acme, fn () => $this->targets->save($existing, ['target' => [
        'subject' => 'user', 'user_id' => (string) $this->bde->id, 'period_type' => 'monthly', 'period_start' => '2026-10-01',
        'target_value' => '500000', 'target_quantity' => '', 'item_group_id' => '', 'location_id' => '', 'allow_overlap' => false, 'status' => 'active', 'remarks' => '',
        ...$target,
    ]], $actor ?? $this->manager));
    $this->complaints = function (array $target, $actor = null, $existing = null) {
        try {
            ($this->save)($target, $actor, $existing);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// What a target is
// ======================================================================================================

it('sets a monthly target for a salesperson, exactly', function () {
    $target = ($this->save)(['period_start' => '2026-10-17', 'target_value' => '5,00,000.5', 'target_quantity' => '2500.125']);

    expect($target->user_id)->toBe($this->bde->id)->and($target->sales_team_id)->toBeNull()->and($target->period_type)->toBe(TargetPeriod::Monthly)
        ->and($target->period_start->toDateString())->toBe('2026-10-01')->and($target->period_end->toDateString())->toBe('2026-10-31')
        ->and($target->target_value)->toBe('500000.50')->and($target->target_quantity)->toBe('2500.125')
        ->and($target->item_group_id)->toBeNull()->and($target->location_id)->toBeNull()->and($target->status)->toBe('active')
        ->and($target->company_id)->toBe($this->acme->id)->and($target->created_by)->toBe($this->manager->id);
});

it('moves any day to the start of its month, and a quarter to the start of the financial-year quarter', function () {
    $bounds = fn (string $type, string $day, int $fy = 4) => array_map(fn ($d) => $d->toDateString(), TargetPeriod::from($type)->bounds(Carbon::parse($day), $fy));

    expect($bounds('monthly', '2026-02-17'))->toBe(['2026-02-01', '2026-02-28'])->and($bounds('monthly', '2028-02-29'))->toBe(['2028-02-01', '2028-02-29'])
        // a financial year that starts in April: Q1 Apr–Jun, Q2 Jul–Sep, Q3 Oct–Dec, Q4 Jan–Mar
        ->and($bounds('quarterly', '2026-11-15'))->toBe(['2026-10-01', '2026-12-31'])->and($bounds('quarterly', '2026-04-01'))->toBe(['2026-04-01', '2026-06-30'])
        ->and($bounds('quarterly', '2027-03-31'))->toBe(['2027-01-01', '2027-03-31'])->and($bounds('quarterly', '2026-09-30'))->toBe(['2026-07-01', '2026-09-30'])
        // a company whose year starts in January: the same blocks of three months, counted from there; and from February
        ->and($bounds('quarterly', '2026-05-20', 1))->toBe(['2026-04-01', '2026-06-30'])->and($bounds('quarterly', '2026-01-01', 1))->toBe(['2026-01-01', '2026-03-31'])
        ->and($bounds('quarterly', '2026-04-10', 2))->toBe(['2026-02-01', '2026-04-30'])->and($bounds('quarterly', '2026-01-10', 2))->toBe(['2025-11-01', '2026-01-31']);
});

it('describes a period the way people say it', function () {
    expect(TargetPeriod::Monthly->describe(Carbon::parse('2026-10-01')))->toBe('October 2026')
        ->and(TargetPeriod::Quarterly->describe(Carbon::parse('2026-10-01')))->toBe('Q3 FY26-27 (Oct–Dec 2026)')
        ->and(TargetPeriod::Quarterly->describe(Carbon::parse('2027-01-01')))->toBe('Q4 FY26-27 (Jan–Mar 2027)')
        ->and(TargetPeriod::Quarterly->describe(Carbon::parse('2026-04-01')))->toBe('Q1 FY26-27 (Apr–Jun 2026)');
});

it('sets a quarterly target using the company’s own financial-year start', function () {
    DB::table('companies')->where('id', $this->acme->id)->update(['financial_year_start_month' => 1]);

    $target = ($this->save)(['period_type' => 'quarterly', 'period_start' => '2026-05-20']);

    expect($target->period_start->toDateString())->toBe('2026-04-01')->and($target->period_end->toDateString())->toBe('2026-06-30');
});

it('needs a goal above zero, as a value, a quantity or both', function () {
    expect(($this->complaints)(['target_value' => '', 'target_quantity' => '']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => '0']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => '-5']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => 'abc']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => '1.234']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => '12345678901234']))->toBe(['target.target_value'])
        ->and(($this->complaints)(['target_value' => '', 'target_quantity' => '0']))->toBe(['target.target_quantity'])
        ->and(($this->complaints)(['target_value' => '', 'target_quantity' => '1.2345']))->toBe(['target.target_quantity']);

    expect(($this->save)(['target_value' => '', 'target_quantity' => '10'])->target_value)->toBeNull();
});

it('refuses a period that is not a month or a quarter, or not a date', function () {
    expect(($this->complaints)(['period_type' => 'weekly']))->toBe(['target.period_type'])
        ->and(($this->complaints)(['period_start' => 'next month']))->toBe(['target.period_start'])
        ->and(($this->complaints)(['period_start' => '1999-12-31']))->toBe(['target.period_start'])
        ->and(($this->complaints)(['period_start' => '']))->toBe(['target.period_start']);
});

it('can be narrowed to an item group and a branch of the company, and to nothing else', function () {
    $target = ($this->save)(['item_group_id' => (string) $this->group, 'location_id' => (string) $this->ahd->id]);

    expect($target->item_group_id)->toBe($this->group)->and($target->location_id)->toBe($this->ahd->id);

    $foreignGroup = itemGroupId($this->globex, 'RM', 'Theirs');

    expect(($this->complaints)(['item_group_id' => (string) $foreignGroup, 'period_start' => '2026-11-01']))->toBe(['target.item_group_id'])
        ->and(($this->complaints)(['location_id' => (string) $this->globexBranch->id, 'period_start' => '2026-11-01']))->toBe(['target.location_id'])
        ->and(($this->complaints)(['item_group_id' => '999999', 'period_start' => '2026-11-01']))->toBe(['target.item_group_id']);
});

// ======================================================================================================
// Who a target is for
// ======================================================================================================

it('lets a manager set targets for their own people and their own team, and not for anybody else', function () {
    expect(($this->complaints)(['user_id' => (string) $this->bde2->id]))->toBe(['target.user_id'])          // on nobody's team
        ->and(($this->complaints)(['user_id' => (string) $this->manager->id]))->toBe(['target.user_id'])    // never for themselves
        ->and(($this->complaints)([], $this->otherManager))->toBe(['target.user_id']);                      // someone else's person

    $teamTarget = ($this->save)(['subject' => 'team', 'user_id' => '', 'sales_team_id' => (string) $this->team->id]);
    expect($teamTarget->sales_team_id)->toBe($this->team->id)->and($teamTarget->user_id)->toBeNull();

    expect(($this->complaints)(['subject' => 'team', 'user_id' => '', 'sales_team_id' => (string) $this->team->id, 'period_start' => '2026-11-01'], $this->otherManager))->toBe(['target.sales_team_id']);
});

it('lets someone who sees everyone set a target for anybody, themselves included', function () {
    expect(($this->save)(['user_id' => (string) $this->bde2->id], $this->admin)->user_id)->toBe($this->bde2->id)
        ->and(($this->save)(['user_id' => (string) $this->admin->id], $this->admin)->user_id)->toBe($this->admin->id);
});

it('is for one person or one team, never both and never neither', function () {
    expect(($this->complaints)(['subject' => '', 'user_id' => '']))->toBe(['target.subject'])
        ->and(($this->complaints)(['user_id' => '']))->toBe(['target.user_id'])
        ->and(($this->complaints)(['subject' => 'team', 'sales_team_id' => '']))->toBe(['target.sales_team_id']);

    // even a forged payload with both ids set keeps only the one the subject names
    $target = ($this->save)(['subject' => 'user', 'sales_team_id' => (string) $this->team->id]);
    expect($target->sales_team_id)->toBeNull();

    // and the database itself refuses a row with both
    expect(fn () => DB::table('sales_targets')->insert([
        'company_id' => $this->acme->id, 'user_id' => $this->bde->id, 'sales_team_id' => $this->team->id, 'period_type' => 'monthly',
        'period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'target_value' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses an inactive person, someone from another company and an unknown id', function () {
    $inactive = makeUser(['BDE / Field Salesperson'], [$this->acme], null, ['is_active' => false]);
    $stranger = makeUser(['BDE / Field Salesperson'], [$this->globex]);

    foreach ([$inactive->id, $stranger->id, 999999] as $id) {
        expect(($this->complaints)(['user_id' => (string) $id], $this->admin))->toBe(['target.user_id']);
    }
});

// ======================================================================================================
// No overlapping duplicates
// ======================================================================================================

it('refuses the same month twice for the same person and scope, naming what it clashes with', function () {
    ($this->save)();

    try {
        ($this->save)(['target_value' => '600000']);
        $this->fail('the duplicate was accepted');
    } catch (ValidationException $e) {
        expect($e->errors()['target.period_start'][0])->toContain('overlaps an existing target')->toContain('October 2026')->toContain('allow overlap');
    }

    expect(SalesTarget::query()->count())->toBe(1);
});

it('refuses a month inside a quarter that already covers it, and a quarter over a month', function () {
    ($this->save)(['period_type' => 'quarterly', 'period_start' => '2026-10-01']);        // Q3: Oct–Dec

    expect(($this->complaints)(['period_start' => '2026-11-01']))->toBe(['target.period_start'])
        ->and(($this->complaints)(['period_start' => '2026-09-01']))->toBe([]);          // September is Q2

    ($this->save)(['period_start' => '2027-02-01']);                                       // February, alone
    expect(($this->complaints)(['period_type' => 'quarterly', 'period_start' => '2027-01-01']))->toBe(['target.period_start']);
});

it('lets the same period stand for a different person, team, item group or branch', function () {
    ($this->save)();

    expect(($this->complaints)(['user_id' => (string) $this->bde2->id], $this->admin))->toBe([])
        ->and(($this->complaints)(['subject' => 'team', 'user_id' => '', 'sales_team_id' => (string) $this->team->id]))->toBe([])
        ->and(($this->complaints)(['item_group_id' => (string) $this->group]))->toBe([])
        ->and(($this->complaints)(['location_id' => (string) $this->ahd->id]))->toBe([]);

    // …but the same item group and branch twice is the same scope
    expect(($this->complaints)(['item_group_id' => (string) $this->group]))->toBe(['target.period_start'])
        ->and(($this->complaints)(['location_id' => (string) $this->ahd->id]))->toBe(['target.period_start'])
        ->and(($this->complaints)(['item_group_id' => (string) $this->group, 'location_id' => (string) $this->ahd->id]))->toBe([]);
});

it('lets a month follow the one before it, without overlap', function () {
    ($this->save)(['period_start' => '2026-09-01']);

    expect(($this->complaints)(['period_start' => '2026-10-01']))->toBe([])->and(($this->complaints)(['period_start' => '2026-08-01']))->toBe([]);
});

it('lets a target overlap others only when it says so, and then it never blocks the others either', function () {
    $regular = ($this->save)();
    $campaign = ($this->save)(['allow_overlap' => true, 'target_value' => '100000']);

    expect($campaign->allow_overlap)->toBeTrue()->and($campaign->slot_key)->toBeNull()->and($regular->slot_key)->not->toBeNull();

    // another regular one is still refused by the first regular one, and the campaign does not stop a second campaign
    expect(($this->complaints)([]))->toBe(['target.period_start'])->and(($this->complaints)(['allow_overlap' => true]))->toBe([]);
});

it('counts a switched-off target as one that still holds its slot', function () {
    ($this->save)(['status' => 'inactive']);

    expect(($this->complaints)([]))->toBe(['target.period_start']);
});

it('does not clash with itself when edited, and frees its slot when removed', function () {
    $target = ($this->save)();

    expect(($this->save)(['target_value' => '650000'], null, $target)->target_value)->toBe('650000.00');

    inCompany($this->acme, fn () => $this->targets->delete($target->fresh()));

    expect(SalesTarget::query()->count())->toBe(0)->and(($this->complaints)([]))->toBe([]);
});

it('is stopped by the database as well: an exact duplicate cannot be written around the service', function () {
    $target = ($this->save)();

    expect(fn () => DB::table('sales_targets')->insert([
        'company_id' => $this->acme->id, 'user_id' => $this->bde->id, 'period_type' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'target_value' => 1, 'status' => 'active', 'slot_key' => $target->slot_key, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('holds the line under a lock on the person, so two managers cannot both slip a target in', function () {
    // both saves find the slot free only if neither sees the other's row; the lock makes the second one wait, then see it
    $first = ($this->save)();
    $locked = [];
    DB::listen(function ($query) use (&$locked) {
        if (str_contains(strtolower($query->sql), 'for update') && str_contains($query->sql, '`users`')) {
            $locked[] = $query->sql;
        }
    });

    expect(($this->complaints)(['target_value' => '1']))->toBe(['target.period_start'])->and($locked)->not->toBeEmpty();
});

// ======================================================================================================
// History
// ======================================================================================================

it('treats a target whose period is over as history', function () {
    $past = ($this->save)(['period_start' => '2026-08-01']);

    expect(fn () => ($this->save)(['period_start' => '2026-08-01', 'target_value' => '1'], null, $past))->toThrow(FieldRuleException::class, 'history')
        ->and(fn () => inCompany($this->acme, fn () => $this->targets->delete($past)))->toThrow(FieldRuleException::class, 'history')
        ->and($past->isOver())->toBeTrue();

    // the month running now is not history yet, and the last day of a period still counts as inside it
    expect(($this->save)()->isOver())->toBeFalse();
    Carbon::setTestNow('2026-10-31 23:00:00');
    expect(SalesTarget::query()->where('period_start', '2026-10-01')->first()->isOver())->toBeFalse();
    Carbon::setTestNow('2026-11-01 00:30:00');
    expect(SalesTarget::query()->where('period_start', '2026-10-01')->first()->isOver())->toBeTrue();
});

// ======================================================================================================
// Achievement
// ======================================================================================================

/** An achievement source that remembers what it was asked and answers from a table of people and amounts. */
function fakeAchievement(array $answers): AchievementSource
{
    return new class($answers) implements AchievementSource
    {
        /** @var list<array{0: int, 1: string, 2: string, 3: ?int, 4: ?int}> */
        public array $asked = [];

        /** @param  array<int, array{value: string, quantity: string}>  $answers */
        public function __construct(private array $answers) {}

        public function achieved(int $userId, string $from, string $to, ?int $itemGroupId, ?int $locationId): array
        {
            $this->asked[] = [$userId, $from, $to, $itemGroupId, $locationId];

            return $this->answers[$userId] ?? ['value' => '0.00', 'quantity' => '0.000'];
        }

        public function isLive(): bool
        {
            return true;
        }
    };
}

it('reads progress from sales orders, and reports nothing achieved while there are none', function () {
    $target = ($this->save)();
    $progress = inCompany($this->acme, fn () => app(TargetAchievement::class)->progress($target));

    expect(app(AchievementSource::class))->toBeInstanceOf(SalesOrderAchievement::class)
        ->and($progress->live)->toBeTrue()->and($progress->achievedValue)->toBe('0.00')->and($progress->achievedQuantity)->toBe('0.000')
        ->and($progress->targetValue)->toBe('500000.00')->and($progress->valuePercent)->toBe('0.0')->and($progress->quantityPercent)->toBeNull();
});

it('works out progress in exact decimals, rounding half up to one decimal', function () {
    $target = ($this->save)(['target_value' => '300000', 'target_quantity' => '1000']);
    $source = fakeAchievement([$this->bde->id => ['value' => '100000.00', 'quantity' => '333.333']]);

    $progress = inCompany($this->acme, fn () => (new TargetAchievement($source))->progress($target));

    expect($progress->achievedValue)->toBe('100000.00')->and($progress->valuePercent)->toBe('33.3')->and($progress->quantityPercent)->toBe('33.3')->and($progress->live)->toBeTrue()
        ->and($source->asked)->toBe([[$this->bde->id, '2026-10-01', '2026-10-31', null, null]]);

    // 1 of 8 is 12.5%; 1 of 3 is 33.3%; 2 of 3 is 66.7%; more than the target is more than 100
    $percent = fn (string $achieved, string $target) => inCompany($this->acme, fn () => (new TargetAchievement(fakeAchievement([$this->bde->id => ['value' => $achieved, 'quantity' => '0']])))
        ->progress(($this->save)(['period_start' => '2027-01-01', 'target_value' => $target], null, null))->valuePercent);

    expect($percent('1', '8'))->toBe('12.5');
});

it('asks about the target’s item group and branch, so only matching orders count', function () {
    $target = ($this->save)(['item_group_id' => (string) $this->group, 'location_id' => (string) $this->ahd->id]);
    $source = fakeAchievement([]);

    inCompany($this->acme, fn () => (new TargetAchievement($source))->progress($target));

    expect($source->asked)->toBe([[$this->bde->id, '2026-10-01', '2026-10-31', $this->group, $this->ahd->id]]);
});

it('adds up a team’s people, each only for the days they were on the team', function () {
    $team = makeTeam($this->acme, $this->otherManager, [], 'TWO', 'Second team');
    $joinedMid = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $leftMid = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $whole = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $before = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $teams = app(SalesTeamService::class);

    inCompany($this->acme, function () use ($teams, $team, $joinedMid, $leftMid, $whole, $before) {
        $teams->addMember($team, $whole->id, '2026-01-01', $this->otherManager);
        $teams->addMember($team, $joinedMid->id, '2026-10-10', $this->otherManager);
        $member = $teams->addMember($team, $leftMid->id, '2026-05-01', $this->otherManager);
        $teams->removeMember($member, '2026-10-11', $this->otherManager);
        $gone = $teams->addMember($team, $before->id, '2026-01-01', $this->otherManager);
        $teams->removeMember($gone, '2026-09-30', $this->otherManager);
    });

    $target = ($this->save)(['subject' => 'team', 'user_id' => '', 'sales_team_id' => (string) $team->id, 'target_value' => '1000000'], $this->otherManager);
    $source = fakeAchievement([
        $whole->id => ['value' => '100.00', 'quantity' => '1.000'], $joinedMid->id => ['value' => '200.50', 'quantity' => '2.000'],
        $leftMid->id => ['value' => '300.25', 'quantity' => '3.500'], $before->id => ['value' => '9999.00', 'quantity' => '9.000'],
    ]);

    $progress = inCompany($this->acme, fn () => (new TargetAchievement($source))->progress($target));

    expect($progress->achievedValue)->toBe('600.75')->and($progress->achievedQuantity)->toBe('6.500')
        ->and(collect($source->asked)->mapWithKeys(fn ($a) => [$a[0] => [$a[1], $a[2]]])->all())->toBe([
            $whole->id => ['2026-10-01', '2026-10-31'],
            $joinedMid->id => ['2026-10-10', '2026-10-31'],      // from the day they joined
            $leftMid->id => ['2026-10-01', '2026-10-11'],        // up to the day they left
        ]);                                                       // and the one who left in September is not asked at all
});
