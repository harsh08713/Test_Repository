<?php

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Masters\Models\State;
use App\Modules\Sales\Models\Quotation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the promise a quotation's revisions depend on, with separate PHP processes (each with its own database
 * connection) acting at the same instant: a sent quotation revised by five people at once gets **one** new revision, the others are
 * told one has been made, and the old revision is superseded exactly once. Two things make it so, each with its own proof:
 * `unique(company, number, revision)` and the row lock on the revision being revised.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * Start one process per entry, all told to revise the same quotation at the same moment, and collect the one line each printed.
 *
 * @return list<string>
 */
function runQuotationWorkers(int $count, int $companyId, int $actorId, int $quotationId): array
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
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);

    $running = [];
    for ($i = 0; $i < $count; $i++) {
        $running[] = [$process = proc_open(
            [PHP_BINARY, base_path('tests/Support/quotation_worker.php'), 'revise', (string) $companyId, (string) $actorId, (string) $quotationId, $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        ), $pipes];
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
    $this->company = makeCompany('QC'.$this->tag);
    $this->location = makeLocation($this->company, 'Q'.$this->tag);
    $this->owner = makeUser([], [$this->company]);

    // The smallest world a revision needs: a lead (open), a state and a sent quotation.
    $source = (new LeadSource(['code' => 'S'.$this->tag, 'name' => 'Source '.$this->tag]))->forceFill(['company_id' => $this->company->id]);
    $source->save();
    $state = (new State(['code' => 'ST'.$this->tag, 'gst_code' => '24', 'name' => 'State '.$this->tag]))->forceFill(['company_id' => $this->company->id]);
    $state->save();

    $lead = new Lead;
    $lead->forceFill([
        'company_id' => $this->company->id, 'location_id' => $this->location->id, 'lead_number' => "LD/{$this->tag}/1", 'lead_date' => now()->toDateString(),
        'lead_source_id' => $source->id, 'name' => 'Racing lead', 'mobile' => '9500000001', 'stage' => 'qualified',
        'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id,
    ])->save();

    $this->sent = function (int $n) use ($lead, $state) {
        $q = new Quotation;
        $q->forceFill([
            'company_id' => $this->company->id, 'location_id' => $this->location->id, 'quotation_number' => "QT/{$this->tag}/{$n}", 'revision_no' => 0,
            'lead_id' => $lead->id, 'state' => 'sent', 'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(20)->toDateString(),
            'price_date' => now()->toDateString(), 'party_name' => 'Racing customer', 'place_of_supply_state_id' => $state->id, 'supply_type' => 'intra',
            'sent_at' => now(), 'created_by' => $this->owner->id,
        ])->save();

        return $q;
    };
    $this->revisionsOf = fn (Quotation $q) => DB::table('quotations')->where('company_id', $this->company->id)->where('quotation_number', $q->quotation_number)->orderBy('revision_no');
});

afterEach(function () {
    $id = $this->company->id;

    DB::table('quotations')->where('company_id', $id)->update(['previous_id' => null]);   // revisions point at each other

    foreach (['quotation_history', 'quotation_dispatches', 'quotation_items', 'quotations', 'lead_stage_history', 'lead_interests', 'leads', 'lead_sources',
        'states', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('makes one revision, and supersedes the old one once, when a sent quotation is revised by several processes at once', function () {
    foreach (range(1, 3) as $round) {
        $q = ($this->sent)($round);

        $lines = runQuotationWorkers(5, $this->company->id, $this->owner->id, $q->id);

        $made = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'OK '))->count();
        $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'has already been made'))->count();
        $rows = ($this->revisionsOf)($q)->get();

        expect($made)->toBe(1, "round {$round}: ".implode(' | ', $lines))
            ->and($told)->toBe(4, "round {$round}: ".implode(' | ', $lines))
            ->and($rows->pluck('revision_no')->map(fn ($n) => (int) $n)->all())->toBe([0, 1])
            ->and($rows[0]->state)->toBe('superseded')->and($rows[0]->superseded_at)->not->toBeNull()
            ->and($rows[1]->state)->toBe('draft')->and((int) $rows[1]->previous_id)->toBe($q->id)
            ->and(DB::table('quotation_history')->where('quotation_id', $q->id)->where('to_state', 'superseded')->count())->toBe(1);
    }
});
