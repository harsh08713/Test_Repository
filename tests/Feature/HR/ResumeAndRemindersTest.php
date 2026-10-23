<?php

use App\Modules\HR\Console\RemindChecklists;
use App\Modules\HR\Http\Livewire\CandidateList;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\HR\Notifications\HrNotice;
use App\Modules\HR\Services\ChecklistReminders;
use App\Modules\HR\Services\ChecklistService;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Services\ResumeService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * H5b: reminders for late onboarding and exit lines (a digest per person, a line at most once a week, claimed so two runs cannot both tell), and a candidate's résumé (one private
 * file, HR only, replaced by a new one). Today is Monday 12 October 2026.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Storage::fake('local');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->hrUser = makeUser(['HR Manager'], [$this->acme]);
    $this->bossUser = makeUser(['Sales Manager'], [$this->acme]);
    $this->workerUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->outsider = makeUser(['HR Manager'], [$this->globex]);

    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return $user;
    };
    $this->hire = function (string $code, string $name, $user = null, array $over = []) {
        ($this->as)($this->hrUser);
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-10-12', 'employment_type' => 'permanent', ...$over], $this->hrUser);

        if ($user !== null) {
            app(EmployeeService::class)->linkUser($e, $user, $this->hrUser);
        }

        return $e;
    };
    $this->line = fn (string $code, string $name, string $who, int $days) => inCompany($this->acme, fn () => ChecklistTemplateItem::query()->create(['code' => $code, 'name' => $name, 'kind' => 'onboarding', 'responsible' => $who, 'due_days' => $days]));
    $this->remind = fn () => inCompany($this->acme, fn () => app(ChecklistReminders::class)->run());
    $this->candidate = function () {
        ($this->as)($this->hrUser);

        return inCompany($this->acme, fn () => app(RecruitmentService::class)->saveCandidate(['name' => 'Asha Verma', 'mobile' => '9000000501', 'source' => 'referral'], $this->hrUser));
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- reminders -----------------------------------------------------------------------------------------------------------------------

it('tells each person once, as a digest, about the late lines that are theirs', function () {
    ($this->line)('LAPTOP', 'Laptop', 'hr', 1);
    ($this->line)('CHAT', 'Welcome chat', 'manager', 2);
    ($this->line)('BOOK', 'Handbook', 'employee', 3);
    ($this->line)('LATER', 'Not due yet', 'employee', 30);
    $boss = ($this->hire)('B1', 'Boss', $this->bossUser);
    ($this->hire)('W1', 'Worker', $this->workerUser, ['reports_to_id' => (string) $boss->id]);

    // nothing is late on the day it starts
    expect(($this->remind)())->toBe(0);
    Notification::assertNothingSent();

    Carbon::setTestNow('2026-10-20 09:00:00');   // eight days on: the first three lines of each person are late
    $told = ($this->remind)();

    // Boss: 3 lines (laptop is HR's, chat is their manager's -> nobody, book is theirs); Worker: 3 lines (laptop HR, chat -> Boss, book -> Worker)
    expect($told)->toBe(6);
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn ($n) => $n->kind === 'checklist_late' && $n->subject === 'An onboarding or exit item is late');
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn ($n) => $n->kind === 'checklist_late' && str_contains($n->subject, '2 onboarding or exit items'));   // chat of the worker + their own book
    Notification::assertSentTo($this->hrUser, HrNotice::class, fn ($n) => str_contains($n->subject, '2 onboarding or exit items'));                                    // the two laptops
    Notification::assertSentToTimes($this->workerUser, HrNotice::class, 1);

    // the same day again, and the next days: nothing more until a week has passed
    expect(($this->remind)())->toBe(0);
    Carbon::setTestNow('2026-10-26 09:00:00');
    expect(($this->remind)())->toBe(0);
    Carbon::setTestNow('2026-10-27 09:00:00');
    expect(($this->remind)())->toBe(6);
});

it('does not remind about a finished line, a line of someone who has left, or another company', function () {
    ($this->line)('BOOK', 'Handbook', 'employee', 1);
    $w = ($this->hire)('W1', 'Worker', $this->workerUser);
    $gone = ($this->hire)('G1', 'Gone');
    $done = EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->where('employee_id', $w->id)->firstOrFail();
    inCompany($this->acme, function () use ($done) {
        ($this->as)($this->hrUser);
        app(ChecklistService::class)->tick($done, $this->hrUser);
    });
    DB::table('employees')->where('id', $gone->id)->update(['employment_status' => 'exited']);

    Carbon::setTestNow('2026-10-20 09:00:00');

    expect(($this->remind)())->toBe(0);
    Notification::assertNothingSent();
    expect(inCompany($this->globex, fn () => app(ChecklistReminders::class)->run()))->toBe(0);
});

it('runs as a command for every company', function () {
    ($this->line)('BOOK', 'Handbook', 'employee', 1);
    ($this->hire)('W1', 'Worker', $this->workerUser);
    Carbon::setTestNow('2026-10-20 09:00:00');

    expect(Artisan::call('hr:checklist-reminders'))->toBe(0)->and(Artisan::output())->toContain('Late items reminded: 1')
        ->and(app(RemindChecklists::class))->toBeInstanceOf(RemindChecklists::class);
    Notification::assertSentTo($this->workerUser, HrNotice::class);
});

// ---- résumé --------------------------------------------------------------------------------------------------------------------------

it('keeps one résumé per candidate on the private disk, replaced by a new one, for HR only', function () {
    $candidate = ($this->candidate)();
    $service = app(ResumeService::class);
    $first = UploadedFile::fake()->create('asha-cv.pdf', 200, 'application/pdf');

    ($this->as)($this->hrUser);
    inCompany($this->acme, fn () => $service->attach($candidate, $first, $this->hrUser));
    $path = $candidate->fresh()->getAttribute('resume_path');

    expect($path)->toStartWith('hr/'.$this->acme->id.'/candidates/'.$candidate->id)->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($candidate->fresh()->getAttribute('resume_name'))->toBe('asha-cv.pdf');

    inCompany($this->acme, fn () => $service->attach($candidate->fresh(), UploadedFile::fake()->create('asha-v2.docx', 100), $this->hrUser));
    $second = $candidate->fresh()->getAttribute('resume_path');
    expect($second)->not->toBe($path)->and(Storage::disk('local')->exists($path))->toBeFalse()->and(Storage::disk('local')->exists($second))->toBeTrue();

    // wrong kind, too big, wrong person
    expect(fn () => $service->attach($candidate->fresh(), UploadedFile::fake()->create('virus.exe', 10), $this->hrUser))->toThrow(ValidationException::class)
        ->and(fn () => $service->attach($candidate->fresh(), UploadedFile::fake()->create('big.pdf', ResumeService::MAX_KB + 10), $this->hrUser))->toThrow(ValidationException::class)
        ->and(fn () => $service->attach($candidate->fresh(), UploadedFile::fake()->create('cv.pdf', 10), $this->workerUser))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->remove($candidate->fresh(), $this->workerUser))->toThrow(AuthorizationException::class);

    inCompany($this->acme, fn () => $service->remove($candidate->fresh(), $this->hrUser));
    expect($candidate->fresh()->getAttribute('resume_path'))->toBeNull()->and(Storage::disk('local')->exists($second))->toBeFalse();
});

it('hands a résumé only to people who may see candidates, and never across companies', function () {
    $candidate = ($this->candidate)();
    inCompany($this->acme, fn () => app(ResumeService::class)->attach($candidate, UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'), $this->hrUser));
    $url = route('hr.candidates.resume', $candidate->id);

    $this->actingAs($this->hrUser)->get($url)->assertOk()->assertHeader('content-disposition');
    $this->actingAs($this->workerUser)->get($url)->assertForbidden();
    $this->actingAs($this->outsider)->get($url)->assertNotFound();
    auth()->logout();
    $this->get($url)->assertRedirect();

    // a candidate with no résumé on file
    $bare = inCompany($this->acme, fn () => app(RecruitmentService::class)->saveCandidate(['name' => 'Ravi Shah', 'mobile' => '9000000502', 'source' => 'portal'], $this->hrUser));
    $this->actingAs($this->hrUser)->get(route('hr.candidates.resume', $bare->id))->assertNotFound();
});

it('attaches a résumé from the candidates screen', function () {
    $candidate = ($this->candidate)();
    ($this->as)($this->hrUser);

    Livewire::actingAs($this->hrUser)->test(CandidateList::class)->call('edit', $candidate->id)
        ->set('resume', UploadedFile::fake()->create('asha.pdf', 50, 'application/pdf'))->call('saveResume')->assertHasNoErrors()->assertSee('asha.pdf');

    expect($candidate->fresh()->getAttribute('resume_name'))->toBe('asha.pdf');

    Livewire::actingAs($this->hrUser)->test(CandidateList::class)->call('edit', $candidate->id)->call('dropResume')->assertHasNoErrors();
    expect($candidate->fresh()->getAttribute('resume_path'))->toBeNull();
});
