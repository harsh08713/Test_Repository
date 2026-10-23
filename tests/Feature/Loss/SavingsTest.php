<?php

use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Http\Livewire\SavingsList;
use App\Modules\Loss\Http\Livewire\SavingsShow;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Loss\Services\CapaService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Loss\Services\SavingsService;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Verified savings: a measurement compares the validated actual loss of the action's category and branch before and after, scaled for days or volume,
 * less the cost; Finance verifies it (not the maker, not the action's owner); nothing changes after it is sent; two measurements cannot claim the same loss;
 * a loss that comes back is flagged. Hand-calculated:
 *   baseline 21-08 to 19-09 (30 days) held ₹3,000 of loss; the action was done on 20-09; the window 20-09 to 12-10 (23 days) held ₹600.
 *   by days:   3,000 × 23/30 = 2,300; − 600 = 1,700; − cost 100 = 1,600.
 *   by volume: 1,000 units before, 400 in the window: 3,000 × 0.4 = 1,200; − 600 = 600; − cost 100 = 500.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    Notification::fake();
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);        // measures; also verifies corrective actions
    $this->investigator = makeUser(['Investigator'], [$this->acme]);
    $this->finance = makeUser(['Finance Controller'], [$this->acme]);
    $this->finance2 = makeUser(['Finance Controller'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);
    $this->outsider = makeUser(['Finance Controller'], [$this->globex]);

    $this->actingAs($this->admin);
    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);
    $this->category = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', 'line_stoppage')->firstOrFail();

    $this->as = function ($user, callable $call) {
        $this->actingAs($user);

        return $call();
    };

    // an incident of ₹$amount validated actual loss at a branch, on a date
    $this->incident = function (string $when, string $amount, $location = null) {
        $location ??= $this->ahd;
        $incident = ($this->as)($this->manager, fn () => app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $this->auto->id, 'loss_category_id' => (string) $this->category->id, 'location_id' => (string) $location->id, 'occurred_at' => $when.'T08:00',
            'severity' => 'medium', 'title' => 'Press line stopped', 'description' => 'Hydraulic failure.', 'custom_fields' => ['line' => 'Press line 3', 'shift' => 'C', 'downtime_hours' => '2'],
        ], $this->manager));
        $line = ($this->as)($this->investigator, fn () => app(LossCalculationService::class)->addLine($incident->fresh(), ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => $amount], 'assumptions' => 'Counted from the shift log'], $this->investigator));
        ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($line, $this->manager));

        return $incident->fresh();
    };

    // a corrective action on an incident, done and verified on the day the clock says
    $this->action = function (LossIncident $incident, $owner, string $result = '250.00') {
        $action = ($this->as)($this->manager, fn () => app(CapaService::class)->create($incident, [
            'action_type' => 'corrective', 'title' => 'Replace the hydraulic seal kit', 'owner_id' => (string) $owner->id, 'due_date' => '2026-10-30',
        ], $this->manager));
        ($this->as)($owner, fn () => app(CapaService::class)->start($action->fresh(), $owner));
        ($this->as)($owner, fn () => app(CapaService::class)->complete($action->fresh(), 'Seal kit replaced on press 3', $owner));

        return ($this->as)($this->manager, fn () => app(CapaService::class)->verify($action->fresh(), $result, 'Checked at the line', $this->manager));
    };

    // run something as it was on the day the first action was done (20 September), then put the clock back
    $this->past = function (callable $call) {
        $now = Carbon::now();
        Carbon::setTestNow('2026-09-20 11:00:00');

        try {
            return $call();
        } finally {
            Carbon::setTestNow($now);
        }
    };
    $this->input = fn (array $over = []) => [
        'baseline_from' => '2026-08-21', 'baseline_to' => '2026-09-19', 'measure_from' => '2026-09-20', 'measure_to' => '2026-10-12',
        'baseline_volume' => '', 'measure_volume' => '', 'volume_unit' => '', 'implementation_cost' => '100', 'evidence' => 'Shift logs and the loss register for the two periods', ...$over,
    ];
    $this->savings = fn () => app(SavingsService::class);

    // the baseline: ₹3,000 on 5 September; the action is done and verified on 20 September; then ₹600 in the window on 1 October
    $this->first = ($this->incident)('2026-09-05', '3000');
    $this->done = ($this->action)($this->first, $this->investigator);
    Carbon::setTestNow('2026-10-12 10:00:00');
    ($this->incident)('2026-10-01', '600');
    $this->start = fn (?CorrectiveAction $a = null, $by = null) => ($this->as)($by ?? $this->manager, fn () => ($this->savings)()->start($a ?? $this->done, $by ?? $this->manager));
    $this->send = function (SavingsMeasurement $m, array $over = []) {
        ($this->as)($this->manager, fn () => ($this->savings)()->update($m->fresh(), ($this->input)($over), $this->manager));

        return ($this->as)($this->manager, fn () => ($this->savings)()->submit($m->fresh(), $this->manager));
    };
});

afterEach(fn () => Carbon::setTestNow());

it('starts a draft from a verified action with the dates round the day it was done, and works the figures out from the loss records', function () {
    $m = ($this->start)();

    expect($m->state)->toBe('draft')->and($m->baseline_from->toDateString())->toBe('2026-08-21')->and($m->baseline_to->toDateString())->toBe('2026-09-19')
        ->and($m->measure_from->toDateString())->toBe('2026-09-20')->and($m->measure_to->toDateString())->toBe('2026-10-12')
        ->and($m->baseline_loss)->toBe('3000.00')->and($m->measure_loss)->toBe('600.00')->and($m->method)->toBe('days')
        ->and($m->normalised_baseline)->toBe('2300.00')->and($m->gross_saving)->toBe('1700.00')->and($m->net_saving)->toBe('1700.00')
        ->and($m->location_id)->toBe($this->ahd->id)->and($m->loss_category_id)->toBe($this->category->id);
});

it('scales the baseline by days or, when both volumes are given, by volume, and takes the cost off', function () {
    $m = ($this->start)();
    $byDays = ($this->as)($this->manager, fn () => ($this->savings)()->update($m->fresh(), ($this->input)(), $this->manager));

    expect($byDays->net_saving)->toBe('1600.00')->and($byDays->implementation_cost)->toBe('100.00');

    $byVolume = ($this->as)($this->manager, fn () => ($this->savings)()->update($m->fresh(), ($this->input)(['baseline_volume' => '1000', 'measure_volume' => '400', 'volume_unit' => 'units produced']), $this->manager));

    expect($byVolume->method)->toBe('volume')->and($byVolume->normalised_baseline)->toBe('1200.00')->and($byVolume->gross_saving)->toBe('600.00')->and($byVolume->net_saving)->toBe('500.00');
});

it('lets the saving be negative when the loss got worse', function () {
    ($this->incident)('2026-10-05', '9000');
    $m = ($this->send)(($this->start)());

    expect($m->measure_loss)->toBe('9600.00')->and($m->gross_saving)->toBe('-7300.00')->and($m->net_saving)->toBe('-7400.00');
});

it('takes a measurement through Finance: verified by someone other than the maker and the action’s owner, and counted apart from the action’s own result', function () {
    $m = ($this->send)(($this->start)());

    // Finance is told once it is sent; the maker and the action's owner are not
    Notification::assertSentTo($this->finance, LossAlertNotification::class, fn ($n) => $n->kind === 'savings_submitted');
    Notification::assertNotSentTo($this->manager, LossAlertNotification::class, fn ($n) => $n->kind === 'savings_submitted');
    Notification::assertNotSentTo($this->investigator, LossAlertNotification::class, fn ($n) => $n->kind === 'savings_submitted');

    expect($m->state)->toBe('submitted')->and($m->net_saving)->toBe('1600.00');

    expect(fn () => ($this->as)($this->manager, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->manager)))->toThrow(AuthorizationException::class)          // the maker
        ->and(fn () => ($this->as)($this->store, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->store)))->toThrow(AuthorizationException::class)         // no right
        ->and(fn () => ($this->as)($this->finance, fn () => ($this->savings)()->approve($m->fresh(), '', $this->finance)))->toThrow(ValidationException::class);              // no note

    ($this->as)($this->finance, fn () => ($this->savings)()->approve($m->fresh(), 'Checked the shift logs and the register', $this->finance));
    $fresh = $m->fresh();

    expect($fresh->state)->toBe('verified')->and($fresh->decided_by)->toBe($this->finance->id)->and($fresh->decision_notes)->toBe('Checked the shift logs and the register');

    $figures = ($this->as)($this->finance, fn () => app(LossFigures::class)->financeVerifiedSavings(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
    $capa = ($this->as)($this->manager, fn () => app(LossFigures::class)->forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')));

    expect($figures)->toBe(['net_saving' => '1600.00', 'count' => 1, 'recurred' => 0])
        ->and($capa['realised_savings'])->toBe('250.00');   // the action's own verified result stands alone: the two are never added
});

it('refuses the verification when the approver owns the action', function () {
    $action = ($this->past)(fn () => ($this->action)(($this->incident)('2026-09-06', '500'), $this->finance2));   // owned by a Finance Controller, who holds the approval right
    $m = ($this->send)(($this->start)($action), ['measure_from' => '2026-09-20']);

    expect(fn () => ($this->as)($this->finance2, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->finance2)))->toThrow(AuthorizationException::class);

    ($this->as)($this->finance, fn () => ($this->savings)()->reject($m->fresh(), 'The window includes a planned shutdown', $this->finance));
    expect($m->fresh()->state)->toBe('rejected');

    $reopened = ($this->as)($this->manager, fn () => ($this->savings)()->reopen($m->fresh(), $this->manager));
    expect($reopened->state)->toBe('draft')->and($reopened->decided_by)->toBeNull();
});

it('keeps a sent measurement as it was: no edit, no change to its figures, no delete', function () {
    $m = ($this->send)(($this->start)());

    expect(fn () => ($this->as)($this->manager, fn () => ($this->savings)()->update($m->fresh(), ($this->input)(['implementation_cost' => '0']), $this->manager)))->toThrow(AuthorizationException::class)
        ->and(fn () => $m->fresh()->forceFill(['net_saving' => '99999.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $m->fresh()->delete())->toThrow(LogicException::class);

    ($this->as)($this->finance, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->finance));

    expect(fn () => $m->fresh()->forceFill(['evidence' => 'Changed afterwards'])->save())->toThrow(LogicException::class)
        ->and(fn () => $m->fresh()->forceFill(['state' => 'draft'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->as)($this->finance2, fn () => ($this->savings)()->reject($m->fresh(), 'Too late', $this->finance2)))->toThrow(AuthorizationException::class);
});

it('refuses a measurement that is not sensible', function () {
    $m = ($this->start)();
    $try = fn (array $over, bool $send = false) => fn () => $send ? ($this->send)($m, $over) : ($this->as)($this->manager, fn () => ($this->savings)()->update($m->fresh(), ($this->input)($over), $this->manager));

    expect($try(['measure_from' => '2026-09-19', 'baseline_to' => '2026-09-18']))->toThrow(ValidationException::class)           // the window starts before the action was done
        ->and($try(['baseline_to' => '2026-09-25']))->toThrow(ValidationException::class)                                          // the baseline runs into the window
        ->and($try(['measure_to' => '2026-10-13']))->toThrow(ValidationException::class)                                           // in the future
        ->and($try(['baseline_from' => '2026-09-25', 'baseline_to' => '2026-09-20']))->toThrow(ValidationException::class)       // ends before it starts
        ->and($try(['baseline_volume' => '1000']))->toThrow(ValidationException::class)                                            // one volume only
        ->and($try(['baseline_volume' => '1000', 'measure_volume' => '400']))->toThrow(ValidationException::class)               // no unit
        ->and($try(['baseline_volume' => '0', 'measure_volume' => '5', 'volume_unit' => 'u']))->toThrow(ValidationException::class)
        ->and($try(['implementation_cost' => '-5']))->toThrow(ValidationException::class)
        ->and($try(['measure_from' => 'tomorrow']))->toThrow(ValidationException::class)
        ->and($try(['evidence' => '']))->not->toThrow(ValidationException::class)                                                  // a draft may lack evidence
        ->and($try(['evidence' => ''], true))->toThrow(ValidationException::class)                                                 // a submission may not
        ->and($try(['measure_from' => '2026-10-10'], true))->toThrow(ValidationException::class)                                   // a 3-day window is too short
        ->and($m->fresh()->state)->toBe('draft');
});

it('starts only from an action somebody has verified, and only once', function () {
    $open = ($this->as)($this->manager, fn () => app(CapaService::class)->create(($this->incident)('2026-09-07', '100'), [
        'action_type' => 'preventive', 'title' => 'Add a sensor', 'owner_id' => (string) $this->investigator->id, 'due_date' => '2026-10-30',
    ], $this->manager));

    expect(fn () => ($this->start)($open))->toThrow(ValidationException::class)
        ->and(fn () => ($this->start)($this->done, $this->store))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->start)($this->done, $this->finance))->toThrow(AuthorizationException::class);                       // Finance verifies, it does not measure

    ($this->start)();

    expect(fn () => ($this->start)())->toThrow(ValidationException::class)
        ->and(SavingsMeasurement::query()->count())->toBe(1);
});

it('will not let two measurements claim the same loss: windows of the same category and branch may not overlap', function () {
    $mine = ($this->send)(($this->start)());
    $other = ($this->past)(fn () => ($this->action)(($this->incident)('2026-09-08', '400'), $this->investigator));
    $clash = ($this->start)($other);

    expect(fn () => ($this->send)($clash))->toThrow(ValidationException::class);          // 20-09 to 12-10 overlaps the first

    $m2 = ($this->as)($this->manager, fn () => ($this->savings)()->update($clash->fresh(), ($this->input)(['measure_from' => '2026-09-20', 'measure_to' => '2026-10-12']), $this->manager));
    ($this->as)($this->finance, fn () => ($this->savings)()->reject($mine->fresh(), 'Not convincing', $this->finance));
    $sent = ($this->as)($this->manager, fn () => ($this->savings)()->submit($m2->fresh(), $this->manager));   // the rejected one no longer claims it

    expect($sent->state)->toBe('submitted');
});

it('flags a verified saving once when the same loss comes back at the same branch after its window', function () {
    $m = ($this->send)(($this->start)());
    ($this->as)($this->finance, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->finance));

    expect(($this->savings)()->sweepRecurrence())->toBe(0);

    ($this->incident)('2026-10-11', '300', $this->mum);   // another branch, and inside the window anyway
    Carbon::setTestNow('2026-10-25 10:00:00');
    expect(($this->savings)()->sweepRecurrence())->toBe(0);

    $back = ($this->incident)('2026-10-20', '700');
    expect(($this->savings)()->sweepRecurrence())->toBe(1)->and(($this->savings)()->sweepRecurrence())->toBe(0);

    $fresh = $m->fresh();
    expect($fresh->recurred_at)->not->toBeNull()->and($fresh->recurrence_incident_id)->toBe($back->id)->and($fresh->state)->toBe('verified')->and($fresh->net_saving)->toBe('1600.00');

    $figures = ($this->as)($this->finance, fn () => app(LossFigures::class)->financeVerifiedSavings(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
    expect($figures['recurred'])->toBe(1);

    // the maker and Finance are told once that it came back
    Notification::assertSentTo($this->manager, LossAlertNotification::class, fn ($n) => $n->kind === 'savings_recurred');
    Notification::assertSentTo($this->finance, LossAlertNotification::class, fn ($n) => $n->kind === 'savings_recurred');
});

it('shows the screens to those who may see them, and only for their own company', function () {
    $m = ($this->start)();

    Livewire::actingAs($this->store)->test(SavingsList::class)->assertForbidden();
    Livewire::actingAs($this->outsider)->test(SavingsShow::class, ['measurement' => $m->id])->assertNotFound();

    Livewire::actingAs($this->manager)->test(SavingsList::class)->set('show', 'awaiting')->assertSee('Every verified action has been measured.');
    Livewire::actingAs($this->manager)->test(SavingsList::class)->set('show', 'draft')->assertSeeHtml('wire:key="sv-'.$m->id.'"');

    // typing the draft, sending it, and Finance verifying it, all from the page
    Livewire::actingAs($this->manager)->test(SavingsShow::class, ['measurement' => $m->id])
        ->set('form.implementation_cost', '100')->set('form.evidence', 'Shift logs and the loss register')->assertSee('1,600.00')
        ->call('submitForFinance')->assertHasNoErrors();

    expect($m->fresh()->state)->toBe('submitted');

    Livewire::actingAs($this->finance)->test(SavingsShow::class, ['measurement' => $m->id])->call('verify')->assertHasErrors('notes')
        ->set('notes', 'Checked the register')->call('verify')->assertHasNoErrors();

    expect($m->fresh()->state)->toBe('verified');
});

it('puts the radar and the verified savings on the dashboard and in the reports, only for those who may see them', function () {
    $m = ($this->send)(($this->start)());
    ($this->as)($this->finance, fn () => ($this->savings)()->approve($m->fresh(), 'Checked', $this->finance));
    inCompany($this->acme, fn () => (new RadarFinding)->forceFill([
        'company_id' => $this->acme->id, 'location_id' => $this->ahd->id, 'detector' => 'scrap', 'source_type' => 'stock_movement', 'source_id' => 1, 'title' => 'Scrapped: Hex Bolt M10',
        'severity' => 'high', 'impact_type' => 'actual', 'estimated_amount' => '500.00', 'evidence' => [], 'state' => 'open', 'first_detected_at' => now(), 'last_seen_at' => now(),
    ])->save());

    $run = fn ($user, string $key, array $filters = []) => inCompany($this->acme, function () use ($user, $key, $filters) {
        $this->actingAs($user);
        $report = app(ReportCatalog::class)->find($key);
        $input = ReportInput::resolve($report, $filters, $user);

        return ['rows' => iterator_to_array($report->rows($input, $user), false), 'summary' => $report->summary($input, $user)];
    });

    $savings = $run($this->finance, 'verified-savings');
    expect($savings['rows'])->toHaveCount(1)->and($savings['summary']['Finance-verified net saving (₹)'])->toBe('1,600.00')
        ->and($run($this->finance, 'verified-savings', ['choice' => 'submitted'])['rows'])->toHaveCount(0);

    $radar = $run($this->manager, 'radar-findings');
    expect($radar['rows'])->toHaveCount(1)->and($radar['summary']['Estimated (₹, not a loss)'])->toBe('500.00')
        ->and($run($this->manager, 'radar-findings', ['choice' => 'dismissed'])['rows'])->toHaveCount(0);

    $this->actingAs($this->store)->get(route('reports.run', 'verified-savings'))->assertForbidden();
    $this->actingAs($this->finance)->get(route('reports.run', 'radar-findings'))->assertForbidden();   // Finance verifies savings; it does not review the radar
    $this->actingAs($this->finance)->get(route('reports.run', 'verified-savings'))->assertOk();
    $this->actingAs($this->manager)->get(route('reports.run', 'radar-findings'))->assertOk();

    // the dashboard: each part only for who may see it, and the estimate is labelled as one
    $this->actingAs($this->manager)->get('/executive')->assertOk()->assertSee('Radar findings waiting')->assertSee('estimate · not a loss')->assertSee('Finance-verified savings');
    $this->actingAs($this->finance)->get('/executive')->assertOk()->assertSee('Finance-verified savings')->assertDontSee('Radar findings waiting');
    $this->actingAs($this->store)->get('/executive')->assertForbidden();
});
