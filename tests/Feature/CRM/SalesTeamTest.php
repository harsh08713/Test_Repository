<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->teams = app(SalesTeamService::class);
    $this->scope = app(SalesScope::class);

    $this->save = fn (array $team = [], $actor = null, $existing = null) => inCompany($this->acme, fn () => $this->teams->saveTeam($existing, ['team' => [
        'code' => 'GUJ', 'name' => 'Gujarat field team', 'manager_user_id' => (string) $this->manager->id, 'status' => 'active', 'remarks' => '', ...$team,
    ]], $actor ?? $this->manager));
    $this->complaints = function (array $team, $actor = null) {
        try {
            ($this->save)($team, $actor);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Teams
// ======================================================================================================

it('starts a team under its manager, tidying the code and name', function () {
    $team = ($this->save)(['code' => '  guj-field ', 'name' => '  Gujarat   field  team ']);

    expect($team->code)->toBe('GUJ-FIELD')->and($team->name)->toBe('Gujarat field team')->and($team->manager_user_id)->toBe($this->manager->id)
        ->and($team->company_id)->toBe($this->acme->id)->and($team->created_by)->toBe($this->manager->id)->and($team->status)->toBe('active');
});

it('keeps codes and names unique within the company, but not across companies', function () {
    ($this->save)();

    expect(($this->complaints)(['name' => 'Another name']))->toBe(['team.code'])
        ->and(($this->complaints)(['code' => 'OTHER']))->toBe(['team.name'])
        ->and(($this->complaints)(['code' => 'bad code!', 'name' => 'X']))->toBe(['team.code'])
        ->and(($this->complaints)(['code' => '', 'name' => '']))->toBe(['team.code', 'team.name']);

    $globexManager = makeUser(['Sales Manager'], [$this->globex]);
    $other = inCompany($this->globex, fn () => $this->teams->saveTeam(null, ['team' => ['code' => 'GUJ', 'name' => 'Gujarat field team', 'manager_user_id' => (string) $globexManager->id, 'status' => 'active']], $globexManager));

    expect($other->company_id)->toBe($this->globex->id);
});

it('lets a manager run only teams of which they are the manager, and an administrator choose anyone', function () {
    expect(($this->complaints)(['manager_user_id' => (string) $this->otherManager->id]))->toBe(['team.manager_user_id']);

    $team = ($this->save)(['code' => 'ADM', 'name' => 'Admin chose', 'manager_user_id' => (string) $this->otherManager->id], $this->admin);

    expect($team->manager_user_id)->toBe($this->otherManager->id);
});

it('wants an active user of this company as manager', function () {
    $inactive = makeUser(['Sales Manager'], [$this->acme], null, ['is_active' => false]);
    $stranger = makeUser(['Sales Manager'], [$this->globex]);

    foreach ([$inactive, $stranger] as $person) {
        expect(($this->complaints)(['manager_user_id' => (string) $person->id], $this->admin))->toBe(['team.manager_user_id']);
    }

    expect(($this->complaints)(['manager_user_id' => '999999'], $this->admin))->toBe(['team.manager_user_id'])
        ->and(($this->complaints)(['manager_user_id' => ''], $this->admin))->toBe(['team.manager_user_id']);
});

// ======================================================================================================
// Members
// ======================================================================================================

it('puts people on a team from a day, and a person is on one team at a time', function () {
    $first = ($this->save)();
    $second = ($this->save)(['code' => 'SRT', 'name' => 'Surat field team']);
    $member = inCompany($this->acme, fn () => $this->teams->addMember($first, $this->bde->id, null, $this->manager));

    expect($member->joined_on->toDateString())->toBe('2026-10-12')->and($member->left_on)->toBeNull()->and($member->company_id)->toBe($this->acme->id);

    try {
        inCompany($this->acme, fn () => $this->teams->addMember($second, $this->bde->id, null, $this->manager));
        $this->fail('a person was put on two teams');
    } catch (ValidationException $e) {
        expect($e->errors()['user_id'][0])->toContain('already on Gujarat field team')->toContain('one team at a time');
    }

    expect(SalesTeamMember::query()->count())->toBe(1);
});

it('is stopped by the database as well when two requests add the same person at once', function () {
    $team = ($this->save)();
    $other = ($this->save)(['code' => 'SRT', 'name' => 'Surat field team']);
    inCompany($this->acme, fn () => $this->teams->addMember($team, $this->bde->id, null, $this->manager));

    // straight into the table, around the service: the generated key still refuses a second open membership
    $twin = (new SalesTeamMember)->fill(['joined_on' => '2026-10-12'])->forceFill(['company_id' => $this->acme->id, 'sales_team_id' => $other->id, 'user_id' => $this->bde->id]);

    expect(fn () => inCompany($this->acme, fn () => $twin->save()))->toThrow(QueryException::class);
});

it('refuses a manager as a member of their own team, a switched-off team, and people who are not the company’s', function () {
    $team = ($this->save)();
    $inactive = makeUser(['BDE / Field Salesperson'], [$this->acme], null, ['is_active' => false]);
    $stranger = makeUser(['BDE / Field Salesperson'], [$this->globex]);

    foreach ([$this->manager->id, $inactive->id, $stranger->id, 999999] as $id) {
        expect(fn () => inCompany($this->acme, fn () => $this->teams->addMember($team, $id, null, $this->manager)))->toThrow(ValidationException::class);
    }

    $off = ($this->save)(['code' => 'OFF', 'name' => 'Off', 'status' => 'inactive']);
    expect(fn () => inCompany($this->acme, fn () => $this->teams->addMember($off, $this->bde->id, null, $this->manager)))->toThrow(FieldRuleException::class, 'switched off');
});

it('refuses a join date in the future or unreadable', function () {
    $team = ($this->save)();

    foreach (['2026-10-13', 'yesterday', '12-10-2026', '2026-02-30'] as $bad) {
        expect(fn () => inCompany($this->acme, fn () => $this->teams->addMember($team, $this->bde->id, $bad, $this->manager)), $bad)->toThrow(ValidationException::class);
    }
});

it('ends a membership instead of deleting it, and keeps the history', function () {
    $team = ($this->save)();
    $member = inCompany($this->acme, fn () => $this->teams->addMember($team, $this->bde->id, '2026-09-01', $this->manager));

    $left = inCompany($this->acme, fn () => $this->teams->removeMember($member, '2026-09-30', $this->manager));

    expect($left->left_on->toDateString())->toBe('2026-09-30')->and(SalesTeamMember::query()->count())->toBe(1)
        ->and($team->members()->count())->toBe(1)->and($team->currentMembers()->count())->toBe(0)
        ->and(fn () => inCompany($this->acme, fn () => $this->teams->removeMember($member->fresh(), null, $this->manager)))->toThrow(FieldRuleException::class, 'already left');
});

it('will not end a membership before it began or in the future', function () {
    $team = ($this->save)();
    $member = inCompany($this->acme, fn () => $this->teams->addMember($team, $this->bde->id, '2026-10-01', $this->manager));

    expect(fn () => inCompany($this->acme, fn () => $this->teams->removeMember($member, '2026-09-30', $this->manager)))->toThrow(ValidationException::class, 'before they joined')
        ->and(fn () => inCompany($this->acme, fn () => $this->teams->removeMember($member, '2026-10-20', $this->manager)))->toThrow(ValidationException::class);
});

it('lets a person move to another team the day after they left the first, and never overlap', function () {
    $first = ($this->save)();
    $second = ($this->save)(['code' => 'SRT', 'name' => 'Surat field team']);
    $member = inCompany($this->acme, fn () => $this->teams->addMember($first, $this->bde->id, '2026-08-01', $this->manager));
    inCompany($this->acme, fn () => $this->teams->removeMember($member, '2026-09-30', $this->manager));

    expect(fn () => inCompany($this->acme, fn () => $this->teams->addMember($second, $this->bde->id, '2026-09-30', $this->manager)))->toThrow(ValidationException::class, 'until 30 Sep 2026')
        ->and(fn () => inCompany($this->acme, fn () => $this->teams->addMember($second, $this->bde->id, '2026-09-15', $this->manager)))->toThrow(ValidationException::class);

    $moved = inCompany($this->acme, fn () => $this->teams->addMember($second, $this->bde->id, '2026-10-01', $this->manager));

    expect($moved->joined_on->toDateString())->toBe('2026-10-01')->and(SalesTeamMember::query()->where('user_id', $this->bde->id)->count())->toBe(2);
});

// ======================================================================================================
// Whose work a person may plan, measure and see
// ======================================================================================================

it('gives a manager reach over the current members of their active teams, and over nobody else', function () {
    $team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $managed = fn ($actor, $user, ?string $date = null) => inCompany($this->acme, fn () => $this->scope->manages($actor, $user->id, $date));

    expect($managed($this->manager, $this->bde))->toBeTrue()
        ->and($managed($this->manager, $this->bde2))->toBeFalse()               // on nobody's team
        ->and($managed($this->otherManager, $this->bde))->toBeFalse()           // someone else's team
        ->and($managed($this->manager, $this->manager))->toBeFalse()            // never oneself
        ->and($managed($this->bde, $this->bde))->toBeFalse()
        ->and($managed($this->bde, $this->manager))->toBeFalse();
});

it('lets a person see their own records, and a manager their team’s', function () {
    makeTeam($this->acme, $this->manager, [$this->bde]);

    $sees = fn ($actor, $user) => inCompany($this->acme, fn () => $this->scope->sees($actor, $user->id));

    expect($sees($this->bde, $this->bde))->toBeTrue()->and($sees($this->manager, $this->bde))->toBeTrue()
        ->and($sees($this->bde, $this->bde2))->toBeFalse()->and($sees($this->otherManager, $this->bde))->toBeFalse();

    expect(inCompany($this->acme, fn () => $this->scope->visibleUserIds($this->manager)))->toEqualCanonicalizing([$this->bde->id, $this->manager->id])
        ->and(inCompany($this->acme, fn () => $this->scope->visibleUserIds($this->bde)))->toBe([$this->bde->id]);
});

it('counts people on the team only for the days they were on it', function () {
    $team = makeTeam($this->acme, $this->manager, []);
    $member = inCompany($this->acme, fn () => $this->teams->addMember($team, $this->bde->id, '2026-09-01', $this->manager));
    inCompany($this->acme, fn () => $this->teams->removeMember($member, '2026-09-30', $this->manager));

    $managed = fn (string $date) => inCompany($this->acme, fn () => $this->scope->manages($this->manager, $this->bde->id, $date));

    expect($managed('2026-09-15'))->toBeTrue()->and($managed('2026-09-30'))->toBeTrue()->and($managed('2026-10-01'))->toBeFalse()->and($managed('2026-08-31'))->toBeFalse()
        ->and($managed('2026-10-12'))->toBeFalse();     // today: they have left
});

it('gives a manager no reach through a team that is switched off', function () {
    $team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $team->forceFill(['status' => 'inactive'])->save();

    expect(inCompany($this->acme, fn () => $this->scope->manages($this->manager, $this->bde->id)))->toBeFalse();
});

it('gives everyone to whoever holds the explicit scope permission, and only to them', function () {
    makeTeam($this->acme, $this->manager, [$this->bde]);

    // the permission, not the role name: Company Admin holds it, and so would anybody it is given to
    expect(inCompany($this->acme, fn () => $this->scope->managedUserIds($this->admin)))->toBeNull()
        ->and(inCompany($this->acme, fn () => $this->scope->manages($this->admin, $this->bde2->id)))->toBeTrue()
        ->and(inCompany($this->acme, fn () => $this->scope->manages($this->manager, $this->bde2->id)))->toBeFalse();

    $this->manager->givePermissionTo('sales.scope_all');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    expect(inCompany($this->acme, fn () => $this->scope->manages($this->manager->fresh(), $this->bde2->id)))->toBeTrue();
});

it('lets only the manager (or someone who sees everyone) run a team', function () {
    $team = makeTeam($this->acme, $this->manager, [$this->bde]);

    expect($this->scope->runsTeam($this->manager, $team))->toBeTrue()->and($this->scope->runsTeam($this->admin, $team))->toBeTrue()
        ->and($this->scope->runsTeam($this->otherManager, $team))->toBeFalse()->and($this->scope->runsTeam($this->bde, $team))->toBeFalse();
});
