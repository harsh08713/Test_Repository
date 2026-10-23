<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Integrations\Enums\InboundStatus;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marketplace deliveries (IndiaMART, Just Dial) that failed, and the state of each connection. The buyer’s payload is never shown here:
 * it holds a phone number and an e-mail address, and the delivery screen already shows it to those who may.
 */
final class IntegrationSyncFailures extends Report
{
    public function key(): string
    {
        return 'integration-failures';
    }

    public function title(): string
    {
        return 'Integration sync failures and webhook status';
    }

    public function description(): string
    {
        return 'Marketplace lead deliveries by status — pick “Failed” for the ones that need a person — and how many connections are failing now.';
    }

    public function group(): string
    {
        return 'Control';
    }

    public function permission(): string
    {
        return 'integration.view';
    }

    public function filters(): array
    {
        return ['date', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Received';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    public function choices(): array
    {
        return collect(InboundStatus::cases())->mapWithKeys(fn (InboundStatus $s) => [$s->value => ucfirst($s->value)])->all();
    }

    public function defaultChoice(): string
    {
        return 'failed';
    }

    public function note(): string
    {
        return 'Built against the providers’ documented formats; not yet verified on a live account. The delivery screen (Integrations) can retry a failed delivery.';
    }

    public function columns(): array
    {
        return [
            new C('created_at', 'Received', C::DATETIME), new C('provider', 'Provider'), new C('via', 'Arrived by'), new C('status_label', 'Status'), new C('attempts', 'Attempts', C::NUMBER),
            new C('provider_lead_id', 'Provider’s lead id'), new C('lead_number', 'Lead made'), new C('error', 'What went wrong'), new C('processed_at', 'Last tried', C::DATETIME),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = LeadApiLog::query()
            ->leftJoin('leads', 'leads.id', '=', 'lead_api_logs.lead_id')
            ->select([
                'lead_api_logs.id', 'lead_api_logs.created_at', 'lead_api_logs.provider', 'lead_api_logs.via', 'lead_api_logs.status', 'lead_api_logs.attempts',
                'lead_api_logs.provider_lead_id', 'lead_api_logs.error', 'lead_api_logs.processed_at', 'leads.lead_number',
            ]);

        $this->between($query, 'lead_api_logs.created_at', $input, true);
        $input->choice === null || $query->where('lead_api_logs.status', $input->choice);

        return $query->orderByDesc('lead_api_logs.created_at')->orderByDesc('lead_api_logs.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['status_label'] = ucfirst((string) $row->getRawOriginal('status'));
        $out['provider'] = ucfirst(str_replace('_', ' ', (string) $row->getRawOriginal('provider')));

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(lead_api_logs.status = 'failed'), 0) as failed");
        $failing = IntegrationConnection::query()->where('enabled', true)->where('consecutive_failures', '>', 0)->count();

        return ['Deliveries' => number_format((int) ($t->total ?? 0)), 'Failed' => number_format((int) ($t->failed ?? 0)), 'Connections failing now' => number_format($failing)];
    }
}
