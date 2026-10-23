<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Jobs\ProcessLeadApiLog;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Services\LeadApiLogStore;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->connection = makeConnection($this->acme, $this->admin, $this->ahd);
    $this->theirs = makeConnection($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);

    // a delivery written down and left unprocessed, as if the queue had been down, aged by the given number of minutes/days
    $this->stored = function (string $id, $connection = null, string $status = 'received', array $age = ['minutes' => 0]): LeadApiLog {
        $connection ??= $this->connection;
        $log = inCompany($connection->company, fn () => app(LeadApiLogStore::class)->record($connection, app(ProviderRegistry::class)->get('indiamart'), indiaMartRecord(['UNIQUE_QUERY_ID' => $id, 'SENDER_MOBILE' => '98'.str_pad((string) crc32($id), 8, '0', STR_PAD_LEFT) % 100000000]), 'webhook')[0]);
        $when = now()->sub((string) array_key_first($age), (int) reset($age));
        DB::table('lead_api_logs')->where('id', $log->id)->update(['status' => $status, 'created_at' => $when, 'updated_at' => $when]);

        return $log->fresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- picking up what nobody finished ---------------------------------------------------------------------

it('queues deliveries that were stored but never processed, and ones a worker abandoned — not fresh ones, and not failed ones', function () {
    Bus::fake();

    $waiting = ($this->stored)('WAIT', age: ['minutes' => 8]);
    $fresh = ($this->stored)('FRESH', age: ['minutes' => 1]);
    $abandoned = ($this->stored)('ABANDONED', status: 'processing', age: ['minutes' => 15]);
    $working = ($this->stored)('WORKING', status: 'processing', age: ['minutes' => 2]);
    $failed = ($this->stored)('FAILED', status: 'failed', age: ['hours' => 3]);
    $done = ($this->stored)('DONE', status: 'created', age: ['hours' => 3]);
    $theirs = ($this->stored)('THEIRS', $this->theirs, age: ['minutes' => 9]);

    Artisan::call('integrations:retry-stuck');

    $queued = fn () => collect(Bus::dispatched(ProcessLeadApiLog::class))->map(fn ($job) => [$job->companyId, $job->logId])->sortBy(1)->values()->all();

    expect($queued())->toBe(collect([[$this->acme->id, $waiting->id], [$this->acme->id, $abandoned->id], [$this->globex->id, $theirs->id]])->sortBy(1)->values()->all());
});

it('actually finishes a delivery that had been left unprocessed', function () {
    $log = ($this->stored)('LATE', age: ['minutes' => 30]);
    expect(Lead::query()->count())->toBe(0);

    Artisan::call('integrations:retry-stuck');

    expect($log->fresh()->status->value)->toBe('created')->and(Lead::query()->count())->toBe(1);
});

// ---- not keeping people's phone numbers for ever -------------------------------------------------------------------

it('removes log rows older than the retention period — failed ones a while later — and never touches the leads', function () {
    $old = ($this->stored)('OLD', status: 'created', age: ['days' => 100]);
    $oldLinked = ($this->stored)('OLD-LINKED', status: 'linked', age: ['days' => 91]);
    $recent = ($this->stored)('RECENT', status: 'created', age: ['days' => 30]);
    $oldFailed = ($this->stored)('OLD-FAILED', status: 'failed', age: ['days' => 120]);        // inside twice the retention
    $ancientFailed = ($this->stored)('ANCIENT-FAILED', status: 'failed', age: ['days' => 200]);
    $lead = makeLead($this->acme, $this->admin, $this->ahd);
    $old->forceFill(['lead_id' => $lead->id])->saveQuietly();

    Artisan::call('integrations:prune');

    expect(LeadApiLog::query()->pluck('provider_lead_id')->sort()->values()->all())->toBe(['OLD-FAILED', 'RECENT'])
        ->and(Lead::query()->whereKey($lead->id)->exists())->toBeTrue();
});

it('uses each company’s own retention period, and never less than a week', function () {
    app(SettingsService::class)->set('integrations.log_retention_days', 30, $this->acme->id);
    app(SettingsService::class)->set('integrations.log_retention_days', 1, $this->globex->id);

    ($this->stored)('A-OLD', status: 'created', age: ['days' => 40]);
    ($this->stored)('A-NEW', status: 'created', age: ['days' => 10]);
    ($this->stored)('B-OLD', $this->theirs, status: 'created', age: ['days' => 8]);
    ($this->stored)('B-NEW', $this->theirs, status: 'created', age: ['days' => 3]);

    Artisan::call('integrations:prune');

    expect(LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->pluck('provider_lead_id')->sort()->values()->all())->toBe(['A-NEW', 'B-NEW']);
});

it('is scheduled: pulling every five minutes, picking up stragglers every ten, pruning daily', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command ?? '', $e->expression]);

    $find = fn (string $name) => $events->first(fn ($e) => str_contains($e[0], $name));

    expect($find('integrations:pull')[1])->toBe('*/5 * * * *')->and($find('integrations:retry-stuck')[1])->toBe('*/10 * * * *')->and($find('integrations:prune')[1])->toBe('0 0 * * *');
});

// ---- permission defaults ------------------------------------------------------------------------------------------

it('keeps integrations with the administrators: nobody who sells can see, set up or run them', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    foreach (['Company Admin', 'Super Admin'] as $role) {
        foreach (['integration.view', 'integration.edit', 'integration.configure', 'integration.view_audit'] as $permission) {
            expect($held($role, $permission))->toBeTrue("{$role} should hold {$permission}");
        }
    }

    foreach (Role::query()->pluck('name') as $role) {
        if (! in_array($role, ['Company Admin', 'Super Admin'], true)) {
            foreach (['integration.view', 'integration.edit', 'integration.configure'] as $permission) {
                expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
            }
        }
    }
});
