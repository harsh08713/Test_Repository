<?php

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\RateMaintenance;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency: separate PHP processes, each with its own database connection, submit rates for approval at the
 * same instant. Two live rates for one item, branch, unit and price list must never overlap, however the requests
 * interleave; the lock on the item taken at submit is what guarantees it, and these tests fail if it is removed.
 * They commit to the test database (no wrapping transaction, which would hide the locking) and clean up after themselves.
 */

/**
 * Start one process per rate, all told to submit at the same moment, and collect what each printed.
 *
 * @param  list<int>  $rateIds
 * @return list<string> one line per worker: "OK" or "ERROR: …"
 */
function runRateSubmitters(array $rateIds, int $userId, int $companyId): array
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
    ]);

    // Booting the framework takes a moment in each process: give them all time to be ready before the shot.
    $startAt = microtime(true) + 4.0 + count($rateIds) * 0.5;

    $running = [];
    foreach ($rateIds as $rateId) {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/submit_rate.php'), (string) $rateId, (string) $userId, (string) $companyId, sprintf('%.6F', $startAt)],
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
    $this->company = makeCompany('RC'.$this->tag);
    $this->location = makeLocation($this->company, 'R'.$this->tag);
    $this->user = User::factory()->create();

    // The smallest world a rate needs: a unit, an item group and an approved item (no seeded defaults to clean up).
    $unit = (new Unit(['code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true]))->forceFill(['company_id' => $this->company->id]);
    $unit->save();
    $group = (new ItemGroup(['code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag]))->forceFill(['company_id' => $this->company->id]);
    $group->save();
    $this->item = (new Item(['name' => 'Item '.$this->tag, 'po_tolerance_percent' => 0]))->forceFill([
        'company_id' => $this->company->id, 'item_group_id' => $group->id, 'base_unit_id' => $unit->id,
        'approval_status' => ApprovalStatus::Approved, 'workflow_stage' => 'approved',
    ]);
    $this->item->save();

    // drafts for one slot, all open-ended from different days: every pair overlaps
    $this->draft = fn (string $from, ?string $to = null) => app(TenantContext::class)->withCompany($this->company->id, fn () => app(RateMaintenance::class)->save(null, ['rate' => [
        'item_id' => (string) $this->item->id, 'location_id' => (string) $this->location->id, 'unit_id' => (string) $this->item->base_unit_id,
        'price_list_id' => '', 'rate' => '10', 'effective_from' => $from, 'effective_to' => $to ?? '', 'remarks' => '',
    ]], $this->user));
});

afterEach(function () {
    $id = $this->company->id;

    DB::table('approvals')->where('company_id', $id)->delete();
    DB::table('activity_log')->where('company_id', $id)->delete();
    DB::table('rates')->where('company_id', $id)->delete();
    DB::table('item_stage_history')->where('company_id', $id)->delete();
    DB::table('items')->where('company_id', $id)->delete();
    DB::table('item_groups')->where('company_id', $id)->delete();
    DB::table('units')->where('company_id', $id)->delete();
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->user->id)->delete();
});

it('lets exactly one of several overlapping rates through when they are submitted at the same instant', function () {
    foreach ([1, 2, 3] as $round) {
        $rates = collect([
            ($this->draft)('2027-0'.$round.'-01'),
            ($this->draft)('2027-0'.$round.'-05'),
            ($this->draft)('2027-0'.$round.'-09'),
            ($this->draft)('2027-0'.$round.'-13'),
        ])->each(fn (Rate $r) => expect($r->approval_status)->toBe(ApprovalStatus::Draft));

        $lines = runRateSubmitters($rates->pluck('id')->all(), $this->user->id, $this->company->id);

        $accepted = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
        $refused = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'ERROR:') && str_contains($l, 'does not overlap another rate'))->count();

        expect($accepted)->toBe(1, "round {$round}: ".implode(' | ', $lines))
            ->and($refused)->toBe(3, "round {$round}: ".implode(' | ', $lines))
            ->and(DB::table('rates')->whereIn('id', $rates->pluck('id'))->where('approval_status', 'pending')->count())->toBe(1);

        // the winner is "live" and would clash with the next round's drafts, so stand it down first
        DB::table('rates')->where('company_id', $this->company->id)->where('approval_status', 'pending')->update(['approval_status' => 'rejected']);
    }
});

it('lets rates that do not overlap all be submitted together, without blocking each other', function () {
    $rates = [
        ($this->draft)('2027-01-01', '2027-01-31'),
        ($this->draft)('2027-02-01', '2027-02-28'),
        ($this->draft)('2027-03-01', '2027-03-31'),
        ($this->draft)('2027-04-01', '2027-04-30'),
    ];

    $lines = runRateSubmitters(array_map(fn (Rate $r) => $r->id, $rates), $this->user->id, $this->company->id);

    expect($lines)->toBe(['OK', 'OK', 'OK', 'OK'])
        ->and(DB::table('rates')->where('company_id', $this->company->id)->where('approval_status', 'pending')->count())->toBe(4);
});
