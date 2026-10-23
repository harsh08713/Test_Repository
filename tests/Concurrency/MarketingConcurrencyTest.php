<?php

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\CampaignStep;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowVersion;
use App\Modules\Workspace\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises marketing makes, with separate PHP processes (each with its own database connection) running at the same instant:
 *
 *  - A campaign member gets each step **once**: four runners over twenty people who are all due make twenty messages, not eighty — whatever order the
 *    processes interleave in (the message key is unique, and the member is moved on only by a conditional UPDATE).
 *  - A workflow runs **once for each event**: four engines over the same new leads make one run and one task for each lead
 *    (`unique(workflow_id, trigger_key)` is claimed before anything is done).
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * @return list<string>
 */
function runMarketingWorkers(string $mode, int $count): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);
    $running = [];

    for ($i = 0; $i < $count; $i++) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/marketing_worker.php'), $mode, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('MK'.$this->tag, ['state_code' => '24']);
    $this->location = makeLocation($this->company, 'M'.$this->tag, ['state_code' => '24']);
    $this->owner = makeUser(['Sales Manager'], [$this->company]);
    $c = $this->company->id;
    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    DB::table('company_user')->where('user_id', $this->owner->id)->update(['all_locations' => true]);

    $this->source = (new LeadSource(['code' => 'S'.$this->tag, 'name' => 'Source '.$this->tag]))->forceFill(['company_id' => $c]);
    $this->source->save();

    $this->lead = function (int $n, string $stage = 'new') use ($c) {
        $lead = new Lead;
        $lead->forceFill([
            'company_id' => $c, 'location_id' => $this->location->id, 'lead_number' => "LD/{$this->tag}/{$n}", 'lead_date' => now()->toDateString(), 'lead_source_id' => $this->source->id,
            'name' => "Lead {$n}", 'mobile' => '95'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'email' => "lead{$n}.{$this->tag}@example.com", 'stage' => $stage,
            'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id,
        ])->save();

        return $lead;
    };
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['communications', 'campaign_members', 'campaign_steps', 'workflow_runs', 'workflow_versions', 'task_assignments', 'tasks', 'workflows', 'campaigns', 'message_templates',
        'contact_consents', 'lead_stage_history', 'lead_transfers', 'leads', 'lead_sources', 'approvals', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('notifications')->where('notifiable_id', $this->owner->id)->delete();
    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('makes each campaign step once for each member when four runners work at the same moment', function () {
    $c = $this->company->id;
    $template = new MessageTemplate;
    $template->fill(['name' => 'Offer', 'channel' => 'email', 'purpose' => 'transactional', 'subject' => 'Hello {{first_name}}', 'body' => 'Dear {{name}}'])->forceFill(['company_id' => $c, 'approval_status' => 'approved'])->save();

    $campaign = new Campaign;
    $campaign->fill(['name' => 'Race', 'type' => 'email', 'start_date' => now()->subDay()->toDateString()])->forceFill([
        'company_id' => $c, 'location_id' => $this->location->id, 'code' => "CP/{$this->tag}/1", 'owner_id' => $this->owner->id, 'audience_kind' => 'leads', 'audience' => ['stages' => ['new']],
        'exit_stages' => [], 'state' => 'draft', 'approval_status' => 'draft',
    ])->save();
    foreach ([1, 2] as $position) {
        (new CampaignStep)->forceFill(['company_id' => $c, 'campaign_id' => $campaign->id, 'position' => $position, 'delay_hours' => 0, 'message_template_id' => $template->id])->save();
    }
    DB::table('campaigns')->where('id', $campaign->id)->update(['state' => 'running', 'approval_status' => 'approved', 'launched_at' => now()->subHour()]);

    foreach (range(1, 20) as $n) {
        $lead = ($this->lead)($n);
        (new CampaignMember)->forceFill([
            'company_id' => $c, 'campaign_id' => $campaign->id, 'subject_type' => 'lead', 'subject_id' => $lead->id, 'name' => $lead->name, 'email' => $lead->email, 'mobile' => $lead->mobile,
            'status' => 'active', 'next_step' => 1, 'next_due_at' => now()->subMinute(), 'lead_id' => $lead->id,
        ])->save();
    }

    $first = runMarketingWorkers('campaign', 4);

    expect(collect($first)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $first))
        ->and(DB::table('communications')->where('company_id', $c)->count())->toBe(20, implode(' | ', $first))
        ->and(DB::table('communications')->where('company_id', $c)->distinct()->count('idempotency_key'))->toBe(20)
        ->and(DB::table('campaign_members')->where('company_id', $c)->where('next_step', 2)->count())->toBe(20);

    $second = runMarketingWorkers('campaign', 4);

    expect(collect($second)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $second))
        ->and(DB::table('communications')->where('company_id', $c)->count())->toBe(40, implode(' | ', $second))
        ->and(DB::table('campaign_members')->where('company_id', $c)->where('status', 'completed')->count())->toBe(20)
        ->and(DB::table('campaigns')->where('company_id', $c)->value('state'))->toBe('completed');
});

it('runs a workflow once for each event when four engines work at the same moment', function () {
    $c = $this->company->id;
    $workflow = new Workflow;
    $workflow->fill(['name' => 'Race'])->forceFill(['company_id' => $c, 'trigger' => 'lead_created', 'state' => 'active', 'run_as' => $this->owner->id, 'activated_at' => now()->subHour()])->save();
    $version = new WorkflowVersion;
    $version->forceFill(['company_id' => $c, 'workflow_id' => $workflow->id, 'version' => 1, 'saved_by' => $this->owner->id, 'definition' => [
        'params' => [], 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'assignee' => 'owner', 'due_hours' => 24, 'priority' => 'normal']],
    ]])->save();
    $workflow->forceFill(['current_version_id' => $version->id])->save();

    foreach (range(1, 12) as $n) {
        ($this->lead)($n);
    }

    $lines = runMarketingWorkers('workflow', 4);

    expect(collect($lines)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $lines))
        ->and(collect($lines)->sum(fn (string $l) => (int) (preg_match('/ok=(\d+)/', $l, $m) ? $m[1] : 0)))->toBe(12, implode(' | ', $lines))
        ->and(DB::table('workflow_runs')->where('company_id', $c)->count())->toBe(12)
        ->and(DB::table('workflow_runs')->where('company_id', $c)->where('status', 'ok')->count())->toBe(12, implode(' | ', $lines))
        ->and(DB::table('tasks')->where('company_id', $c)->count())->toBe(12);

    // and a second round has nothing left to do
    $again = runMarketingWorkers('workflow', 2);
    expect(collect($again)->every(fn (string $l) => str_contains($l, 'ok=0 failed=0 skipped=0')))->toBeTrue(implode(' | ', $again))->and(DB::table('tasks')->where('company_id', $c)->count())->toBe(12);
    expect(Task::query()->withoutGlobalScopes()->where('company_id', $c)->pluck('title')->unique()->count())->toBe(12);
});
