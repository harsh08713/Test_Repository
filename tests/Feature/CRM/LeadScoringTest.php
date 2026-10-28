<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Http\Livewire\Leads\LeadShow;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadAdvisor;
use App\Modules\CRM\Services\LeadScorer;
use App\Modules\CRM\Support\LeadFacts;
use App\Modules\CRM\Support\LeadScore;
use App\Modules\CRM\Support\ScoringConfig;
use App\Modules\Shared\Http\Livewire\Settings\General;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->theirAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->settings = app(SettingsService::class);

    // a lead as typed in today, from a referral, with every detail but a value (override anything)
    $this->lead = fn (array $over = [], $source = 'REFERENCE') => makeLead($this->acme, $this->admin, $this->ahd, [
        'lead_source_id' => (string) leadSourceId($this->acme, $source), 'email' => 'meera@example.test', 'city' => 'Ahmedabad',
        'requirement' => 'Hex bolts M10, five thousand pieces a month', ...$over,
    ]);
    // change what a model holds without the service (the score reads the record, however it got that way)
    $this->set = function (Lead $lead, array $attributes) {
        $lead->forceFill($attributes)->saveQuietly();

        return $lead->fresh();
    };
    $this->facts = fn (Lead $lead) => inCompany($this->acme, fn () => app(LeadScorer::class)->facts($lead));
    $this->score = fn (Lead $lead, ?LeadFacts $facts = null) => inCompany($this->acme, function () use ($lead, $facts) {
        $scorer = app(LeadScorer::class);

        return $scorer->score($lead->fresh(), $facts ?? $scorer->facts($lead), $scorer->config((int) $this->acme->id));
    });
    $this->points = fn (LeadScore $score, string $key) => collect($score->factors)->firstWhere('key', $key)['points'];
    $this->followup = fn (Lead $lead, string $status, ?string $outcome = null, string $at = '2026-10-11 11:00:00') => DB::table('followups')->insert([
        'company_id' => $lead->company_id, 'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id, 'scheduled_at' => $at, 'type' => 'call',
        'assigned_to' => $this->admin->id, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        'outcome_id' => $outcome === null ? null : FollowupOutcome::query()->where('name', $outcome)->value('id'),
        'completed_at' => $status === 'done' ? $at : null,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ---- the arithmetic ------------------------------------------------------------------------------------------------

it('scores a lead from what is on it, and the signals add up to the score', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);

    $score = ($this->score)($lead);

    // referral 20 + six of six details 20 + no conversation 0 + never contacted, entered today 10 + New 0 + a value over the threshold 5
    expect(collect($score->factors)->pluck('points', 'key')->all())->toBe(['source' => 20, 'completeness' => 20, 'engagement' => 0, 'recency' => 10, 'stage' => 0, 'value' => 5])
        ->and($score->earned)->toBe(55)->and($score->available)->toBe(100)->and($score->score)->toBe(55)->and($score->band)->toBe(LeadScore::WARM)->and($score->closed)->toBeFalse()
        ->and(array_sum(array_column($score->factors, 'points')))->toBe($score->earned)
        ->and(collect($score->factors)->every(fn ($f) => $f['detail'] !== '' && $f['points'] >= 0 && $f['points'] <= $f['max']))->toBeTrue();
});

it('gives the same score every time for the same record', function () {
    $lead = ($this->lead)(['expected_value' => '60000']);

    expect(($this->score)($lead))->toEqual(($this->score)($lead));
});

it('rates the source by the company’s own percentages, and an unlisted source earns nothing', function () {
    $referral = ($this->lead)();
    $spreadsheet = ($this->lead)([], 'EXCEL');
    $social = ($this->lead)([], 'SOCIAL');

    expect(($this->points)(($this->score)($referral), 'source'))->toBe(20)->and(($this->points)(($this->score)($spreadsheet), 'source'))->toBe(4)         // 20 % of 20
        ->and(($this->points)(($this->score)($social), 'source'))->toBe(6);                                                                                     // 30 %

    $this->settings->set('scoring.source_points', 'REFERENCE=50, EXCEL = 150; nonsense; SOCIAL=abc', $this->acme->id);

    expect(($this->points)(($this->score)($referral), 'source'))->toBe(10)->and(($this->points)(($this->score)($spreadsheet), 'source'))->toBe(20)         // 150 is brought to 100
        ->and(($this->points)(($this->score)($social), 'source'))->toBe(0);                                                                                     // unreadable, so unrated
});

it('scores a lead the same however a screen loaded its source', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);
    $full = ($this->score)($lead);

    // the lead page loads `source:id,name` — no code. The score must not depend on that.
    $limited = $lead->fresh();
    $limited->setRelation('source', LeadSource::query()->select('id', 'name')->find($lead->lead_source_id));
    $none = $lead->fresh()->setRelation('source', null);

    $score = fn (Lead $model) => inCompany($this->acme, function () use ($model) {
        $scorer = app(LeadScorer::class);

        return $scorer->score($model, $scorer->facts($model), $scorer->config((int) $this->acme->id));
    });

    expect($score($limited)->score)->toBe($full->score)->and($score($none)->score)->toBe($full->score)->and($full->score)->toBe(55);
});

it('reads the source setting forgivingly', function () {
    expect(ScoringConfig::parseSources('reference=100, WEBSITE = 60; bad, X=abc, OVER=250, NEG=-5, A.B-1=7'))
        ->toBe(['REFERENCE' => 100, 'WEBSITE' => 60, 'OVER' => 100, 'NEG' => 0, 'A.B-1' => 7])
        ->and(ScoringConfig::parseSources(''))->toBe([]);
});

it('counts the six details a lead can have, and names the ones it lacks', function () {
    $bare = ($this->lead)(['email' => '', 'city' => '', 'contact_person' => '', 'requirement' => 'short']);
    $score = ($this->score)($bare);
    $detail = collect($score->factors)->firstWhere('key', 'completeness');

    expect($detail['points'])->toBe(3)                                                                                                   // only the mobile number: 1 of 6 of 20, rounded
        ->and($detail['detail'])->toContain('1 of 6')->toContain('an e-mail address')->toContain('a contact person')->toContain('a requirement')->toContain('an address')->toContain('an expected value')
        ->and(($this->points)(($this->score)(($this->lead)(['expected_value' => '1000'])), 'completeness'))->toBe(20);
});

it('reads engagement from the follow-ups that reached the person, not from the ones that were only tried', function () {
    $lead = ($this->lead)();
    $engagement = fn () => ($this->points)(($this->score)($lead), 'engagement');

    expect($engagement())->toBe(0);

    ($this->followup)($lead, 'done', 'Call Disconnected');
    expect($engagement())->toBe(2);                                    // tried, never reached: 10 % of 20

    ($this->followup)($lead, 'done', 'Interested', '2026-10-09 11:00:00');
    expect($engagement())->toBe(8);                                    // one conversation: 40 %

    ($this->followup)($lead, 'done', 'Meeting Fixed', '2026-10-10 11:00:00');
    expect($engagement())->toBe(14);                                   // two: 70 %

    ($this->followup)($lead, 'done', 'Store Visited', '2026-10-11 11:00:00');
    ($this->followup)($lead, 'done', 'Not Interested', '2026-10-11 12:00:00');
    expect($engagement())->toBe(20);                                   // three or more: all of it
});

it('counts follow-ups of this lead only, and neither planned, called-off nor another company’s', function () {
    $lead = ($this->lead)();
    $other = ($this->lead)();
    $theirs = makeLead($this->globex, $this->theirAdmin, $this->nyc);

    ($this->followup)($lead, 'done', 'Interested');
    ($this->followup)($lead, 'pending', null, '2026-10-20 11:00:00');
    ($this->followup)($lead, 'pending', null, '2026-10-01 11:00:00');          // planned and overdue
    ($this->followup)($lead, 'cancelled', 'Interested');
    ($this->followup)($other, 'done', 'Interested');
    DB::table('followups')->insert(['company_id' => $theirs->company_id, 'subject_type' => 'lead', 'subject_id' => $lead->id, 'scheduled_at' => '2026-10-11 10:00:00', 'type' => 'call',
        'assigned_to' => $this->theirAdmin->id, 'status' => 'done', 'outcome_id' => null, 'completed_at' => '2026-10-11 10:00:00', 'created_at' => now(), 'updated_at' => now()]);

    $facts = ($this->facts)($lead);

    expect([$facts->done, $facts->connected, $facts->pending, $facts->overdue])->toBe([1, 1, 2, 1])
        ->and($facts->lastDoneAt?->toDateTimeString())->toBe('2026-10-11 11:00:00');
});

it('measures recency from the last contact, or half as generously from the day an uncontacted lead came in', function () {
    $lead = ($this->lead)();
    $recency = fn (array $over) => ($this->points)(($this->score)(($this->set)($lead, $over)), 'recency');
    $day = fn (int $ago) => now()->subDays($ago)->format('Y-m-d 09:00:00');

    foreach ([[0, 20], [3, 20], [4, 15], [7, 15], [8, 10], [14, 10], [15, 5], [30, 5], [31, 0], [90, 0]] as [$days, $points]) {
        expect($recency(['last_contacted_at' => $day($days)]))->toBe($points, "{$days} days since the last contact");
    }

    // never contacted: counted from the day it was entered, at half (rounded)
    foreach ([[0, 10], [3, 10], [5, 8], [10, 5], [20, 3], [45, 0]] as [$days, $points]) {
        expect($recency(['last_contacted_at' => null, 'lead_date' => now()->subDays($days)->toDateString()]))->toBe($points, "entered {$days} days ago, never contacted");
    }
});

it('moves up with the stage', function () {
    $lead = ($this->lead)();
    $stage = fn (LeadStage $s) => ($this->points)(($this->score)(($this->set)($lead, ['stage' => $s])), 'stage');

    expect([$stage(LeadStage::New), $stage(LeadStage::Contacted), $stage(LeadStage::Qualified), $stage(LeadStage::Deal), $stage(LeadStage::Quotation)])->toBe([0, 5, 10, 15, 15]);
});

it('weighs the expected value against the company’s threshold', function () {
    $lead = ($this->lead)();
    $value = fn (?string $v) => ($this->points)(($this->score)(($this->set)($lead, ['expected_value' => $v])), 'value');

    expect([$value(null), $value('0'), $value('49999.99'), $value('50000'), $value('99999.99'), $value('100000'), $value('2500000')])->toBe([0, 0, 0, 3, 3, 5, 5]);

    $this->settings->set('scoring.value_threshold', 0, $this->acme->id);   // no threshold: any value at all earns it

    expect($value('1'))->toBe(5)->and($value(null))->toBe(0);
});

it('lets the company weigh the signals, and a weight of nothing switches one off', function () {
    $lead = ($this->set)(($this->lead)(['expected_value' => '150000']), ['stage' => LeadStage::Qualified]);

    expect(($this->score)($lead)->score)->toBe(65);                                                       // 20 + 20 + 0 + 10 + 10 + 5 of 100

    foreach (['max_source', 'max_completeness', 'max_engagement', 'max_recency', 'max_value'] as $key) {
        $this->settings->set("scoring.{$key}", 0, $this->acme->id);
    }

    $only = ($this->score)($lead);

    expect($only->available)->toBe(15)->and($only->earned)->toBe(10)->and($only->score)->toBe(67);        // only the stage counts now: 10 of 15

    $this->settings->set('scoring.max_stage', 0, $this->acme->id);

    expect(($this->score)($lead)->score)->toBe(0)->and(($this->score)($lead)->available)->toBe(0);          // nothing to weigh is not a division by zero
});

it('cuts hot, warm and cold where the company says, a score on the line belonging to the warmer side', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);   // 55 points

    expect(($this->score)($lead)->band)->toBe(LeadScore::WARM)->and(($this->score)($lead)->bandLabel())->toBe('Warm');

    $this->settings->set('scoring.hot_from', 55, $this->acme->id);
    expect(($this->score)($lead)->band)->toBe(LeadScore::HOT)->and(($this->score)($lead)->badge())->toContain('rose');

    $this->settings->set('scoring.hot_from', 56, $this->acme->id);
    $this->settings->set('scoring.warm_from', 56, $this->acme->id);
    expect(($this->score)($lead)->band)->toBe(LeadScore::COLD)->and(($this->score)($lead)->bandLabel())->toBe('Cold');
});

it('does not score a lead that is won or lost', function () {
    $lead = ($this->lead)();

    foreach ([LeadStage::Won, LeadStage::Lost] as $stage) {
        $score = ($this->score)(($this->set)($lead, ['stage' => $stage]));

        expect($score->closed)->toBeTrue()->and($score->factors)->toBe([])->and($score->score)->toBe(0);
    }
});

it('uses each company’s own settings', function () {
    $this->settings->set('scoring.hot_from', 10, $this->globex->id);
    $mine = ($this->lead)(['expected_value' => '150000']);

    $scorer = app(LeadScorer::class);

    expect($scorer->config((int) $this->globex->id)->hotFrom)->toBe(10)->and($scorer->config((int) $this->acme->id)->hotFrom)->toBe(70)
        ->and(($this->score)($mine)->band)->toBe(LeadScore::WARM);       // Globex's cut-off does not touch Acme's lead
});

// ---- the advice ------------------------------------------------------------------------------------------------------

it('tells the person what to do next, most pressing first, and no more than three things', function () {
    $lead = ($this->set)(($this->lead)(), ['stage' => LeadStage::New, 'mobile' => null, 'last_contacted_at' => now()->subDays(20), 'next_followup_at' => now()->subDays(2)]);
    $facts = new LeadFacts(done: 2, connected: 1, pending: 1, overdue: 1, lastDoneAt: CarbonImmutable::now()->subDays(20));

    $advice = app(LeadAdvisor::class)->advise($lead, ($this->score)($lead, $facts), $facts, now());

    // four things apply (overdue, quiet for 20 days, no mobile, spoken to but still New) — three are offered, the overdue one first
    expect($advice->actions)->toHaveCount(3)->and($advice->actions[0])->toContain('passed its time')->toContain('2 days ago')
        ->and($advice->actions[1])->toContain('20 days ago')->and($advice->actions[2])->toContain('no mobile number')
        ->and($advice->contactBy)->toBeNull();                                  // something is planned already
});

it('asks for a follow-up when nothing is planned, and suggests when by, by how warm the lead is', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);
    $facts = ($this->facts)($lead);
    $advise = fn (string $band) => app(LeadAdvisor::class)->advise($lead->fresh(), new LeadScore(false, 50, $band, [], 50, 100), $facts, now());

    expect($advise(LeadScore::HOT)->actions[0])->toContain('Nothing is planned')->and($advise(LeadScore::HOT)->contactBy->toDateString())->toBe('2026-10-13')
        ->and($advise(LeadScore::WARM)->contactBy->toDateString())->toBe('2026-10-15')
        ->and($advise(LeadScore::COLD)->contactBy->toDateString())->toBe('2026-10-19');
});

it('says when nobody has ever reached the lead', function () {
    $fresh = ($this->lead)();
    $old = ($this->set)(($this->lead)(), ['lead_date' => now()->subDays(3)->toDateString()]);
    $advise = fn (Lead $lead) => app(LeadAdvisor::class)->advise($lead->fresh(), ($this->score)($lead), ($this->facts)($lead), now())->actions;

    expect(implode(' ', $advise($fresh)))->not->toContain('Nobody has reached')                       // came in today: too soon to chase
        ->and(implode(' ', $advise($old)))->toContain('Nobody has reached this lead yet')->toContain('3 days ago')->toContain('Call it today');
});

it('suggests the next stage from what has happened', function () {
    $lead = ($this->lead)();
    $advise = fn (Lead $lead, LeadFacts $facts) => implode(' | ', app(LeadAdvisor::class)->advise($lead->fresh(), ($this->score)($lead, $facts), $facts, now())->actions);
    $planned = new LeadFacts(done: 1, connected: 1, pending: 1, overdue: 0, lastDoneAt: CarbonImmutable::now());

    expect($advise($lead, $planned))->toContain('move it to Contacted');

    $qualified = ($this->set)($lead, ['stage' => LeadStage::Qualified, 'last_contacted_at' => now()->subDay()]);
    expect($advise($qualified, $planned))->toContain('qualified but has no expected value')->not->toContain('convert it');

    $valued = ($this->set)($qualified, ['expected_value' => '75000']);
    expect($advise($valued, $planned))->toContain('convert it to a deal, or quote it');
});

it('summarises a lead from what is stored, and nothing else', function () {
    $lead = ($this->set)(($this->lead)(['expected_value' => '250000']), ['last_contacted_at' => '2026-10-10 09:00:00']);
    ($this->followup)($lead, 'done', 'Interested', '2026-10-10 09:00:00');
    ($this->followup)($lead, 'done', 'Call Disconnected', '2026-10-09 09:00:00');
    ($this->followup)($lead, 'pending', null, '2026-10-20 09:00:00');

    $summary = app(LeadAdvisor::class)->summary($lead->fresh(['source']), ($this->facts)($lead));

    expect($summary)->toBe('Entered 12 Oct 2026 through Typed in from Reference. 2 follow-ups done (1 reached the person), 1 planned. Last contacted 10 Oct 2026. Stage: New. Expected value ₹2,50,000.00.')
        ->and($summary)->not->toContain('Meera')->not->toContain('9876');                   // no name, no number: it can be pasted anywhere

    $quiet = ($this->lead)();
    expect(app(LeadAdvisor::class)->summary($quiet->fresh(['source']), ($this->facts)($quiet)))->toContain('No follow-up has been planned or done.')->toContain('Never contacted.');
});

it('gives a closed lead its summary and no advice', function () {
    $lead = ($this->set)(($this->lead)(), ['stage' => LeadStage::Lost]);
    $facts = ($this->facts)($lead);

    $advice = app(LeadAdvisor::class)->advise($lead, ($this->score)($lead), $facts, now());

    expect($advice->actions)->toBe([])->and($advice->contactBy)->toBeNull()->and($advice->summary)->toContain('Stage: Lost.');
});

// ---- on the lead's page ------------------------------------------------------------------------------------------------

it('shows the score, the reasons and the advice on the lead’s page — and says it is not an AI', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);

    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead->fresh()])
        ->assertSee('Score and advice')->assertSee('Warm')->assertSee('out of 100')->assertSee('not by an AI')
        ->assertSee('Where it came from')->assertSee('Reference')->assertSee('Details filled in')->assertSee('How recent the contact is')->assertSee('Never contacted')
        ->assertSee('Worth doing next')->assertSee('Nothing is planned for this lead')->assertSee('Be back in touch by')->assertSee('Thu 15 Oct')
        ->assertSee('55 of 100 points is 55 out of 100');
});

it('says a closed lead is not scored, and still summarises it', function () {
    $lead = ($this->set)(($this->lead)(), ['stage' => LeadStage::Lost]);

    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead])->assertSee('Closed — not scored')->assertSee('Stage: Lost.')->assertDontSee('Worth doing next')->assertDontSee('Details filled in');
});

it('leaves the card off when the company switches scoring off', function () {
    $lead = ($this->lead)();
    $this->settings->set('scoring.enabled', false, $this->acme->id);

    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertDontSee('Score and advice')->assertDontSee('Worth doing next')->assertSee($lead->name);

    $this->settings->set('scoring.enabled', true, $this->acme->id);
    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('Score and advice');
});

it('changes nothing about the lead by looking at it', function () {
    $lead = ($this->lead)(['expected_value' => '150000']);
    $before = $lead->fresh()->getAttributes();
    $followups = DB::table('followups')->count();

    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('Score and advice');

    expect($lead->fresh()->getAttributes())->toBe($before)->and(DB::table('followups')->count())->toBe($followups);
});

it('puts the weights on the company’s settings screen', function () {
    Livewire::actingAs($this->admin)->test(General::class)
        ->assertSee('Lead scoring')->assertSee('Show a score, advice and a summary on a lead')->assertSee('A lead is “hot” from this score')
        ->assertSee('How much each lead source is worth');
});
