<?php

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Enums\ApprovalStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the promises a deal depends on, with separate PHP processes (each with its own database connection) acting
 * at the same instant:
 *
 *  - A lead converts **once**: the same lead converted by five processes at once makes one deal, burns one number and leaves the
 *    lead at Deal; the other four are told it has been converted. Two things make it so, and each has its own proof here:
 *    `deals.lead_id` is unique (remove it and the lock alone is not enough, because a request that waited for the lock reads the
 *    deals as they were when its transaction began), and the lead's row is locked while it is converted.
 *  - Different leads of one branch, converted together, queue on the branch's number series: every deal gets its own number, in a
 *    row with no gap, and nothing deadlocks.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * Start one process per lead, all told to convert it at the same moment, and collect the one line each printed.
 *
 * @param  list<int>  $leadIds  one process for each id (repeat an id to race on one lead)
 * @return list<string>
 */
function runDealWorkers(array $leadIds, int $companyId, int $actorId, int $itemId): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ]);

    // Booting the framework takes a moment in each process: give them all time to be ready before the shot.
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + count($leadIds) * 0.5);

    $running = [];
    foreach ($leadIds as $leadId) {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/deal_worker.php'), 'convert', (string) $companyId, (string) $actorId, (string) $leadId, (string) $itemId, $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $running[] = [$process, $pipes];
    }

    $lines = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = trim(str_replace("\r", '', stream_get_contents($pipes[1])));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines[] = $stdout !== '' ? $stdout : 'ERROR: no output'.($stderr !== '' ? " (stderr: {$stderr})" : '');
    }

    return $lines;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('DC'.$this->tag);
    $this->location = makeLocation($this->company, 'D'.$this->tag);
    $this->owner = makeUser([], [$this->company]);

    // The smallest world a deal needs: a unit, an item group, an approved item and a lead source (no seeded defaults to clean up).
    $unit = (new Unit(['code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true]))->forceFill(['company_id' => $this->company->id]);
    $unit->save();
    $group = (new ItemGroup(['code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag]))->forceFill(['company_id' => $this->company->id]);
    $group->save();
    $this->item = (new Item(['name' => 'Item '.$this->tag, 'po_tolerance_percent' => 0]))->forceFill([
        'company_id' => $this->company->id, 'item_group_id' => $group->id, 'base_unit_id' => $unit->id,
        'approval_status' => ApprovalStatus::Approved, 'workflow_stage' => 'approved',
    ]);
    $this->item->save();
    $this->source = (new LeadSource(['code' => 'S'.$this->tag, 'name' => 'Source '.$this->tag]))->forceFill(['company_id' => $this->company->id]);
    $this->source->save();

    // a qualified lead, owned by the person who converts it
    $this->lead = function (int $n) {
        $lead = new Lead;
        $lead->forceFill([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'lead_number' => "LD/{$this->tag}/{$n}", 'lead_date' => now()->toDateString(),
            'lead_source_id' => $this->source->id, 'name' => "Racing lead {$n}", 'mobile' => '95'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'stage' => 'qualified',
            'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id,
        ])->save();

        return $lead;
    };
    $this->dealsOf = fn () => DB::table('deals')->where('company_id', $this->company->id);
    $this->lastNumber = fn () => (int) DB::table('number_series')->where('company_id', $this->company->id)->where('document_type', 'deal')->value('last_number');
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['deal_stage_history', 'deal_items', 'deals', 'lead_stage_history', 'lead_interests', 'leads', 'lead_sources', 'activity_log', 'item_stage_history',
        'items', 'item_groups', 'units', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('makes one deal, and burns one number, when the same lead is converted by several processes at once', function () {
    foreach (range(1, 3) as $round) {
        $lead = ($this->lead)($round);
        $before = ($this->lastNumber)();

        $lines = runDealWorkers(array_fill(0, 5, $lead->id), $this->company->id, $this->owner->id, $this->item->id);

        $made = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'OK '))->count();
        $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'already been converted'))->count();

        expect($made)->toBe(1, "round {$round}: ".implode(' | ', $lines))
            ->and($told)->toBe(4, "round {$round}: ".implode(' | ', $lines))
            ->and(DB::table('deals')->where('lead_id', $lead->id)->count())->toBe(1)
            ->and(DB::table('deal_items')->whereIn('deal_id', DB::table('deals')->where('lead_id', $lead->id)->pluck('id'))->count())->toBe(1)
            ->and(($this->lastNumber)())->toBe($before + 1, "round {$round}: the four that were refused took no number")
            ->and(DB::table('leads')->where('id', $lead->id)->value('stage'))->toBe('deal')
            ->and(DB::table('lead_stage_history')->where('lead_id', $lead->id)->where('to_stage', 'deal')->count())->toBe(1);
    }
});

it('numbers deals converted together one after another, without a gap, a repeat or a deadlock', function () {
    $leads = collect(range(11, 15))->map(fn (int $n) => ($this->lead)($n));

    $lines = runDealWorkers($leads->pluck('id')->all(), $this->company->id, $this->owner->id, $this->item->id);

    $serials = collect($lines)->map(fn (string $l) => (int) substr($l, strrpos($l, '/') + 1))->sort()->values()->all();

    expect(collect($lines)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $lines))
        ->and($serials)->toBe([1, 2, 3, 4, 5])
        ->and(($this->dealsOf)()->count())->toBe(5)
        ->and(($this->dealsOf)()->distinct()->count('deal_number'))->toBe(5)
        ->and(($this->lastNumber)())->toBe(5)
        ->and(DB::table('leads')->where('company_id', $this->company->id)->where('stage', 'deal')->count())->toBe(5);
});
