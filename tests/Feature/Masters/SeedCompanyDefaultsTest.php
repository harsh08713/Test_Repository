<?php

use App\Modules\Masters\Actions\SeedCompanyDefaults;
use App\Modules\Masters\Enums\AccountNature;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
});

function groupsOf($company)
{
    return AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->get()->keyBy('code');
}

it('gives a company all 36 states with their GST codes', function () {
    seedDefaults($this->acme);

    $states = State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->get();

    expect($states)->toHaveCount(36)
        ->and($states->firstWhere('gst_code', '24')->name)->toBe('Gujarat')
        ->and($states->firstWhere('gst_code', '24')->code)->toBe('GJ')
        ->and($states->pluck('status')->unique()->all())->toBe(['active']);
});

it('gives a company the standard units, with decimals only where they make sense', function () {
    seedDefaults($this->acme);

    $units = Unit::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->get()->keyBy('code');

    expect($units->count())->toBeGreaterThanOrEqual(15)
        ->and($units['NOS']->decimal_allowed)->toBeFalse()
        ->and($units['BOX']->decimal_allowed)->toBeFalse()
        ->and($units['KG']->decimal_allowed)->toBeTrue()
        ->and($units['MTR']->decimal_allowed)->toBeTrue()
        ->and($units['LTR']->decimal_allowed)->toBeTrue();
});

it('lays out the standard Indian chart of accounts', function () {
    seedDefaults($this->acme);
    $g = groupsOf($this->acme);

    // The four primary groups
    expect($g['1000']->parent_id)->toBeNull()->and($g['1000']->nature)->toBe(AccountNature::Asset)
        ->and($g['2000']->nature)->toBe(AccountNature::Liability)
        ->and($g['3000']->nature)->toBe(AccountNature::Income)
        ->and($g['4000']->nature)->toBe(AccountNature::Expense);

    // The groups the brief names, in the right place
    expect($g['1160']->name)->toBe('Sundry Debtors')->and($g['1160']->parent_id)->toBe($g['1100']->id)
        ->and($g['2230']->name)->toBe('Sundry Creditors')->and($g['2230']->parent_id)->toBe($g['2200']->id)
        ->and($g['1110']->name)->toBe('Bank Accounts')
        ->and($g['1120']->name)->toBe('Cash-in-Hand')
        ->and($g['2210']->name)->toBe('Duties & Taxes')
        ->and($g['4100']->name)->toBe('Direct Expenses')
        ->and($g['4200']->name)->toBe('Indirect Expenses')
        ->and($g['3100']->name)->toBe('Direct Incomes');

    // every child inherits its root's nature, and everything is a protected standard group
    foreach ($g as $group) {
        $root = $group;
        while ($root->parent_id !== null) {
            $root = $g->firstWhere('id', $root->parent_id);
        }
        expect($group->nature)->toBe($root->nature)->and($group->is_system)->toBeTrue();
    }
});

it('is idempotent: running twice adds nothing the second time', function () {
    $first = app(SeedCompanyDefaults::class)->run($this->acme);
    $counts = [State::withoutGlobalScopes(Tenancy::SCOPES)->count(), Unit::withoutGlobalScopes(Tenancy::SCOPES)->count(), AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->count()];

    $second = app(SeedCompanyDefaults::class)->run($this->acme);

    expect($first['states'])->toBe(36)->and($first['account_groups'])->toBeGreaterThan(20)
        ->and($first['lead_sources'])->toBe(9)->and($first['followup_outcomes'])->toBe(7)->and($first['lost_reasons'])->toBeGreaterThan(3)
        ->and($second)->toBe(['states' => 0, 'units' => 0, 'account_groups' => 0, 'payment_methods' => 0, 'lead_sources' => 0, 'followup_outcomes' => 0, 'lost_reasons' => 0])
        ->and([State::withoutGlobalScopes(Tenancy::SCOPES)->count(), Unit::withoutGlobalScopes(Tenancy::SCOPES)->count(), AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->count()])->toBe($counts);
});

it('never overwrites what an administrator has changed', function () {
    seedDefaults($this->acme);
    State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('gst_code', '24')->update(['name' => 'Gujarat (renamed)', 'status' => 'inactive']);
    Unit::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', 'KG')->update(['name' => 'Kilos']);

    seedDefaults($this->acme);

    expect(State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('gst_code', '24')->first())
        ->name->toBe('Gujarat (renamed)')->status->toBe('inactive');
    expect(Unit::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', 'KG')->value('name'))->toBe('Kilos');
});

it('repairs a company that is missing some defaults', function () {
    seedDefaults($this->acme);
    State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('gst_code', '27')->forceDelete();

    $added = app(SeedCompanyDefaults::class)->run($this->acme);

    expect($added['states'])->toBe(1);
});

it('keeps every company’s reference data separate', function () {
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    expect(State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->count())->toBe(36)
        ->and(State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->count())->toBe(36)
        ->and(State::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(72)
        ->and(groupsOf($this->acme)->pluck('id')->intersect(groupsOf($this->globex)->pluck('id')))->toBeEmpty();
});

it('writes one summary audit entry instead of a hundred', function () {
    $before = Activity::query()->withoutGlobalScopes()->count();

    $added = app(SeedCompanyDefaults::class)->run($this->acme);

    $entries = Activity::query()->withoutGlobalScopes()->where('id', '>', 0)->latest('id')->take(5)->get();
    expect(Activity::query()->withoutGlobalScopes()->count() - $before)->toBe(1)
        ->and($entries->first()->event)->toBe('defaults_seeded')
        ->and($entries->first()->company_id)->toBe($this->acme->id)
        ->and($entries->first()->properties['states'])->toBe($added['states']);
});

it('works when called by a signed-in user of the company', function () {
    $admin = makeUser(['Company Admin'], [$this->acme]);
    $this->actingAs($admin);

    $added = app(SeedCompanyDefaults::class)->run($this->acme);

    expect($added['states'])->toBe(36);
});
