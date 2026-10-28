<?php

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Http\Livewire\Deals\DealShow;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealForecaster;
use App\Modules\CRM\Support\DealForecast;
use App\Modules\Shared\Http\Livewire\Settings\General;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->theirAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);          // a salesperson's lead is theirs; an administrator's goes to the pool, and a deal needs an owner
    $this->theirBde = makeUser(['BDE / Field Salesperson'], [$this->globex], [$this->nyc]);
    $this->bolt = makeUsableItem($this->acme);
    $this->settings = app(SettingsService::class);

    // a deal through the real service (a lead, qualified, converted); `goes` then says how its life went
    $this->deal = fn () => makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]));
    $this->goes = function (Deal $deal, array $via, ?string $final = null) {
        $from = DealStage::Discovery->value;                       // the service wrote the first entry (→ discovery) when the lead was converted

        foreach ($via as $stage) {
            DB::table('deal_stage_history')->insert(['company_id' => $deal->company_id, 'deal_id' => $deal->id, 'from_stage' => $from, 'to_stage' => $stage, 'created_at' => now()]);
            $from = $stage;
        }

        DB::table('deals')->where('id', $deal->id)->update(['stage' => $final ?? $from]);

        return $deal->fresh();
    };
    $this->closed = fn (array $via, string $final) => ($this->goes)(($this->deal)(), $via, $final);
    $this->forecaster = fn () => app(DealForecaster::class);
    $this->history = fn () => ($this->forecaster)()->history((int) $this->acme->id);
});

afterEach(fn () => Carbon::setTestNow());

// ---- the arithmetic ---------------------------------------------------------------------------------------------------

it('works the estimate out by hand: the stage’s usual chance, moved towards what happened', function (int $won, int $deals, int $usual, int $weight, int $expected) {
    expect(DealForecaster::estimate($won, $deals, $usual, $weight))->toBe($expected);
})->with([
    'no history, discovery' => [0, 0, 10, 10, 10],
    'no history, negotiation' => [0, 0, 50, 10, 50],
    'no history, quotation' => [0, 0, 70, 10, 70],
    'half won' => [5, 10, 50, 10, 50],
    'all won' => [10, 10, 50, 10, 75],
    'none won' => [0, 10, 50, 10, 25],
    'a long record of losing' => [0, 200, 50, 10, 2],
    'a long record of winning' => [200, 200, 50, 10, 98],
    'never below one' => [0, 1000, 10, 1, 1],
    'never above ninety-nine' => [1000, 1000, 70, 1, 99],
    'more wins than deals cannot happen' => [30, 10, 50, 10, 75],
    'a weight of nothing is a weight of one' => [1, 1, 50, 0, 75],
    'a slower mover' => [8, 10, 50, 100, 53],
]);

it('moves further from the usual figure the more deals have closed, and never past what happened', function () {
    $quotation = fn (int $deals) => DealForecaster::estimate((int) ($deals * 0.9), $deals, 70, 10);

    $steps = array_map($quotation, [0, 5, 10, 20, 50, 100, 400]);

    expect($steps[0])->toBe(70)->and($steps)->toBe(collect($steps)->sort()->values()->all())     // never turns back on the way to 90 %
        ->and(end($steps))->toBeLessThanOrEqual(90)->and(end($steps))->toBeGreaterThan(85);
});

// ---- the history ------------------------------------------------------------------------------------------------------

it('counts the closed deals that reached each open stage, and how many of them were won', function () {
    ($this->closed)(['negotiation', 'quotation'], 'won');        // reached all three, won
    ($this->closed)(['negotiation'], 'lost');                    // reached discovery and negotiation, lost
    ($this->closed)([], 'lost');                                 // never left discovery, lost
    ($this->closed)(['quotation'], 'won');                       // went straight to a quotation
    ($this->closed)(['negotiation', 'quotation'], 'lost');       // reached all three, lost
    ($this->goes)(($this->deal)(), ['negotiation']);             // still open: counts for nothing yet

    expect(($this->history)())->toBe([
        'discovery' => ['n' => 5, 'won' => 2],
        'negotiation' => ['n' => 3, 'won' => 1],
        'quotation' => ['n' => 3, 'won' => 2],
    ]);
});

it('counts a deal once per stage however many times it passed through, and by how it ended', function () {
    $deal = ($this->deal)();
    ($this->goes)($deal, ['negotiation', 'discovery', 'negotiation', 'quotation'], 'lost');                // moved back and forth, then lost

    expect(($this->history)())->toBe(['discovery' => ['n' => 1, 'won' => 0], 'negotiation' => ['n' => 1, 'won' => 0], 'quotation' => ['n' => 1, 'won' => 0]]);

    DB::table('deals')->where('id', $deal->id)->update(['stage' => 'won']);                                  // re-opened and won in the end: it counts as won

    expect(($this->history)()['negotiation'])->toBe(['n' => 1, 'won' => 1]);
});

it('leaves out another company’s deals and a deal that has been deleted', function () {
    ($this->closed)(['negotiation'], 'won');
    $deleted = ($this->closed)(['negotiation'], 'won');
    DB::table('deals')->where('id', $deleted->id)->update(['deleted_at' => now()]);

    $theirLead = makeLead($this->globex, $this->theirBde, $this->nyc, [], [lineRow(makeUsableItem($this->globex), '10', '')]);
    $theirs = makeDeal($this->globex, $this->theirBde, $theirLead);
    ($this->goes)($theirs, ['negotiation'], 'won');

    expect(($this->history)()['negotiation'])->toBe(['n' => 1, 'won' => 1])
        ->and(($this->forecaster)()->history((int) $this->globex->id)['negotiation'])->toBe(['n' => 1, 'won' => 1]);
});

// ---- the forecast for one deal --------------------------------------------------------------------------------------

it('forecasts an open deal from its stage’s history, and says how much history there is', function () {
    foreach (range(1, 6) as $i) {
        ($this->closed)(['negotiation'], $i <= 4 ? 'won' : 'lost');      // 4 of 6 reaching negotiation were won
    }

    $open = ($this->goes)(($this->deal)(), ['negotiation']);
    $forecast = ($this->forecaster)()->forecast($open);

    // (4 + 10 × 0.5) ÷ (6 + 10) = 9 ÷ 16 = 56.25 → 56
    expect($forecast)->toBeInstanceOf(DealForecast::class)->and($forecast->stage)->toBe(DealStage::Negotiation)->and($forecast->percent)->toBe(56)
        ->and([$forecast->samples, $forecast->won, $forecast->prior])->toBe([6, 4, 50])->and($forecast->confidence)->toBe(DealForecast::THIN)
        ->and($forecast->confidenceLabel())->toBe('Thin history')
        ->and($forecast->weighted)->toBe(bcdiv(bcmul((string) $open->expected_value, '56', 4), '100', 2));
});

it('starts from the usual figure when nothing has closed, and calls the history thin, fair or good by the company’s counts', function () {
    $open = ($this->deal)();

    expect(($this->forecaster)()->forecast($open)->percent)->toBe(10)->and(($this->forecaster)()->forecast($open)->samples)->toBe(0);

    $at = fn (int $n) => ($this->forecaster)()->forecast($open, ['discovery' => ['n' => $n, 'won' => 0]])->confidence;

    expect([$at(0), $at(19), $at(20), $at(49), $at(50), $at(500)])->toBe(['thin', 'thin', 'fair', 'fair', 'good', 'good']);

    $this->settings->set('forecast.fair_from', 5, $this->acme->id);
    $this->settings->set('forecast.good_from', 8, $this->acme->id);

    expect([$at(4), $at(5), $at(7), $at(8)])->toBe(['thin', 'fair', 'fair', 'good']);

    $this->settings->set('forecast.fair_from', 30, $this->acme->id);     // a "fair" bar above the "good" one is brought down to it
    expect($at(8))->toBe('good')->and($at(7))->toBe('thin');
});

it('lets the company decide how stubborn the usual figure is', function () {
    $open = ($this->deal)();
    $history = ['discovery' => ['n' => 10, 'won' => 10]];

    expect(($this->forecaster)()->forecast($open, $history)->percent)->toBe(55);          // (10 + 10 × .1) ÷ 20

    $this->settings->set('forecast.prior_weight', 100, $this->acme->id);

    expect(($this->forecaster)()->forecast($open, $history)->percent)->toBe(18);          // (10 + 100 × .1) ÷ 110 = 18.2

    $this->settings->set('forecast.prior_weight', 1, $this->acme->id);

    expect(($this->forecaster)()->forecast($open, $history)->percent)->toBe(92);          // (10 + .1) ÷ 11
});

it('forecasts only an open deal, and only while the company wants it', function () {
    $won = ($this->closed)(['negotiation'], 'won');
    $lost = ($this->closed)([], 'lost');
    $open = ($this->deal)();

    expect(($this->forecaster)()->forecast($won))->toBeNull()->and(($this->forecaster)()->forecast($lost))->toBeNull()->and(($this->forecaster)()->forecast($open))->not->toBeNull();

    $this->settings->set('forecast.enabled', false, $this->acme->id);

    expect(($this->forecaster)()->forecast($open))->toBeNull()->and(($this->forecaster)()->enabled((int) $this->globex->id))->toBeTrue();
});

it('writes nothing: it does not touch the deal, its probability or its history', function () {
    $open = ($this->goes)(($this->deal)(), ['negotiation']);
    $before = [$open->fresh()->getAttributes(), DB::table('deal_stage_history')->count(), DB::table('deals')->count()];

    ($this->forecaster)()->forecast($open);
    ($this->forecaster)()->history((int) $this->acme->id);

    expect([$open->fresh()->getAttributes(), DB::table('deal_stage_history')->count(), DB::table('deals')->count()])->toBe($before);
});

// ---- on the deal's page --------------------------------------------------------------------------------------------------

it('shows the forecast beside the person’s own figure, with what it rests on', function () {
    foreach (range(1, 4) as $i) {
        ($this->closed)(['negotiation'], $i <= 3 ? 'won' : 'lost');
    }

    $open = ($this->goes)(($this->deal)(), ['negotiation']);
    DB::table('deals')->where('id', $open->id)->update(['probability' => 90]);

    // (3 + 5) ÷ (4 + 10) = 8 ÷ 14 = 57 %
    Livewire::actingAs($this->admin)->test(DealShow::class, ['deal' => $open->fresh()])
        ->assertSee('Forecast from history')->assertSee('Thin history')->assertSee('57%')->assertSee('90%')
        ->assertSee('Of 4 closed deals that reached Negotiation, 3 were won')->assertSee('too little history to lean on')
        ->assertSee('much lower than yours')->assertSee('not by an AI')->assertSee('never replaces it');
});

it('says so plainly when nothing has closed yet, and stays quiet about a gap that is small', function () {
    $open = ($this->deal)();

    Livewire::actingAs($this->admin)->test(DealShow::class, ['deal' => $open->fresh()])
        ->assertSee('Forecast from history')->assertSee('No closed deal has reached Discovery yet')->assertSee('usual 10% for the stage')->assertDontSee('worth a second look');
});

it('has no forecast card on a deal that is won or lost, or when the company has switched it off', function () {
    $won = ($this->closed)(['negotiation'], 'won');
    $open = ($this->deal)();

    Livewire::actingAs($this->admin)->test(DealShow::class, ['deal' => $won])->assertDontSee('Forecast from history');

    $this->settings->set('forecast.enabled', false, $this->acme->id);

    Livewire::actingAs($this->admin)->test(DealShow::class, ['deal' => $open->fresh()])->assertDontSee('Forecast from history')->assertSee($open->deal_number);
});

it('puts the forecast settings on the company’s settings screen', function () {
    Livewire::actingAs($this->admin)->test(General::class)
        ->assertSee('Deal forecast')->assertSee('Show a history-based chance of winning on a deal')->assertSee('How many closed deals the stage’s usual chance is worth');
});

it('leaves the pipeline totals on the salesperson’s figure', function () {
    $open = ($this->goes)(($this->deal)(), ['negotiation']);
    DB::table('deals')->where('id', $open->id)->update(['probability' => 40]);

    ($this->forecaster)()->forecast($open->fresh());

    expect($open->fresh()->probability)->toBe(40)
        ->and(bccomp((string) $open->fresh()->weightedValue(), bcdiv(bcmul((string) $open->expected_value, '40', 4), '100', 2), 2))->toBe(0);
});
