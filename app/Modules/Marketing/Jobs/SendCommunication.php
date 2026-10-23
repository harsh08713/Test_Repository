<?php

namespace App\Modules\Marketing\Jobs;

use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Hands one queued message to the mail server. Safe to deliver twice: only a queued message is claimed. */
class SendCommunication implements ShouldQueue
{
    use Dispatchable, Queueable, RunsInCompanyContext;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $communicationId, int $companyId)
    {
        $this->forCompany($companyId);
    }

    public function handle(CommunicationService $messages): void
    {
        $messages->deliver($this->communicationId, (int) $this->companyId);
    }

    /** The worker died or timed out: do not leave the message looking as if it is being sent for ever. */
    public function failed(Throwable $exception): void
    {
        Communication::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($this->communicationId)->where('company_id', $this->companyId)->where('status', Communication::SENDING)
            ->update(['status' => Communication::FAILED, 'failure' => 'The sending process stopped before the message was handed over.']);
    }
}
