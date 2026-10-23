<?php

use App\Modules\Industry\Http\Livewire\IndustryList;
use App\Modules\Industry\Http\Livewire\IndustryShow;
use App\Modules\Industry\Models\CompanyIndustryTemplate;
use App\Modules\Industry\Models\IndustrySetting;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
});

/** Run service code as a signed-in user of the company, the way a request does. */
function asIndustryUser($user, callable $callback)
{
    test()->actingAs($user);

    return $callback(app(IndustryTemplateService::class));
}

// ---- the seeded templates ---------------------------------------------------------------------------------

it('seeds the 15 industry templates, each with KPIs, loss categories, fields and reports', function () {
    $templates = IndustryTemplate::query()->get();

    expect($templates)->toHaveCount(15)
        ->and($templates->pluck('code')->sort()->values()->all())->toBe(collect([
            'agriculture', 'automotive', 'chemicals', 'construction', 'electronics', 'energy_utilities', 'food_beverage', 'healthcare',
            'hospitality', 'it_services', 'logistics', 'metals_mining_cement', 'pharma', 'retail', 'textile',
        ])->sort()->values()->all());

    foreach ($templates as $template) {
        expect(KpiDefinition::query()->where('industry_template_id', $template->id)->count())->toBeGreaterThanOrEqual(3, "{$template->code} KPIs")
            ->and(LossCategory::query()->where('industry_template_id', $template->id)->count())->toBeGreaterThanOrEqual(3, "{$template->code} categories")
            ->and($template->fields()->count())->toBeGreaterThanOrEqual(3, "{$template->code} fields")
            ->and($template->reports()->count())->toBeGreaterThanOrEqual(1, "{$template->code} reports");
    }
});

it('gives every KPI thresholds that agree with its direction', function () {
    foreach (KpiDefinition::all() as $kpi) {
        $ok = $kpi->direction === 'higher_better'
            ? bccomp($kpi->threshold_green, $kpi->threshold_amber, 4) >= 0 && bccomp($kpi->threshold_amber, $kpi->threshold_red, 4) >= 0
            : bccomp($kpi->threshold_green, $kpi->threshold_amber, 4) <= 0 && bccomp($kpi->threshold_amber, $kpi->threshold_red, 4) <= 0;

        expect($ok)->toBeTrue("{$kpi->code} thresholds are out of order");
    }
});

it('seeds one default workflow whose moves all join known states', function () {
    $states = DB::table('workflow_states')->whereNull('industry_template_id')->pluck('code')->all();
    $moves = DB::table('workflow_transitions')->whereNull('industry_template_id')->get();

    expect($states)->toHaveCount(10)->toContain('draft', 'submitted', 'triage', 'investigation', 'action_required', 'verification', 'closed', 'rejected', 'on_hold', 'reopened')
        ->and(DB::table('workflow_states')->whereNull('industry_template_id')->where('is_initial', true)->count())->toBe(1);

    foreach ($moves as $move) {
        expect($states)->toContain($move->from_state)->toContain($move->to_state);
    }
});

it('can be seeded again without duplicating anything, and leaves a template an operator moved on', function () {
    $count = fn () => [IndustryTemplate::count(), KpiDefinition::count(), DB::table('workflow_states')->count(), DB::table('workflow_transitions')->count(), DB::table('template_fields')->count()];
    $before = $count();

    $this->seed(IndustryTemplateSeeder::class);
    expect($count())->toBe($before);

    app(IndustryTemplateService::class)->publishNewVersion($this->auto);
    KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 2)->update(['name' => 'Edited in v2']);

    $this->seed(IndustryTemplateSeeder::class);
    expect(KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 2)->where('name', 'Edited in v2')->exists())->toBeTrue()
        ->and($this->auto->fresh()->version)->toBe(2);
});

// ---- enabling a template ----------------------------------------------------------------------------------

it('lets a company switch a template on and off, and keeps other companies out of it', function () {
    asIndustryUser($this->admin, fn ($s) => $s->enable($this->auto, $this->admin));

    expect(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->count())->toBe(1)
        ->and(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'enabled')->count())->toBe(1);

    asIndustryUser($this->globexAdmin, fn ($s) => expect($s->enabled())->toHaveCount(0)->and($s->isEnabled($this->auto))->toBeFalse());
    asIndustryUser($this->admin, fn ($s) => expect($s->enabled()->pluck('code')->all())->toBe(['automotive']));

    asIndustryUser($this->admin, fn ($s) => $s->enable($this->auto, $this->admin));      // again: nothing new, nothing audited
    expect(Activity::query()->where('event', 'enabled')->count())->toBe(1);

    asIndustryUser($this->admin, fn ($s) => $s->disable($this->auto, $this->admin));
    expect(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'disabled')->count())->toBe(1);
});

it('refuses to switch a template on for someone without the right', function () {
    expect(fn () => asIndustryUser($this->accounts, fn ($s) => $s->enable($this->auto, $this->accounts)))->toThrow(AuthorizationException::class)
        ->and(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('refuses a template the system has switched off', function () {
    $this->auto->update(['status' => 'inactive']);

    expect(fn () => asIndustryUser($this->admin, fn ($s) => $s->enable($this->auto, $this->admin)))->toThrow(ValidationException::class);
});

// ---- thresholds -------------------------------------------------------------------------------------------

it('keeps a company’s thresholds to itself and leaves the shared template alone', function () {
    $oee = KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('code', 'oee')->firstOrFail();

    asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'oee', '80', '60', '40', $this->admin));

    $mine = asIndustryUser($this->admin, fn ($s) => $s->resolve($this->auto)['kpis']->firstWhere('code', 'oee'));
    $theirs = asIndustryUser($this->globexAdmin, fn ($s) => $s->resolve($this->auto)['kpis']->firstWhere('code', 'oee'));

    expect(round((float) $mine->threshold_green))->toBe(80.0)
        ->and(round((float) $theirs->threshold_green))->toBe(round((float) $oee->threshold_green))
        ->and(round((float) $oee->fresh()->threshold_green))->toBe(round((float) $oee->threshold_green))
        ->and(IndustrySetting::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'thresholds_changed')->count())->toBe(1);

    asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'oee', '', '', '', $this->admin));      // blank = back to the template
    expect(asIndustryUser($this->admin, fn ($s) => round((float) $s->resolve($this->auto)['kpis']->firstWhere('code', 'oee')->threshold_green)))->toBe(round((float) $oee->threshold_green));
});

it('refuses thresholds on the wrong side of each other, or that are not numbers', function () {
    // OEE is higher-is-better: green must be at least amber. Downtime is lower-is-better: green must be at most amber.
    expect(fn () => asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'oee', '50', '60', null, $this->admin)))->toThrow(ValidationException::class)
        ->and(fn () => asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'downtime_hours', '50', '40', null, $this->admin)))->toThrow(ValidationException::class)
        ->and(fn () => asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'oee', 'abc', '60', null, $this->admin)))->toThrow(ValidationException::class)
        ->and(fn () => asIndustryUser($this->admin, fn ($s) => $s->setKpiThresholds($this->auto, 'nope', '1', '2', null, $this->admin)))->toThrow(ValidationException::class)
        ->and(IndustrySetting::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('works out green, amber and red for both directions, and says nothing without data', function () {
    $service = app(IndustryTemplateService::class);
    $oee = KpiDefinition::query()->where('code', 'oee')->where('industry_template_id', $this->auto->id)->firstOrFail();          // higher better 85 / 65
    $down = KpiDefinition::query()->where('code', 'downtime_hours')->where('industry_template_id', $this->auto->id)->firstOrFail();   // lower better 20 / 40

    expect($service->status($oee, '90'))->toBe('green')->and($service->status($oee, '85'))->toBe('green')
        ->and($service->status($oee, '70'))->toBe('amber')->and($service->status($oee, '65'))->toBe('amber')->and($service->status($oee, '10'))->toBe('red')
        ->and($service->status($down, '15'))->toBe('green')->and($service->status($down, '40'))->toBe('amber')->and($service->status($down, '41'))->toBe('red')
        ->and($service->status($oee, null))->toBeNull();
});

// ---- the extra incident fields ----------------------------------------------------------------------------

it('validates a template’s incident fields on the server', function () {
    $service = app(IndustryTemplateService::class);

    $clean = $service->validateFields($this->auto, 1, ['line' => ' Line 3 ', 'shift' => 'B', 'downtime_hours' => '2.5', 'defect_count' => '12']);
    expect($clean)->toEqualCanonicalizing(['line' => 'Line 3', 'shift' => 'B', 'downtime_hours' => '2.5', 'defect_count' => '12']);

    $fails = fn (array $values) => array_keys(rescue(fn () => $service->validateFields($this->auto, 1, $values), fn ($e) => $e instanceof ValidationException ? $e->errors() : [], false));

    expect($fails(['shift' => 'B']))->toBe(['custom_fields.line'])                                                   // required field missing
        ->and($fails(['line' => 'L1', 'shift' => 'Z']))->toBe(['custom_fields.shift'])                                // not one of the choices
        ->and($fails(['line' => 'L1', 'downtime_hours' => '2.55555']))->toBe(['custom_fields.downtime_hours'])       // too many decimals
        ->and($fails(['line' => 'L1', 'defect_count' => '1.5']))->toBe(['custom_fields.defect_count'])               // not a whole number
        ->and($fails(['line' => 'L1', 'surprise' => 'x']))->toBe(['custom_fields.surprise']);                        // a field the template does not have
});

it('checks booleans, dates, bounds and lengths', function () {
    $service = app(IndustryTemplateService::class);
    $pharma = IndustryTemplate::query()->where('code', 'pharma')->firstOrFail();

    expect($service->validateFields($pharma, 1, ['batch_no' => 'B-1', 'deviation_class' => 'Major', 'product_affected' => 'yes']))
        ->toMatchArray(['product_affected' => true]);

    expect(fn () => $service->validateFields($pharma, 1, ['batch_no' => 'B-1', 'deviation_class' => 'Major', 'product_affected' => 'maybe']))->toThrow(ValidationException::class);

    DB::table('template_fields')->insert([
        ['industry_template_id' => $pharma->id, 'version' => 1, 'key' => 'seen_on', 'label' => 'Seen on', 'type' => 'date', 'validation_rules' => null, 'is_required' => false, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()],
        ['industry_template_id' => $pharma->id, 'version' => 1, 'key' => 'ph', 'label' => 'pH', 'type' => 'decimal', 'validation_rules' => json_encode(['min' => 0, 'max' => 14]), 'is_required' => false, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $base = ['batch_no' => 'B-1', 'deviation_class' => 'Minor'];

    expect($service->validateFields($pharma, 1, [...$base, 'seen_on' => '2026-10-18', 'ph' => '7.4']))->toMatchArray(['seen_on' => '2026-10-18', 'ph' => '7.4'])
        ->and(fn () => $service->validateFields($pharma, 1, [...$base, 'seen_on' => '2026-02-30']))->toThrow(ValidationException::class)
        ->and(fn () => $service->validateFields($pharma, 1, [...$base, 'seen_on' => '18-10-2026']))->toThrow(ValidationException::class)
        ->and(fn () => $service->validateFields($pharma, 1, [...$base, 'ph' => '15']))->toThrow(ValidationException::class)
        ->and(fn () => $service->validateFields($pharma, 1, [...$base, 'batch_no' => str_repeat('x', 501)]))->toThrow(ValidationException::class);
});

// ---- versions ---------------------------------------------------------------------------------------------

it('publishes a new version without disturbing a company that is pinned to the old one', function () {
    asIndustryUser($this->admin, fn ($s) => $s->enable($this->auto, $this->admin));
    $service = app(IndustryTemplateService::class);

    // A category with a parent, to prove the links are re-pointed at the copies.
    $parent = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', 'line_stoppage')->firstOrFail();
    LossCategory::query()->create(['industry_template_id' => $this->auto->id, 'version' => 1, 'parent_id' => $parent->id, 'code' => 'press_line', 'name' => 'Press line', 'default_severity' => 'medium']);

    $v1Kpis = KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 1)->count();

    expect($service->publishNewVersion($this->auto))->toBe(2);

    $this->auto->refresh();
    expect($this->auto->version)->toBe(2)
        ->and(KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 2)->count())->toBe($v1Kpis)
        ->and(KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 1)->count())->toBe($v1Kpis);

    $child = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('version', 2)->where('code', 'press_line')->firstOrFail();
    $newParent = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('version', 2)->where('code', 'line_stoppage')->firstOrFail();
    expect($child->parent_id)->toBe($newParent->id)->and($newParent->id)->not->toBe($parent->id);

    // The company still sees version 1 until it moves, on purpose, and that is audited.
    asIndustryUser($this->admin, fn ($s) => expect($s->versionFor($this->auto))->toBe(1)->and($s->resolve($this->auto)['version'])->toBe(1));
    asIndustryUser($this->admin, fn ($s) => $s->moveToCurrentVersion($this->auto, $this->admin));
    asIndustryUser($this->admin, fn ($s) => expect($s->versionFor($this->auto))->toBe(2));
    expect(Activity::query()->where('event', 'version_moved')->count())->toBe(1);

    // A company that never enabled it sees the current version.
    asIndustryUser($this->globexAdmin, fn ($s) => expect($s->versionFor($this->auto))->toBe(2));
});

it('falls back to the default workflow for a template without one of its own', function () {
    $config = asIndustryUser($this->admin, fn ($s) => $s->resolve($this->auto));

    expect($config['states'])->toHaveCount(10)->and($config['transitions']->count())->toBeGreaterThan(8);
});

// ---- the screens ------------------------------------------------------------------------------------------

it('shows the 15 templates, lets an administrator switch one on and off, and shows the result', function () {
    Livewire::actingAs($this->admin)->test(IndustryList::class)
        ->assertOk()->assertSee('Automotive')->assertSee('Hospitality')->assertSee('IT &amp; Professional Services', false)
        ->call('enableTemplate', $this->auto->id)->assertSee('Enabled Automotive.');

    expect(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->exists())->toBeTrue();

    Livewire::actingAs($this->admin)->test(IndustryList::class)->call('disableTemplate', $this->auto->id)->assertSee('Disabled Automotive.');
    expect(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('lets Accounts read the templates but not switch them on, and keeps others out', function () {
    Livewire::actingAs($this->accounts)->test(IndustryList::class)->assertOk()->assertDontSeeHtml('enableTemplate')
        ->call('enableTemplate', $this->auto->id)->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(IndustryList::class)->assertForbidden();

    expect(CompanyIndustryTemplate::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('shows a template’s configuration and saves thresholds from the page', function () {
    Livewire::actingAs($this->admin)->test(IndustryShow::class, ['industry' => $this->auto])
        ->assertOk()->assertSee('Overall equipment effectiveness')->assertSee('Line stoppage')->assertSee('Production line')->assertSee('Draft')
        ->set('thresholds.oee.green', '82')->set('thresholds.oee.amber', '62')->call('saveThresholds', 'oee')->assertHasNoErrors()->assertSee('Your numbers')
        ->set('thresholds.oee.green', '10')->call('saveThresholds', 'oee')->assertHasErrors(['kpi-oee']);

    Livewire::actingAs($this->accounts)->test(IndustryShow::class, ['industry' => $this->auto])->assertOk()
        ->call('saveThresholds', 'oee')->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(IndustryShow::class, ['industry' => $this->auto])->assertForbidden();
});

it('opens the industry pages over HTTP and puts them in the menu for those who may read them', function () {
    $this->actingAs($this->admin)->get('/industries')->assertOk()->assertSee('Loss Intelligence');
    $this->actingAs($this->admin)->get('/industries/automotive')->assertOk()->assertSee('Automotive');
    $this->actingAs($this->telecaller)->get('/industries')->assertForbidden();
});
