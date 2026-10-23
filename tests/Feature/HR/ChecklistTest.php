<?php

use App\Modules\HR\Http\Livewire\ChecklistTemplateList;
use App\Modules\HR\Http\Livewire\MyChecklists;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\HR\Services\ChecklistService;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * H5: onboarding and exit checklists. Today is Monday 12 October 2026. The standard list: "Laptop" (HR, 2 days), "Welcome chat" (manager, 5 days), "Read the handbook" (employee, 7 days)
 * for onboarding; "Return the laptop" (HR, 0 days) for exit.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->hrUser = makeUser(['HR Manager'], [$this->acme]);
    $this->bossUser = makeUser(['Sales Manager'], [$this->acme]);
    $this->workerUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->otherUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->outsider = makeUser(['HR Manager'], [$this->globex]);

    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return $user;
    };
    $this->line = fn (string $code, string $name, string $kind, string $who, int $days) => inCompany($this->acme, fn () => ChecklistTemplateItem::query()->create(['code' => $code, 'name' => $name, 'kind' => $kind, 'responsible' => $who, 'due_days' => $days]));
    $this->hire = function (string $code, string $name, $user = null, array $over = []) {
        ($this->as)($this->hrUser);
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-10-12', 'employment_type' => 'permanent', ...$over], $this->hrUser);

        if ($user !== null) {
            app(EmployeeService::class)->linkUser($e, $user, $this->hrUser);
        }

        return $e;
    };
    $this->items = fn (Employee $e, string $kind = 'onboarding') => EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->where('employee_id', $e->id)->where('kind', $kind)->orderBy('due_on')->get();
});

afterEach(fn () => Carbon::setTestNow());

it('copies the standard onboarding list onto a new hire, with due dates counted from the joining date', function () {
    ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    ($this->line)('CHAT', 'Welcome chat', 'onboarding', 'manager', 5);
    ($this->line)('BOOK', 'Read the handbook', 'onboarding', 'employee', 7);
    ($this->line)('RETURN', 'Return the laptop', 'exit', 'hr', 0);
    inCompany($this->acme, fn () => ChecklistTemplateItem::query()->create(['code' => 'OLD', 'name' => 'Retired step', 'kind' => 'onboarding', 'responsible' => 'hr', 'due_days' => 1, 'status' => 'inactive']));

    $e = ($this->hire)('N1', 'Newcomer');
    $items = ($this->items)($e);

    expect($items->pluck('title')->all())->toBe(['Laptop', 'Welcome chat', 'Read the handbook'])
        ->and($items->map(fn ($i) => $i->due_on->toDateString())->all())->toBe(['2026-10-14', '2026-10-17', '2026-10-19'])
        ->and($items->pluck('responsible')->all())->toBe(['hr', 'manager', 'employee'])
        ->and(($this->items)($e, 'exit'))->toHaveCount(0);
});

it('makes nothing when the company has no standard list, and does not double a list started twice', function () {
    $quiet = ($this->hire)('N0', 'Quiet');
    expect(($this->items)($quiet))->toHaveCount(0);

    ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    $service = app(ChecklistService::class);

    expect(inCompany($this->acme, fn () => $service->start($quiet, 'onboarding')))->toBe(1)
        ->and(inCompany($this->acme, fn () => $service->start($quiet, 'onboarding')))->toBe(0)
        ->and(($this->items)($quiet))->toHaveCount(1);

    // a line added to the standard list later is added on the next start; the old line is untouched
    ($this->line)('CHAT', 'Welcome chat', 'onboarding', 'manager', 5);
    expect(inCompany($this->acme, fn () => $service->start($quiet, 'onboarding')))->toBe(1)->and(($this->items)($quiet))->toHaveCount(2);

    expect(fn () => inCompany($this->acme, fn () => $service->start($quiet, 'party')))->toThrow(ValidationException::class);
});

it('starts the exit list when notice is recorded, due from today', function () {
    ($this->line)('RETURN', 'Return the laptop', 'exit', 'hr', 0);
    ($this->line)('CLEAR', 'Clear dues', 'exit', 'hr', 3);
    $e = ($this->hire)('L1', 'Leaver');

    ($this->as)($this->hrUser);
    app(EmployeeService::class)->recordNotice($e->fresh(), '2026-11-10', 'Moving abroad', $this->hrUser);

    expect(($this->items)($e, 'exit')->map(fn ($i) => $i->due_on->toDateString())->all())->toBe(['2026-10-12', '2026-10-15']);
});

it('lets HR, the manager and the person tick only what is theirs', function () {
    ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    ($this->line)('CHAT', 'Welcome chat', 'onboarding', 'manager', 5);
    ($this->line)('BOOK', 'Read the handbook', 'onboarding', 'employee', 7);
    $boss = ($this->hire)('B1', 'Boss', $this->bossUser);
    $worker = ($this->hire)('W1', 'Worker', $this->workerUser, ['reports_to_id' => (string) $boss->id]);
    ($this->hire)('O1', 'Other', $this->otherUser);
    [$laptop, $chat, $book] = ($this->items)($worker)->all();
    $service = app(ChecklistService::class);
    $tick = fn ($user, $item) => inCompany($this->acme, function () use ($service, $user, $item) {
        ($this->as)($user);

        return $service->tick($item->fresh(), $user, 'ok');
    });

    // the person: only their own line
    expect(fn () => $tick($this->workerUser, $laptop))->toThrow(AuthorizationException::class)
        ->and(fn () => $tick($this->workerUser, $chat))->toThrow(AuthorizationException::class)
        ->and($tick($this->workerUser, $book)->isDone())->toBeTrue();

    // the manager: the manager’s line — not the HR line, and never their own list
    expect(fn () => $tick($this->bossUser, $laptop))->toThrow(AuthorizationException::class)
        ->and($tick($this->bossUser, $chat)->done_by)->toBe($this->bossUser->id);
    [$ownLaptop, $ownChat] = ($this->items)($boss)->all();
    expect(fn () => $tick($this->bossUser, $ownChat))->toThrow(AuthorizationException::class);

    // someone with no part in it
    expect(fn () => $tick($this->otherUser, $laptop))->toThrow(AuthorizationException::class);

    // HR: anything
    expect($tick($this->hrUser, $laptop)->done_at)->not->toBeNull()->and($tick($this->hrUser, $ownChat)->isDone())->toBeTrue();

    // done is done
    expect(fn () => $tick($this->hrUser, $laptop))->toThrow(ValidationException::class)
        ->and(fn () => EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->find($laptop->id)->forceFill(['note' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(fn () => EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->find($laptop->id)->delete())->toThrow(LogicException::class);
    expect($ownLaptop->fresh()->done_at)->toBeNull();
});

it('keeps lists to the people in a person’s reach, and to the company', function () {
    ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    $boss = ($this->hire)('B1', 'Boss', $this->bossUser);
    $worker = ($this->hire)('W1', 'Worker', $this->workerUser, ['reports_to_id' => (string) $boss->id]);
    $other = ($this->hire)('O1', 'Other', $this->otherUser);
    $service = app(ChecklistService::class);
    $seen = fn ($user) => inCompany($this->acme, function () use ($service, $user) {
        ($this->as)($user);

        return $service->visibleTo($user)->pluck('employee_id')->unique()->sort()->values()->all();
    });

    expect($seen($this->workerUser))->toBe([$worker->id])
        ->and($seen($this->bossUser))->toBe(collect([$boss->id, $worker->id])->sort()->values()->all())
        ->and($seen($this->hrUser))->toBe(collect([$boss->id, $worker->id, $other->id])->sort()->values()->all());

    // a second company has none of this
    expect(DB::table('employee_checklist_items')->where('company_id', $this->globex->id)->count())->toBe(0);
});

it('shows the screen, marks an item done from it, starts a list by hand for HR only, and refuses a stranger’s item', function () {
    ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    ($this->line)('BOOK', 'Read the handbook', 'onboarding', 'employee', 7);
    $worker = ($this->hire)('W1', 'Worker', $this->workerUser);
    $other = ($this->hire)('O1', 'Other', $this->otherUser);
    $book = ($this->items)($worker)->last();
    $strangers = ($this->items)($other)->last();

    ($this->as)($this->workerUser);
    Livewire::actingAs($this->workerUser)->test(MyChecklists::class)->assertSee('Read the handbook')->assertDontSee('Other')
        ->call('startNoting', $book->id)->set('note', 'Read it')->call('done', $book->id)->assertHasNoErrors();
    expect($book->fresh()->done_at)->not->toBeNull()->and($book->fresh()->note)->toBe('Read it');

    Livewire::actingAs($this->workerUser)->test(MyChecklists::class)->call('done', $strangers->id)->assertNotFound();
    Livewire::actingAs($this->workerUser)->test(MyChecklists::class)->set('startFor', (string) $worker->id)->call('startList')->assertForbidden();

    // HR starts an exit list by hand: the standard exit list is empty, so nothing is added and it says so
    ($this->as)($this->hrUser);
    Livewire::actingAs($this->hrUser)->test(MyChecklists::class)->set('startFor', (string) $worker->id)->set('startKind', 'exit')->call('startList')->assertHasNoErrors();
    ($this->line)('RETURN', 'Return the laptop', 'exit', 'hr', 0);
    Livewire::actingAs($this->hrUser)->test(MyChecklists::class)->set('startFor', (string) $worker->id)->set('startKind', 'exit')->call('startList')->assertHasNoErrors();
    expect(($this->items)($worker, 'exit'))->toHaveCount(1);
});

it('manages the standard list from its own screen, for HR only', function () {
    ($this->as)($this->hrUser);
    Livewire::actingAs($this->hrUser)->test(ChecklistTemplateList::class)->assertOk();

    $this->actingAs($this->workerUser)->get(route('hr.checklist-items'))->assertForbidden();
    $this->actingAs($this->workerUser)->get(route('hr.checklists'))->assertOk();
    $this->actingAs($this->hrUser)->get(route('hr.checklist-items'))->assertOk();
});

it('refuses two copies of the same line at the database', function () {
    $line = ($this->line)('LAPTOP', 'Laptop', 'onboarding', 'hr', 2);
    $e = ($this->hire)('N1', 'Newcomer');
    $copy = fn () => (new EmployeeChecklistItem)->forceFill(['company_id' => $this->acme->id, 'employee_id' => $e->id, 'kind' => 'onboarding', 'source_item_id' => $line->id, 'title' => 'Laptop', 'responsible' => 'hr', 'due_on' => '2026-10-14'])->save();

    expect($copy)->toThrow(UniqueConstraintViolationException::class);
});
