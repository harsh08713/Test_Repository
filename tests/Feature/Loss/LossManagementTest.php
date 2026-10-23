<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Http\Livewire\CorrectiveActionList;
use App\Modules\Loss\Http\Livewire\LossIncidentForm;
use App\Modules\Loss\Http\Livewire\LossIncidentList;
use App\Modules\Loss\Http\Livewire\LossIncidentShow;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\WorkflowEvent;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Loss\Services\CapaService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Loss\Services\LossWorkflowService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Loss management: reporting under an industry template, immediate escalation, the impact formulas (hand-calculated), the four values kept apart,
 * double counting prevented, the workflow with its conditions and maker–checker, corrective actions and verified savings, losses reported from
 * operational records, and the screens.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->reporter = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);
    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->manager2 = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->investigator = makeUser(['Investigator'], [$this->acme]);
    $this->capaOwner = makeUser(['CAPA Owner'], [$this->acme]);
    $this->sales = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    $this->outsider = makeUser(['Quality/Loss Manager'], [$this->globex]);

    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $this->retail = IndustryTemplate::query()->where('code', 'retail')->firstOrFail();
    $this->actingAs($this->admin);
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);

    $this->category = fn (string $code, ?IndustryTemplate $t = null) => LossCategory::query()->where('industry_template_id', ($t ?? $this->auto)->id)->where('code', $code)->firstOrFail();
    $this->input = fn (array $over = []) => [
        'industry_template_id' => (string) $this->auto->id, 'loss_category_id' => (string) ($this->category)('line_stoppage')->id, 'location_id' => (string) $this->ahd->id,
        'occurred_at' => '2026-10-11T22:15', 'severity' => 'medium', 'title' => 'Press line 3 stopped', 'description' => 'Hydraulic failure on press 3, night shift.',
        'custom_fields' => ['line' => 'Press line 3', 'shift' => 'C', 'downtime_hours' => '2.5'], ...$over,
    ];
    $this->report = function (array $over = [], $by = null) {
        $by ??= $this->reporter;
        $this->actingAs($by);

        return app(LossIncidentService::class)->create(($this->input)($over), $by);
    };
    $this->as = function ($user, callable $call) {
        $this->actingAs($user);

        return $call();
    };
    $this->line = fn (LossIncident $i, string $type, string $key, array $inputs, array $more = [], $by = null) => ($this->as)($by ?? $this->investigator,
        fn () => app(LossCalculationService::class)->addLine($i->fresh(), ['impact_type' => $type, 'formula_key' => $key, 'inputs' => $inputs, ...$more], $by ?? $this->investigator));
    $this->move = fn (LossIncident $i, string $to, $by, ?string $comment = null) => ($this->as)($by, fn () => app(LossWorkflowService::class)->transition($i->fresh(), $to, $comment, $by));
    $this->figures = fn () => ($this->as)($this->manager, fn () => app(LossFigures::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));
});

afterEach(fn () => Carbon::setTestNow());

// ---- reporting -----------------------------------------------------------------------------------------------------------------------

it('reports a numbered loss under the template version the company uses, with its extra fields checked', function () {
    $i = ($this->report)();

    expect($i->incident_number)->toBe('LI/AHD/26-27/00001')->and($i->state)->toBe('draft')->and($i->template_version)->toBe(1)
        ->and($i->reporter_id)->toBe($this->reporter->id)->and($i->custom_fields)->toEqualCanonicalizing(['line' => 'Press line 3', 'shift' => 'C', 'downtime_hours' => '2.5'])
        ->and($i->occurred_at->format('Y-m-d H:i'))->toBe('2026-10-11 22:15')->and($i->escalated_at)->toBeNull()
        ->and(WorkflowEvent::query()->where('subject_id', $i->id)->sole()->action)->toBe('reported');
    Notification::assertNothingSent();
});

it('refuses a report that does not fit the template or the person', function () {
    $try = fn (array $over) => fn () => ($this->report)($over);

    expect($try(['custom_fields' => ['shift' => 'C']]))->toThrow(ValidationException::class)                                       // the template's required "line"
        ->and($try(['custom_fields' => ['line' => 'L1', 'surprise' => 'x']]))->toThrow(ValidationException::class)                   // a field the template does not have
        ->and($try(['loss_category_id' => (string) ($this->category)('shrinkage', $this->retail)->id]))->toThrow(ValidationException::class)   // another template's category
        ->and($try(['industry_template_id' => (string) $this->retail->id, 'loss_category_id' => (string) ($this->category)('shrinkage', $this->retail)->id]))->toThrow(ValidationException::class)   // not switched on
        ->and($try(['location_id' => (string) $this->mum->id]))->toThrow(ValidationException::class)                                // not a branch the reporter works in
        ->and($try(['occurred_at' => '2026-10-13T08:00']))->toThrow(ValidationException::class)                                     // in the future
        ->and($try(['severity' => 'apocalyptic']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->as)($this->sales, fn () => app(LossIncidentService::class)->create(($this->input)(), $this->sales)))->toThrow(AuthorizationException::class)
        ->and(LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('escalates a safety, quality, compliance or critical loss to the loss managers at once, and only once', function () {
    $i = ($this->report)(['is_safety' => true]);

    expect($i->escalated_at)->not->toBeNull();
    Notification::assertSentTo([$this->manager, $this->manager2, $this->admin], LossAlertNotification::class, fn ($n) => $n->kind === 'loss_escalated' && str_contains($n->message, 'safety'));
    Notification::assertNotSentTo([$this->reporter, $this->sales, $this->outsider], LossAlertNotification::class);

    expect(app(LossIncidentService::class)->escalate($i))->toBeFalse();
    Notification::assertSentToTimes($this->manager, LossAlertNotification::class, 1);

    ($this->report)(['severity' => 'critical']);
    Notification::assertSentToTimes($this->manager, LossAlertNotification::class, 2);
});

// ---- the formulas ---------------------------------------------------------------------------------------------------------------------

it('works each formula out exactly, rounding once to paise', function () {
    $calc = app(LossCalculationService::class);
    $amount = fn (string $key, array $in) => $calc->calculate($key, $in)['amount'];

    expect($amount('material_cost', ['quantity' => '12.5', 'unit_cost' => '80.25']))->toBe('1003.13')                   // 1003.125 → half up
        ->and($amount('scrap_net', ['quantity' => '10', 'unit_cost' => '100', 'salvage_value' => '150']))->toBe('850.00')
        ->and($amount('expiry', ['quantity' => '40', 'unit_cost' => '1.1']))->toBe('44.00')
        ->and($amount('downtime', ['hours' => '2.5', 'cost_per_hour' => '4000', 'lost_output' => '100', 'margin_per_unit' => '12.5']))->toBe('11250.00')
        ->and($amount('downtime', ['hours' => '2.5', 'cost_per_hour' => '4000']))->toBe('10000.00')
        ->and($amount('rework', ['hours' => '6', 'labour_rate' => '350', 'extra_material' => '420.50']))->toBe('2520.50')
        ->and($amount('warranty', ['claims' => '3', 'average_claim_cost' => '15000']))->toBe('45000.00')
        ->and($amount('markdown', ['original_price' => '499', 'markdown_price' => '399', 'quantity' => '20']))->toBe('2000.00')
        ->and($amount('shrinkage', ['book_qty' => '120', 'physical_qty' => '117', 'unit_cost' => '45.5']))->toBe('136.50')
        ->and($amount('billing_leakage', ['billable_value' => '18500', 'billed_value' => '16250']))->toBe('2250.00')
        ->and($amount('energy_excess', ['actual_units' => '5200', 'standard_units' => '4800', 'tariff' => '8.75']))->toBe('3500.00')
        ->and($amount('sla_penalty', ['penalty_per_breach' => '2500', 'breaches' => '4']))->toBe('10000.00')
        ->and($amount('manual', ['amount' => '7777.77']))->toBe('7777.77');

    expect(fn () => $calc->calculate('shrinkage', ['book_qty' => '100', 'physical_qty' => '101', 'unit_cost' => '5']))->toThrow(ValidationException::class)   // negative
        ->and(fn () => $calc->calculate('material_cost', ['quantity' => '-1', 'unit_cost' => '5']))->toThrow(ValidationException::class)
        ->and(fn () => $calc->calculate('warranty', ['claims' => '1.5', 'average_claim_cost' => '5']))->toThrow(ValidationException::class)
        ->and(fn () => $calc->calculate('material_cost', ['quantity' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => $calc->calculate('magic', []))->toThrow(ValidationException::class);
});

it('insists that an opportunity and a hand-entered figure say how they were arrived at', function () {
    $i = ($this->report)();

    expect(fn () => ($this->line)($i, 'opportunity', 'material_cost', ['quantity' => '1', 'unit_cost' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->line)($i, 'opportunity', 'material_cost', ['quantity' => '1', 'unit_cost' => '1'], ['assumptions' => 'Better tooling', 'confidence_pct' => '150', 'target_period' => 'Q4']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->line)($i, 'actual', 'manual', ['amount' => '100']))->toThrow(ValidationException::class);

    $ok = ($this->line)($i, 'opportunity', 'material_cost', ['quantity' => '100', 'unit_cost' => '20'], ['assumptions' => 'Better tooling', 'confidence_pct' => '60', 'target_period' => 'Q4 FY26-27']);
    expect((string) $ok->amount)->toBe('2000.00')->and($ok->formula_version)->toBe(1)->and($ok->inputs)->toBe(['quantity' => '100', 'unit_cost' => '20']);
});

// ---- the four values, kept apart and counted once ---------------------------------------------------------------------------------------

it('keeps actual loss, exposure, opportunity and savings apart, counting actual loss only once validated', function () {
    $i = ($this->report)();
    $actual = ($this->line)($i, 'actual', 'downtime', ['hours' => '2.5', 'cost_per_hour' => '4000']);                                    // 10,000
    ($this->line)($i, 'exposure', 'warranty', ['claims' => '2', 'average_claim_cost' => '15000']);                                     // 30,000
    ($this->line)($i, 'opportunity', 'rework', ['hours' => '10', 'labour_rate' => '300'], ['assumptions' => 'Quick-change dies', 'confidence_pct' => '70', 'target_period' => 'Q4']);   // 3,000

    expect(($this->figures)())->toMatchArray(['actual_loss' => '0.00', 'estimated_exposure' => '30000.00', 'recoverable_opportunity' => '3000.00', 'realised_savings' => '0.00']);

    // the person who entered a figure cannot validate it; someone else can
    expect(fn () => ($this->as)($this->investigator, fn () => app(LossCalculationService::class)->validateLine($actual, $this->investigator)))->toThrow(AuthorizationException::class);
    ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($actual, $this->manager));

    expect(($this->figures)())->toMatchArray(['actual_loss' => '10000.00', 'estimated_exposure' => '30000.00', 'recoverable_opportunity' => '3000.00']);
});

it('never lets a manager validate their own figure', function () {
    $i = ($this->report)();
    $own = ($this->line)($i, 'actual', 'manual', ['amount' => '500'], ['assumptions' => 'Counted by hand'], $this->manager);

    expect(fn () => ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($own, $this->manager)))->toThrow(ValidationException::class, 'other than');
});

it('recalculates by superseding, never editing, and counts only the current figure', function () {
    $i = ($this->report)();
    $first = ($this->line)($i, 'actual', 'material_cost', ['quantity' => '10', 'unit_cost' => '100']);
    $second = ($this->as)($this->investigator, fn () => app(LossCalculationService::class)->recalculate($first, ['inputs' => ['quantity' => '12', 'unit_cost' => '100']], $this->investigator));

    expect($first->fresh()->superseded_at)->not->toBeNull()->and($first->fresh()->superseded_by_id)->toBe($second->id)->and((string) $first->fresh()->amount)->toBe('1000.00')
        ->and((string) $second->amount)->toBe('1200.00')->and($second->validation_state)->toBe('pending')
        ->and(fn () => $first->fresh()->forceFill(['amount' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => $first->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => ($this->as)($this->investigator, fn () => app(LossCalculationService::class)->recalculate($first->fresh(), ['inputs' => ['quantity' => '1', 'unit_cost' => '1']], $this->investigator)))->toThrow(ValidationException::class);

    ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($second, $this->manager));
    expect(($this->figures)()['actual_loss'])->toBe('1200.00');
});

it('counts a loss reported twice from the same record once, and nothing of a rejected figure or a rejected incident', function () {
    $item = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '5']], 'approved');
    $scrap = ($this->as)($this->admin, function () use ($item) {
        app(StockService::class)->receive($item, $this->ahd->id, '100', '5', null, 'test', 1, 1);

        return app(StockService::class)->scrap($item, $this->ahd->id, '40', null, 'Crushed by the forklift', $this->admin);
    });

    $first = ($this->report)(['source_type' => 'stock_movement', 'source_id' => (string) $scrap->id]);
    $second = ($this->report)(['source_type' => 'stock_movement', 'source_id' => (string) $scrap->id]);

    expect($first->primary_incident_id)->toBeNull()->and($second->primary_incident_id)->toBe($first->id)->and($second->isDuplicate())->toBeTrue();

    foreach ([$first, $second] as $inc) {
        $l = ($this->line)($inc, 'actual', 'material_cost', ['quantity' => '40', 'unit_cost' => '5']);
        ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($l, $this->manager));
    }
    expect(($this->figures)()['actual_loss'])->toBe('200.00');   // not 400

    $rejectedLine = ($this->line)($first, 'actual', 'manual', ['amount' => '999'], ['assumptions' => 'A guess']);
    ($this->as)($this->manager, fn () => app(LossCalculationService::class)->rejectLine($rejectedLine, 'Not supported by the count', $this->manager));
    expect(($this->figures)()['actual_loss'])->toBe('200.00');

    // a rejected incident counts for nothing
    ($this->move)($first, 'submitted', $this->reporter);
    ($this->move)($first, 'rejected', $this->manager, 'Duplicate of a warranty case');
    expect(($this->figures)()['actual_loss'])->toBe('0.00');
});

it('refuses to report a record that is not a loss, or not one of yours', function () {
    $item = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '5']], 'approved');
    $receipt = ($this->as)($this->admin, fn () => app(StockService::class)->receive($item, $this->ahd->id, '100', '5', null, 'test', 1, 1));

    expect(fn () => ($this->report)(['source_type' => 'stock_movement', 'source_id' => (string) $receipt->id]))->toThrow(FieldRuleException::class)   // a receipt is not a loss
        ->and(fn () => ($this->report)(['source_type' => 'stock_movement', 'source_id' => '999999']))->toThrow(FieldRuleException::class)
        ->and(fn () => ($this->report)(['source_type' => 'invoice', 'source_id' => '1']))->toThrow(FieldRuleException::class);
});

it('works out the Pareto of actual loss by category', function () {
    $amounts = [['line_stoppage', '6000'], ['component_defect', '3000'], ['line_stoppage', '1000']];
    foreach ($amounts as $n => [$code, $amount]) {
        $i = ($this->report)(['loss_category_id' => (string) ($this->category)($code)->id, 'title' => "Loss {$n}"]);
        $l = ($this->line)($i, 'actual', 'manual', ['amount' => $amount], ['assumptions' => 'Counted']);
        ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($l, $this->manager));
    }

    $pareto = ($this->as)($this->manager, fn () => app(LossFigures::class)->pareto(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));

    expect($pareto)->toHaveCount(2)
        ->and($pareto[0])->toMatchArray(['category' => 'Line stoppage', 'amount' => '7000.00', 'incidents' => 2, 'cumulative_pct' => '70.00'])
        ->and($pareto[1])->toMatchArray(['category' => 'Component defect', 'amount' => '3000.00', 'incidents' => 1, 'cumulative_pct' => '100.00']);
});

// ---- the workflow ---------------------------------------------------------------------------------------------------------------------

it('moves an incident through its workflow only by allowed moves, each with its conditions, recorded', function () {
    $i = ($this->report)();

    expect(fn () => ($this->move)($i, 'closed', $this->manager))->toThrow(ValidationException::class, 'not possible')
        ->and(fn () => ($this->move)($i, 'submitted', $this->sales))->toThrow(ValidationException::class);   // no permission

    ($this->move)($i, 'submitted', $this->reporter);
    ($this->move)($i, 'triage', $this->manager);
    expect(fn () => ($this->move)($i, 'investigation', $this->manager))->toThrow(ValidationException::class, 'investigator');

    ($this->as)($this->manager, fn () => app(LossIncidentService::class)->assign($i->fresh(), $this->investigator->id, $this->manager->id, $this->manager));
    Notification::assertSentTo($this->investigator, LossAlertNotification::class, fn ($n) => $n->kind === 'loss_assigned');
    ($this->move)($i, 'investigation', $this->investigator);

    expect(fn () => ($this->move)($i, 'action_required', $this->investigator))->toThrow(ValidationException::class, 'root cause');
    ($this->as)($this->investigator, fn () => app(LossIncidentService::class)->recordRootCause($i->fresh(), [
        'method' => 'five_whys', 'whys' => ['Press stopped', 'Hydraulic pressure fell', 'Seal failed', 'Seal past its life', 'No replacement schedule'], 'root_cause' => 'No preventive replacement of hydraulic seals',
    ], $this->investigator));
    ($this->move)($i, 'action_required', $this->investigator);

    expect(fn () => ($this->move)($i, 'verification', $this->investigator))->toThrow(ValidationException::class, 'at least one');
    $action = ($this->as)($this->investigator, fn () => app(CapaService::class)->create($i->fresh(), [
        'action_type' => 'preventive', 'title' => 'Replace press seals every 2,000 hours', 'owner_id' => (string) $this->capaOwner->id, 'due_date' => '2026-10-30', 'expected_benefit_amount' => '40000',
    ], $this->investigator));
    expect(fn () => ($this->move)($i, 'verification', $this->investigator))->toThrow(ValidationException::class, 'not done');

    ($this->as)($this->capaOwner, fn () => app(CapaService::class)->complete($action, 'Schedule in the CMMS; seals replaced on all presses', $this->capaOwner));
    ($this->move)($i, 'verification', $this->investigator);

    $figure = ($this->line)($i, 'actual', 'downtime', ['hours' => '2.5', 'cost_per_hour' => '4000']);
    expect(fn () => ($this->move)($i, 'closed', $this->manager))->toThrow(ValidationException::class, 'verified');
    ($this->as)($this->manager2, fn () => app(CapaService::class)->verify($action->fresh(), '12000', 'Two months without a seal failure', $this->manager2));
    expect(fn () => ($this->move)($i, 'closed', $this->manager))->toThrow(ValidationException::class, 'validated');
    ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($figure, $this->manager));

    $closed = ($this->move)($i, 'closed', $this->manager);
    expect($closed->state)->toBe('closed')->and($closed->closed_at)->not->toBeNull()
        ->and(WorkflowEvent::query()->where('subject_type', 'loss_incident')->where('subject_id', $i->id)->pluck('to_state')->all())
        ->toBe(['draft', 'submitted', 'triage', 'investigation', 'action_required', 'verification', 'closed']);

    // closed: nothing changes, no new actions, until it is re-opened with a reason
    expect(app(LossWorkflowService::class)->isFinal($closed))->toBeTrue()
        ->and(($this->as)($this->manager, fn () => auth()->user()->can('update', $closed)))->toBeFalse()
        ->and(fn () => ($this->as)($this->investigator, fn () => app(CapaService::class)->create($closed, ['action_type' => 'corrective', 'title' => 'More', 'owner_id' => (string) $this->capaOwner->id, 'due_date' => '2026-10-30'], $this->investigator)))->toThrow(ValidationException::class)
        ->and(fn () => ($this->move)($closed, 'reopened', $this->manager))->toThrow(ValidationException::class);   // needs a reason

    $reopened = ($this->move)($closed, 'reopened', $this->manager, 'Seal failed again on press 4');
    expect($reopened->closed_at)->toBeNull();
});

it('needs approval by someone other than the reporter to close', function () {
    $i = ($this->report)([], $this->manager);   // the manager reported it
    DB::table('loss_incidents')->where('id', $i->id)->update(['state' => 'verification']);

    expect(fn () => ($this->move)($i, 'closed', $this->manager))->toThrow(ValidationException::class, 'other than')
        ->and(($this->as)($this->manager, fn () => app(LossWorkflowService::class)->moves($i->fresh(), $this->manager)->pluck('to_state')->all()))->not->toContain('closed');

    expect(($this->move)($i, 'closed', $this->manager2)->state)->toBe('closed');
});

// ---- corrective actions ----------------------------------------------------------------------------------------------------------------

it('runs a corrective action from open to verified, with its saving counted once verified and only by someone other than its owner', function () {
    $i = ($this->report)();
    $a = ($this->as)($this->investigator, fn () => app(CapaService::class)->create($i, [
        'action_type' => 'corrective', 'title' => 'Rebuild press 3 hydraulics', 'owner_id' => (string) $this->capaOwner->id, 'approver_id' => (string) $this->manager->id,
        'due_date' => '2026-10-20', 'expected_benefit_amount' => '25000', 'baseline_value' => '10000',
    ], $this->investigator));

    expect($a->action_number)->toBe('CA/AHD/26-27/00001')->and($a->state)->toBe('open');

    $capa = app(CapaService::class);
    ($this->as)($this->capaOwner, fn () => $capa->start($a, $this->capaOwner));
    ($this->as)($this->capaOwner, fn () => $capa->complete($a->fresh(), 'Pump and seals rebuilt', $this->capaOwner));

    expect(fn () => ($this->as)($this->capaOwner, fn () => $capa->verify($a->fresh(), '9000', 'Looks good', $this->capaOwner)))->toThrow(AuthorizationException::class)   // its owner
        ->and(fn () => ($this->as)($this->manager2, fn () => $capa->verify($a->fresh(), '9000', 'Looks good', $this->manager2)))->toThrow(AuthorizationException::class)    // not the named approver
        ->and(fn () => ($this->as)($this->manager, fn () => $capa->verify($a->fresh(), 'lots', 'Looks good', $this->manager)))->toThrow(ValidationException::class);

    ($this->as)($this->manager, fn () => $capa->sendBack($a->fresh(), 'No pressure test record attached', $this->manager));
    expect($a->fresh()->state)->toBe('in_progress')->and(($this->figures)()['realised_savings'])->toBe('0.00');

    ($this->as)($this->capaOwner, fn () => $capa->complete($a->fresh(), 'Pressure test passed, record attached', $this->capaOwner));
    ($this->as)($this->manager, fn () => $capa->verify($a->fresh(), '9000.50', 'Three weeks of logs checked', $this->manager));

    expect($a->fresh())->state->toBe('verified')->and((string) $a->fresh()->verified_result_amount)->toBe('9000.50')
        ->and(($this->figures)()['realised_savings'])->toBe('9000.50')
        ->and(WorkflowEvent::query()->where('subject_type', 'corrective_action')->where('subject_id', $a->id)->pluck('action')->all())->toBe(['created', 'start', 'complete', 'send_back', 'complete', 'verify']);
});

it('escalates an overdue action once', function () {
    $i = ($this->report)();
    $a = ($this->as)($this->investigator, fn () => app(CapaService::class)->create($i, ['action_type' => 'corrective', 'title' => 'Fix', 'owner_id' => (string) $this->capaOwner->id, 'approver_id' => (string) $this->manager->id, 'due_date' => '2026-10-15'], $this->investigator));

    expect(app(CapaService::class)->escalateOverdue())->toBe(0);   // not due yet

    Carbon::setTestNow('2026-10-17 09:00:00');
    expect($a->fresh()->isOverdue())->toBeTrue();
    $this->artisan('loss:escalate')->assertSuccessful();
    expect(app(CapaService::class)->escalateOverdue())->toBe(0);

    Notification::assertSentToTimes($this->capaOwner, LossAlertNotification::class, 1);
    Notification::assertSentTo($this->manager, LossAlertNotification::class, fn ($n) => $n->kind === 'capa_overdue');
});

it('checks an action’s owner, approver and dates', function () {
    $i = ($this->report)();
    $make = fn (array $over) => fn () => ($this->as)($this->investigator, fn () => app(CapaService::class)->create($i, [
        'action_type' => 'corrective', 'title' => 'Fix it', 'owner_id' => (string) $this->capaOwner->id, 'due_date' => '2026-10-20', ...$over,
    ], $this->investigator));

    expect($make(['owner_id' => (string) $this->sales->id]))->toThrow(ValidationException::class)                 // cannot work actions
        ->and($make(['owner_id' => (string) $this->outsider->id]))->toThrow(ValidationException::class)              // another company
        ->and($make(['approver_id' => (string) $this->capaOwner->id]))->toThrow(ValidationException::class)          // the owner, and cannot approve
        ->and($make(['due_date' => '2026-10-01']))->toThrow(ValidationException::class)
        ->and($make(['action_type' => 'magic']))->toThrow(ValidationException::class);
});

// ---- the screens --------------------------------------------------------------------------------------------------------------------------

it('reports a loss from the form, with the template’s fields, and from a record with its figure suggested', function () {
    Livewire::actingAs($this->reporter)->test(LossIncidentForm::class)
        ->assertOk()->assertSee('Production line')
        ->set('incident.loss_category_id', (string) ($this->category)('component_defect')->id)->set('incident.location_id', (string) $this->ahd->id)
        ->set('incident.title', 'Bad batch of brackets')->set('incident.description', 'Holes drilled off-centre')
        ->call('save')->assertHasErrors(['custom.line'])
        ->set('custom.line', 'Body shop')->call('save')->assertHasNoErrors()->assertRedirect();

    expect(LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->sole()->custom_fields)->toBe(['line' => 'Body shop']);

    $item = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '5']], 'approved');
    $scrap = ($this->as)($this->admin, function () use ($item) {
        app(StockService::class)->receive($item, $this->ahd->id, '100', '5', null, 'test', 1, 1);

        return app(StockService::class)->scrap($item, $this->ahd->id, '40', null, 'Crushed by the forklift', $this->admin);
    });

    Livewire::actingAs($this->reporter)->withQueryParams(['source' => 'stock_movement', 'id' => (string) $scrap->id])->test(LossIncidentForm::class)
        ->assertOk()->assertSee('Reported from')->assertSet('incident.quantity', '40')->assertSet('impact.formula_key', 'material_cost')->assertSet('impact.inputs.unit_cost', '5')
        ->set('incident.loss_category_id', (string) ($this->category)('component_defect')->id)->set('custom.line', 'Stores')
        ->call('save')->assertHasNoErrors();

    $reported = LossIncident::withoutGlobalScopes(Tenancy::SCOPES)->where('source_type', 'stock_movement')->sole();
    expect((string) LossImpactLine::query()->where('loss_incident_id', $reported->id)->sole()->amount)->toBe('200.00');
});

it('works an incident from its page', function () {
    $i = ($this->report)();

    Livewire::actingAs($this->reporter)->test(LossIncidentShow::class, ['incident' => $i])
        ->assertOk()->assertSee('Press line 3 stopped')->assertSee('Actual loss')->assertSee('Estimated exposure')
        ->call('openDialog', 'move', 'submitted')->call('makeMove')->assertHasNoErrors();
    expect($i->fresh()->state)->toBe('submitted');

    Livewire::actingAs($this->manager)->test(LossIncidentShow::class, ['incident' => $i->fresh()])
        ->set('investigatorId', (string) $this->investigator->id)->call('saveAssignment')->assertHasNoErrors()
        ->call('openDialog', 'impact')->set('line.formula_key', 'material_cost')->set('line.inputs.quantity', '10')->set('line.inputs.unit_cost', '25')->call('addImpact')->assertHasNoErrors();

    $line = LossImpactLine::query()->where('loss_incident_id', $i->id)->sole();
    expect((string) $line->amount)->toBe('250.00')->and($i->fresh()->investigator_id)->toBe($this->investigator->id);

    Livewire::actingAs($this->manager)->test(LossIncidentShow::class, ['incident' => $i->fresh()])->call('validateFigure', $line->id)->assertHasErrors(['workflow']);   // own figure
    Livewire::actingAs($this->manager2)->test(LossIncidentShow::class, ['incident' => $i->fresh()])->call('validateFigure', $line->id)->assertHasNoErrors();
    expect($line->fresh()->validation_state)->toBe('validated');
});

it('lists incidents with the four values apart, and keeps them to the people and branches they belong to', function () {
    $i = ($this->report)();
    $l = ($this->line)($i, 'actual', 'manual', ['amount' => '5000'], ['assumptions' => 'Counted']);
    ($this->as)($this->manager, fn () => app(LossCalculationService::class)->validateLine($l, $this->manager));

    Livewire::actingAs($this->manager)->test(LossIncidentList::class)->assertOk()->assertSee($i->incident_number)->assertSee('5,000.00')->assertSee('Recoverable opportunity');
    Livewire::actingAs($this->sales)->test(LossIncidentList::class)->assertForbidden();
    Livewire::actingAs($this->outsider)->test(LossIncidentShow::class, ['incident' => $i])->assertForbidden();

    $a = ($this->as)($this->investigator, fn () => app(CapaService::class)->create($i, ['action_type' => 'corrective', 'title' => 'Seal kit', 'owner_id' => (string) $this->capaOwner->id, 'due_date' => '2026-10-20'], $this->investigator));
    Livewire::actingAs($this->capaOwner)->test(CorrectiveActionList::class)->assertOk()->assertSee($a->action_number);
});

it('opens the pages over HTTP and puts them in the menu', function () {
    $i = ($this->report)();

    foreach (['/losses', '/losses/report', '/losses/actions', '/losses/'.$i->id, '/losses/'.$i->id.'/edit'] as $url) {
        $this->actingAs($this->manager)->get($url)->assertOk();
    }

    $this->actingAs($this->sales)->get('/losses')->assertForbidden();
    $this->actingAs($this->manager)->get('/losses')->assertSee('Loss incidents')->assertSee('Corrective actions');
});
