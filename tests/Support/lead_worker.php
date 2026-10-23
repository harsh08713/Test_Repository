<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, does one thing
 * to a lead — so several workers hit the same rule at the same moment.
 *
 *   php tests/Support/lead_worker.php create   <companyId> <actorId> <locationId> <sourceId> <mobile> <startAt>
 *   php tests/Support/lead_worker.php transfer <companyId> <actorId> <leadId> <toUserId> <startAt>
 *   php tests/Support/lead_worker.php complete <companyId> <actorId> <followupId> <outcomeId> <nextAt> <startAt>
 *   php tests/Support/lead_worker.php remind   <clockOffsetSeconds> <startAt>
 *
 * Prints one line: "OK" (or "OK <lead number>"), "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>"
 * for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$startAt = (float) array_pop($args);

try {
    // Connect first, so that what follows the wait is the work itself and not the handshake.
    DB::select('select 1');

    // Everything is loaded and warm; now wait for the shared start time so the calls really collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $said = 'OK';

    if ($mode === 'create') {
        [$companyId, $actorId, $locationId, $sourceId, $mobile] = $args;

        $lead = app(TenantContext::class)->withCompany((int) $companyId, fn () => app(LeadService::class)->create(['lead' => [
            'location_id' => $locationId, 'lead_source_id' => $sourceId, 'name' => "Racing lead {$mobile}", 'mobile' => $mobile,
        ], 'interests' => []], User::query()->findOrFail((int) $actorId)));

        $said = 'OK '.$lead->lead_number;
    } elseif ($mode === 'transfer') {
        [$companyId, $actorId, $leadId, $toUserId] = $args;

        app(TenantContext::class)->withCompany((int) $companyId, fn () => app(LeadTransferService::class)
            ->transfer([(int) $leadId], (int) $toUserId, 'Handed over in a race', User::query()->findOrFail((int) $actorId)));
    } elseif ($mode === 'complete') {
        [$companyId, $actorId, $followupId, $outcomeId, $nextAt] = $args;

        app(TenantContext::class)->withCompany((int) $companyId, fn () => app(FollowupService::class)->complete(
            Followup::query()->findOrFail((int) $followupId),
            ['done' => ['outcome_id' => $outcomeId, 'notes' => 'Done in a race', 'next_at' => $nextAt, 'next_type' => 'call']],
            User::query()->findOrFail((int) $actorId),
        ));
    } elseif ($mode === 'remind') {
        // Each run believes it is a little later than the one before, as overlapping runs of a scheduler do: so the
        // moment a run stamps on a follow-up is its own, and "nothing changed" cannot stand in for the claim.
        Carbon::setTestNow(Carbon::now()->addSeconds((int) ($args[0] ?? 0)));

        Artisan::call('crm:followup-reminders');
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    echo $said, PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}
