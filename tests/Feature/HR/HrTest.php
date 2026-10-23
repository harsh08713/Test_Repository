<?php

use App\Modules\HR\Http\Livewire\EmployeeForm;
use App\Modules\HR\Http\Livewire\EmployeeShow;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentEvent;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\HrFigures;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * H1: the employee file, who may see it, change of job, notice and exit, papers, and the HR dashboard. The dashboard is hand-calculated (see hrWorld()).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Storage::fake('local');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->hr = makeUser(['HR Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->management = makeUser(['Management'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->staff = makeUser(['Tele-caller'], [$this->acme]);
    $this->outsider = makeUser(['HR Manager'], [$this->globex]);

    $this->sales = inCompany($this->acme, fn () => Department::query()->create(['code' => 'SALES', 'name' => 'Sales']));
    $this->ops = inCompany($this->acme, fn () => Department::query()->create(['code' => 'OPS', 'name' => 'Operations']));
    $this->lead = inCompany($this->acme, fn () => Designation::query()->create(['code' => 'LEAD', 'name' => 'Team lead']));
    $this->exec = inCompany($this->acme, fn () => Designation::query()->create(['code' => 'EXEC', 'name' => 'Executive']));
    $this->service = app(EmployeeService::class);

    $this->hire = function (string $code, string $name, array $over = []) {
        $this->actingAs($this->hr);
        app(TenantContext::class)->flush();

        return $this->service->create([
            'code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-01-05', 'employment_type' => 'permanent', ...$over,
        ], $this->hr);
    };
});

afterEach(fn () => Carbon::setTestNow());

function hrWorld(): void
{
    $t = test();
    $a = ($t->hire)('E1', 'Asha Rao', ['joining_date' => '2020-10-20', 'date_of_birth' => '1985-10-15']);
    $b = ($t->hire)('E2', 'Bimal Shah', ['joining_date' => '2026-10-01', 'date_of_birth' => '1990-03-03', 'department_id' => (string) $t->sales->id, 'reports_to_id' => (string) $a->id]);
    ($t->hire)('E3', 'Chirag Patel', ['joining_date' => '2026-08-01', 'employment_type' => 'probation', 'probation_end_date' => '2026-10-20', 'department_id' => (string) $t->sales->id, 'reports_to_id' => (string) $b->id]);
    $d = ($t->hire)('E4', 'Dev Nair', ['joining_date' => '2025-01-10', 'employment_type' => 'contract', 'location_id' => (string) $t->mum->id, 'department_id' => (string) $t->ops->id, 'reports_to_id' => (string) $a->id]);
    $e = ($t->hire)('E5', 'Esha Iyer', ['joining_date' => '2023-02-01', 'reports_to_id' => (string) $a->id]);
    $f = ($t->hire)('E6', 'Farid Khan', ['joining_date' => '2024-05-05', 'department_id' => (string) $t->ops->id, 'reports_to_id' => (string) $e->id]);

    $t->service->recordNotice($d, '2026-10-31', 'Better offer', $t->hr);
    DB::table('employees')->where('id', $e->id)->update(['employment_status' => 'exited', 'exit_date' => '2026-10-05']);   // left a week ago
    $t->world = compact('a', 'b', 'd', 'e', 'f');
}

// ---- the file -------------------------------------------------------------------------------------------------------------------------

it('adds an employee, suggests the next code and writes the first line of the history', function () {
    $this->actingAs($this->hr);
    expect($this->service->suggestCode())->toBe('EMP-00001');

    $e = ($this->hire)('EMP-00001', 'Meena Joshi', ['department_id' => (string) $this->sales->id, 'designation_id' => (string) $this->exec->id, 'mobile' => '9876543210', 'work_email' => 'meena@acme.test']);

    expect($e->employment_status)->toBe('active')->and($e->department_id)->toBe($this->sales->id)->and($this->service->suggestCode())->toBe('EMP-00002');
    $first = EmploymentEvent::query()->where('employee_id', $e->id)->get();
    expect($first)->toHaveCount(1)->and($first[0]->event)->toBe('joined')->and($first[0]->effective_on->toDateString())->toBe('2026-01-05')->and($first[0]->recorded_by)->toBe($this->hr->id);
});

it('refuses what makes no sense: a used code, a child, a bad mobile, a joining date far ahead, a person on probation with no end', function () {
    ($this->hire)('E1', 'First Person');

    $try = fn (array $over) => fn () => ($this->hire)($over['code'] ?? 'E9', 'Someone', $over);

    expect($try(['code' => 'E1']))->toThrow(ValidationException::class, 'already used')
        ->and($try(['date_of_birth' => '2020-01-01']))->toThrow(ValidationException::class, '14 years')
        ->and($try(['mobile' => '12345']))->toThrow(ValidationException::class, '10-digit')
        ->and($try(['joining_date' => '2027-06-01']))->toThrow(ValidationException::class, 'joining date')
        ->and($try(['employment_type' => 'probation']))->toThrow(ValidationException::class, 'probation')
        ->and($try(['code' => 'bad code!']))->toThrow(ValidationException::class, 'letters, digits')
        ->and($try(['department_id' => '999999']))->toThrow(ValidationException::class, 'department')
        ->and(Employee::query()->count())->toBe(1);
});

it('keeps another company’s people out of the pickers and as managers', function () {
    $other = inCompany($this->globex, function () {
        $this->actingAs($this->outsider);

        return app(EmployeeService::class)->create(['code' => 'G1', 'name' => 'Gina', 'location_id' => (string) makeLocation($this->globex, 'GLB')->id, 'joining_date' => '2026-01-01', 'employment_type' => 'permanent'], $this->outsider);
    });

    expect(fn () => ($this->hire)('E1', 'Local', ['reports_to_id' => (string) $other->id]))->toThrow(ValidationException::class, 'still with the company');

    $this->actingAs($this->hr);
    expect(Employee::query()->count())->toBe(0);   // the outsider's employee is invisible here
});

// ---- jobs and the history ------------------------------------------------------------------------------------------------------------

it('records a change of job as dated history, one line for each thing that changed', function () {
    $boss = ($this->hire)('E1', 'Boss');
    $e = ($this->hire)('E2', 'Worker', ['department_id' => (string) $this->sales->id, 'designation_id' => (string) $this->exec->id]);

    $this->service->changeJob($e, ['designation_id' => (string) $this->lead->id, 'department_id' => (string) $this->ops->id, 'reports_to_id' => (string) $boss->id], '2026-09-01', 'Promoted', $this->hr);

    $events = EmploymentEvent::query()->where('employee_id', $e->id)->where('event', '!=', 'joined')->get()->keyBy('event');
    expect($events->keys()->sort()->values()->all())->toBe(['department', 'designation', 'manager'])
        ->and($events['designation']->from_value)->toBe('Executive')->and($events['designation']->to_value)->toBe('Team lead')
        ->and($events['department']->from_value)->toBe('Sales')->and($events['department']->to_value)->toBe('Operations')
        ->and($events['manager']->to_value)->toBe('Boss')->and($events['designation']->effective_on->toDateString())->toBe('2026-09-01')
        ->and($events['designation']->remarks)->toBe('Promoted');

    $e->refresh();
    expect($e->designation_id)->toBe($this->lead->id)->and($e->reports_to_id)->toBe($boss->id);

    // saving the same job again changes nothing and writes nothing
    $before = EmploymentEvent::query()->count();
    $this->service->changeJob($e, ['designation_id' => (string) $this->lead->id], '2026-09-02', null, $this->hr);
    expect(EmploymentEvent::query()->count())->toBe($before);
});

it('refuses a manager who is the person, who reports to them, or who has left, and a date that cannot be', function () {
    $a = ($this->hire)('E1', 'A');
    $b = ($this->hire)('E2', 'B', ['reports_to_id' => (string) $a->id]);
    $c = ($this->hire)('E3', 'C', ['reports_to_id' => (string) $b->id]);

    $change = fn ($e, array $input, string $on = '2026-10-01') => fn () => $this->service->changeJob($e, $input, $on, null, $this->hr);

    expect($change($a, ['reports_to_id' => (string) $a->id]))->toThrow(ValidationException::class, 'themself')
        ->and($change($a, ['reports_to_id' => (string) $c->id]))->toThrow(ValidationException::class, 'circle')   // C reports to B who reports to A
        ->and($change($b, ['reports_to_id' => (string) $c->id]))->toThrow(ValidationException::class, 'circle')
        ->and($change($c, ['designation_id' => (string) $this->lead->id], '2027-01-01'))->toThrow(ValidationException::class, 'Give the day')
        ->and($change($c, ['designation_id' => (string) $this->lead->id], '2025-12-31'))->toThrow(ValidationException::class, 'not before the person joined');

    DB::table('employees')->where('id', $a->id)->update(['employment_status' => 'exited']);
    expect($change($c, ['reports_to_id' => (string) $a->id]))->toThrow(ValidationException::class, 'still with the company');
});

it('confirms a person once and makes them permanent', function () {
    $e = ($this->hire)('E1', 'Probationer', ['employment_type' => 'probation', 'probation_end_date' => '2026-07-01']);

    $this->service->confirm($e, '2026-07-02', $this->hr);
    $e->refresh();
    expect($e->employment_type)->toBe('permanent')->and($e->confirmation_date->toDateString())->toBe('2026-07-02')
        ->and(EmploymentEvent::query()->where('event', 'confirmed')->count())->toBe(1);
    expect(fn () => $this->service->confirm($e, '2026-07-03', $this->hr))->toThrow(ValidationException::class, 'already');
});

it('never lets the history be changed or removed', function () {
    $e = ($this->hire)('E1', 'Someone');
    $event = EmploymentEvent::query()->where('employee_id', $e->id)->firstOrFail();

    expect(fn () => $event->forceFill(['remarks' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

// ---- who sees what -----------------------------------------------------------------------------------------------------------------

it('shows HR everyone, a manager their own line, and everyone their own file', function () {
    hrWorld();
    ['a' => $a, 'b' => $b] = $this->world;
    $this->service->linkUser($b, $this->manager, $this->hr);
    app(EmployeeAccess::class)->flush();

    $names = fn ($user) => (function () use ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return app(EmployeeAccess::class)->restrict(Employee::query(), $user)->orderBy('code')->pluck('name')->all();
    })();

    expect($names($this->hr))->toHaveCount(6)->and($names($this->management))->toHaveCount(6)
        ->and($names($this->manager))->toBe(['Bimal Shah', 'Chirag Patel'])     // themself and the person below
        ->and($names($this->staff))->toBe([]);                                    // no file, no reach

    // another company never
    $this->actingAs($this->outsider);
    app(TenantContext::class)->flush();
    expect(Employee::query()->count())->toBe(0);

    // the policy agrees with the query
    $this->actingAs($this->manager);
    app(TenantContext::class)->flush();
    app(EmployeeAccess::class)->flush();
    expect($this->manager->can('view', $b))->toBeTrue()->and($this->manager->can('view', $a))->toBeFalse()->and($this->manager->can('delete', $b))->toBeFalse();
});

it('keeps date of birth, address, contacts and papers from those without the right, but not from the person themself', function () {
    $e = ($this->hire)('E1', 'Private Person', ['date_of_birth' => '1991-02-03', 'personal_email' => 'private@home.test', 'address' => '12 Hidden Lane']);
    $boss = ($this->hire)('E2', 'Boss Person');
    $this->service->changeJob($e, ['reports_to_id' => (string) $boss->id], '2026-02-01', null, $this->hr);
    $this->service->linkUser($boss, $this->manager, $this->hr);

    $this->actingAs($this->manager);
    app(TenantContext::class)->flush();
    app(EmployeeAccess::class)->flush();
    Livewire::test(EmployeeShow::class, ['employee' => $e])->assertSee('Private Person')->assertDontSee('private@home.test')->assertDontSee('12 Hidden Lane')->assertDontSee('Emergency contacts');

    $this->actingAs($this->hr);
    app(TenantContext::class)->flush();
    app(EmployeeAccess::class)->flush();
    Livewire::test(EmployeeShow::class, ['employee' => $e])->assertSee('private@home.test')->assertSee('12 Hidden Lane')->assertSee('Emergency contacts');

    // the person's own file shows everything to them
    $this->service->linkUser($e, $this->staff, $this->hr);
    $this->actingAs($this->staff);
    app(TenantContext::class)->flush();
    app(EmployeeAccess::class)->flush();
    Livewire::test(EmployeeShow::class, ['employee' => $e->fresh()])->assertSee('private@home.test')->assertSee('12 Hidden Lane');
    $this->get('/hr/me')->assertRedirect(route('hr.employees.show', $e));
});

it('links a login once, only to a login of the company', function () {
    $a = ($this->hire)('E1', 'A');
    $b = ($this->hire)('E2', 'B');

    $this->service->linkUser($a, $this->manager, $this->hr);
    expect(fn () => $this->service->linkUser($b, $this->manager, $this->hr))->toThrow(ValidationException::class, 'already linked')
        ->and(fn () => $this->service->linkUser($b, $this->outsider, $this->hr))->toThrow(ValidationException::class, 'does not belong');

    $this->service->linkUser($a, null, $this->hr);
    expect($a->fresh()->user_id)->toBeNull();
});

// ---- notice and exit -----------------------------------------------------------------------------------------------------------------

it('records notice, then on the last day marks the person as gone and switches their login off, once', function () {
    $e = ($this->hire)('E1', 'Leaver');
    $this->service->linkUser($e, $this->manager, $this->hr);
    $token = $this->manager->createToken('phone');
    expect($this->manager->tokens()->count())->toBe(1);

    $this->service->recordNotice($e, '2026-10-20', 'Moving cities', $this->hr);
    expect($e->fresh()->employment_status)->toBe('notice')->and($this->manager->fresh()->is_active)->toBeTrue();

    $this->artisan('hr:sync-exits')->assertSuccessful();
    expect($e->fresh()->employment_status)->toBe('notice');                       // the day has not come

    Carbon::setTestNow('2026-10-20 08:00:00');
    $this->artisan('hr:sync-exits')->assertSuccessful();
    $e->refresh();
    expect($e->employment_status)->toBe('exited')->and($this->manager->fresh()->is_active)->toBeFalse()->and($this->manager->tokens()->count())->toBe(0);

    $events = fn () => EmploymentEvent::query()->where('employee_id', $e->id)->pluck('event')->all();
    expect($events())->toBe(['joined', 'notice', 'exited']);

    $this->artisan('hr:sync-exits')->assertSuccessful();                           // idempotent
    expect($events())->toBe(['joined', 'notice', 'exited']);
});

it('lets someone leave today, take notice back, and come back — and only for those with the right', function () {
    $a = ($this->hire)('E1', 'Leaves today');
    $b = ($this->hire)('E2', 'Stays');
    $c = ($this->hire)('E3', 'Comes back');

    $this->service->recordNotice($a, '2026-10-12', 'Dismissed', $this->hr);
    expect($a->fresh()->employment_status)->toBe('exited');                        // the last day is today: gone at once

    $this->service->recordNotice($b, '2026-11-30', 'Thinking', $this->hr);
    $this->service->withdrawNotice($b, $this->hr);
    expect($b->fresh()->employment_status)->toBe('active')->and($b->fresh()->exit_date)->toBeNull();

    $this->service->recordNotice($c, '2026-10-12', 'Left', $this->hr);
    $this->service->rejoin($c->fresh(), '2026-10-12', $this->hr);
    expect($c->fresh()->employment_status)->toBe('active')->and(EmploymentEvent::query()->where('employee_id', $c->id)->pluck('event')->all())->toContain('rejoined');

    // a person who has left cannot be changed; an admin without record_exit… has it (administrators hold every non-role permission), a manager does not
    expect(fn () => $this->service->changeJob($a->fresh(), ['designation_id' => (string) $this->lead->id], '2026-10-01', null, $this->hr))->toThrow(ValidationException::class, 'left the company')
        ->and(fn () => $this->service->recordNotice($b, '2026-11-30', 'x', $this->manager))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->service->recordNotice($b, '2026-10-01', 'In the past', $this->hr))->toThrow(ValidationException::class, 'today or later')
        ->and(fn () => $this->service->recordNotice($b, '2026-11-30', '', $this->hr))->toThrow(ValidationException::class, 'Say why');
});

// ---- contacts and papers -----------------------------------------------------------------------------------------------------------

it('keeps up to five emergency contacts, one of them first to call', function () {
    $e = ($this->hire)('E1', 'Person');
    $add = fn (string $name, bool $primary = false) => $this->service->addContact($e, ['name' => $name, 'relationship' => 'Brother', 'mobile' => '9876500000', 'is_primary' => $primary], $this->hr);

    $c1 = $add('One');
    $c2 = $add('Two', true);
    expect($c1->fresh()->is_primary)->toBeFalse()->and($c2->fresh()->is_primary)->toBeTrue();    // the newest "first to call" wins

    $this->service->removeContact($c2, $this->hr);
    expect($c1->fresh()->is_primary)->toBeTrue();                                                // a primary always remains

    foreach (['Three', 'Four', 'Five', 'Five and a half'] as $n) {
        $add($n);
    }
    expect(fn () => $add('Six'))->toThrow(ValidationException::class, 'already 5')
        ->and(fn () => $this->service->addContact($e, ['name' => 'X', 'relationship' => 'Y', 'mobile' => '123'], $this->hr))->toThrow(ValidationException::class);
});

it('stores papers privately, shows them only to those who may, and removes the file with the paper', function () {
    $e = ($this->hire)('E1', 'Person');
    $boss = ($this->hire)('E2', 'Boss');
    $this->service->changeJob($e, ['reports_to_id' => (string) $boss->id], '2026-02-01', null, $this->hr);
    $this->service->linkUser($boss, $this->manager, $this->hr);

    $doc = $this->service->addDocument($e, UploadedFile::fake()->create('aadhaar.pdf', 120, 'application/pdf'), ['doc_type' => 'id_proof', 'title' => 'ID card', 'expires_on' => '2026-10-30'], $this->hr);
    Storage::disk('local')->assertExists($doc->path);
    expect($doc->path)->toStartWith('hr/'.$this->acme->id.'/'.$e->id.'/')->and($doc->original_name)->toBe('aadhaar.pdf')->and(array_key_exists('path', $doc->toArray()))->toBeFalse();

    expect(fn () => $this->service->addDocument($e, UploadedFile::fake()->create('virus.exe', 10), ['doc_type' => 'other', 'title' => 'x'], $this->hr))->toThrow(ValidationException::class, 'PDF')
        ->and(fn () => $this->service->addDocument($e, UploadedFile::fake()->create('big.pdf', 6000), ['doc_type' => 'other', 'title' => 'x'], $this->hr))->toThrow(ValidationException::class, '5 MB')
        ->and(fn () => $this->service->addDocument($e, UploadedFile::fake()->create('a.pdf', 10), ['doc_type' => 'weird', 'title' => 'x'], $this->hr))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->addDocument($e, UploadedFile::fake()->create('a.pdf', 10), ['doc_type' => 'other', 'title' => 'x'], $this->manager))->toThrow(AuthorizationException::class);

    // download: HR yes; the manager (can see the file, not the sensitive parts) no; the outsider's company 404; a person with no reach 403 or 404
    $this->actingAs($this->hr)->get(route('hr.documents.download', $doc))->assertOk()->assertDownload('aadhaar.pdf');
    app(EmployeeAccess::class)->flush();
    app(TenantContext::class)->flush();
    $this->actingAs($this->manager)->get(route('hr.documents.download', $doc))->assertForbidden();
    app(TenantContext::class)->flush();
    $this->actingAs($this->outsider)->get(route('hr.documents.download', $doc))->assertNotFound();
    app(TenantContext::class)->flush();
    $this->actingAs($this->staff)->get(route('hr.documents.download', $doc))->assertForbidden();

    $this->actingAs($this->hr);
    app(TenantContext::class)->flush();
    $this->service->removeDocument($doc, $this->hr);
    Storage::disk('local')->assertMissing($doc->path);
});

// ---- the dashboard ----------------------------------------------------------------------------------------------------------------------

it('counts headcount, joiners, leavers, groups and what needs attention, by hand', function () {
    hrWorld();
    ['a' => $a, 'b' => $b] = $this->world;
    $this->service->addDocument($b, UploadedFile::fake()->create('visa.pdf', 10), ['doc_type' => 'certificate', 'title' => 'Certificate', 'expires_on' => '2026-10-20'], $this->hr);
    $this->service->addDocument($a, UploadedFile::fake()->create('later.pdf', 10), ['doc_type' => 'other', 'title' => 'Later', 'expires_on' => '2026-12-31'], $this->hr);

    $this->actingAs($this->hr);
    app(TenantContext::class)->flush();
    app(EmployeeAccess::class)->flush();
    $s = app(HrFigures::class)->summary($this->hr, Carbon::parse('2026-10-12')->toImmutable());

    $by = fn (array $rows) => collect($rows)->pluck('count', 'name')->all();

    expect($s['headcount'])->toBe(5)                      // A, B, C, D, F — Esha has left
        ->and($s['on_notice'])->toBe(1)                   // Dev
        ->and($s['joiners'])->toBe(1)                     // Bimal joined on 1 October
        ->and($s['leavers'])->toBe(1)                     // Esha's last day was 5 October
        ->and($by($s['by_department']))->toBe(['Operations' => 2, 'Sales' => 2, 'No department' => 1])
        ->and($by($s['by_branch']))->toBe(['AHD Branch' => 4, 'MUM Branch' => 1])
        ->and($by($s['by_type']))->toBe(['Permanent' => 3, 'Contract' => 1, 'On probation' => 1])
        ->and(collect($s['birthdays'])->pluck('name')->all())->toBe(['Asha Rao'])
        ->and(collect($s['anniversaries'])->map(fn ($x) => $x['name'].' '.$x['years'])->all())->toBe(['Asha Rao 6'])
        ->and(collect($s['probation_ending'])->pluck('name')->all())->toBe(['Chirag Patel'])
        ->and(collect($s['leaving_soon'])->pluck('name')->all())->toBe(['Dev Nair'])
        ->and($s['orphans'])->toBe(1)                     // Farid reports to Esha, who has left
        ->and(collect($s['documents_due'])->pluck('title')->all())->toBe(['Certificate']);

    // a manager without HR's reach sees only their own line, and no papers
    $this->service->linkUser($b, $this->manager, $this->hr);
    app(EmployeeAccess::class)->flush();
    $this->actingAs($this->manager);
    app(TenantContext::class)->flush();
    $mine = app(HrFigures::class)->summary($this->manager, Carbon::parse('2026-10-12')->toImmutable());
    expect($mine['headcount'])->toBe(2)->and($mine['documents_due'])->toBeNull();
});

// ---- screens ---------------------------------------------------------------------------------------------------------------------------

it('opens the screens for those who may and puts them in the menu', function () {
    hrWorld();
    ['b' => $b] = $this->world;

    foreach (['/hr', '/hr/employees', '/hr/employees/create', '/hr/employees/'.$b->id, '/hr/employees/'.$b->id.'/edit', '/hr/org-chart', '/hr/me'] as $url) {
        $this->actingAs($this->hr)->get($url)->assertOk();
        app(TenantContext::class)->flush();
    }

    $this->actingAs($this->hr)->get('/hr/employees')->assertSee('Asha Rao')->assertSee('Farid Khan')->assertSee('Employees')->assertSee('HR dashboard');
    $this->actingAs($this->hr)->get('/hr/org-chart')->assertSee('Bimal Shah')->assertDontSee('Esha Iyer');   // she has left

    $this->actingAs($this->staff)->get('/hr')->assertForbidden();
    $this->actingAs($this->staff)->get('/hr/employees')->assertForbidden();
    $this->actingAs($this->staff)->get('/hr/employees/create')->assertForbidden();
    $this->actingAs($this->staff)->get('/hr/employees/'.$b->id)->assertForbidden();
    $this->actingAs($this->staff)->get('/hr/me')->assertOk()->assertSee('not linked');
    $this->actingAs($this->outsider)->get('/hr/employees/'.$b->id)->assertNotFound();
    $this->actingAs($this->staff)->get('/dashboard')->assertOk()->assertSee('My profile')->assertDontSee('HR dashboard');
});

it('adds, changes and gives notice from the screens, and refuses what the service refuses', function () {
    $e = ($this->hire)('E1', 'Screen Person');
    $this->actingAs($this->hr);
    app(TenantContext::class)->flush();

    Livewire::test(EmployeeForm::class)->set('f.code', 'E7')->set('f.name', 'New Hire')->set('f.location_id', (string) $this->ahd->id)->set('f.joining_date', '2026-10-01')
        ->call('save')->assertHasNoErrors();
    expect(Employee::query()->where('code', 'E7')->exists())->toBeTrue();

    Livewire::test(EmployeeForm::class)->set('f.code', 'E1')->set('f.name', 'Dup')->set('f.location_id', (string) $this->ahd->id)->set('f.joining_date', '2026-10-01')
        ->call('save')->assertHasErrors(['code']);

    Livewire::test(EmployeeShow::class, ['employee' => $e])->call('openPanel', 'job')->set('job.designation_id', (string) $this->lead->id)->set('job.effective_on', '2026-10-01')->call('saveJob')->assertHasNoErrors();
    expect($e->fresh()->designation_id)->toBe($this->lead->id);

    Livewire::test(EmployeeShow::class, ['employee' => $e->fresh()])->call('openPanel', 'job')->set('job.effective_on', '2027-05-05')->set('job.designation_id', (string) $this->exec->id)->call('saveJob')->assertHasErrors(['effective_on']);

    Livewire::test(EmployeeShow::class, ['employee' => $e->fresh()])->call('openPanel', 'notice')->set('notice.exit_date', '2026-11-15')->set('notice.exit_reason', 'Relocating')->call('giveNotice')->assertHasNoErrors();
    expect($e->fresh()->employment_status)->toBe('notice');

    // a manager with no exit right cannot, even by calling the action
    $e2 = ($this->hire)('E8', 'Report');
    $this->service->linkUser($e2, $this->manager, $this->hr);
    app(EmployeeAccess::class)->flush();
    $this->actingAs($this->manager);
    app(TenantContext::class)->flush();
    Livewire::test(EmployeeShow::class, ['employee' => $e2])->set('notice.exit_date', '2026-11-15')->set('notice.exit_reason', 'x')->call('giveNotice')->assertHasErrors(['action']);
    expect($e2->fresh()->employment_status)->toBe('active');
});

it('has the HR permissions on the right roles', function () {
    expect($this->hr->can('employee.view_all'))->toBeTrue()->and($this->hr->can('employee.record_exit'))->toBeTrue()->and($this->hr->can('hr_dashboard.view'))->toBeTrue()
        ->and($this->management->can('employee.view_all'))->toBeTrue()->and($this->management->can('employee.create'))->toBeFalse()->and($this->management->can('employee.view_sensitive'))->toBeFalse()
        ->and($this->manager->can('employee.view'))->toBeTrue()->and($this->manager->can('employee.view_all'))->toBeFalse()
        ->and($this->staff->can('employee.view'))->toBeFalse()->and($this->admin->can('employee.record_exit'))->toBeTrue();
});
