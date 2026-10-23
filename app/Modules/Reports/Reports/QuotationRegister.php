<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
use Illuminate\Database\Eloquent\Builder;

/** Every revision of every quotation, who owns it, where it stands, whether it became an order — and what share of offers did. */
final class QuotationRegister extends Report
{
    public function key(): string
    {
        return 'quotation-register';
    }

    public function title(): string
    {
        return 'Quotation register and conversion';
    }

    public function description(): string
    {
        return 'Every quotation revision you may see with its state, value and the sales order made from it, and the share of quotations that were accepted and ordered.';
    }

    public function group(): string
    {
        return 'Pipeline';
    }

    public function permission(): string
    {
        return 'quotation.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Quotation date';
    }

    public function choiceLabel(): string
    {
        return 'State';
    }

    public function choices(): array
    {
        return collect(QuotationState::cases())->mapWithKeys(fn (QuotationState $s) => [$s->value => $s->label()])->all();
    }

    public function note(): string
    {
        return 'Conversion counts each quotation once, however many revisions it had: “sent” = at least one revision was sent; “accepted” = a revision was accepted by the customer; “ordered” = a sales order that is not cancelled was made from it.';
    }

    public function columns(): array
    {
        return [
            new C('quotation_number', 'Quotation'), new C('revision', 'Rev', C::NUMBER), new C('quotation_date', 'Date', C::DATE), new C('party_name', 'Customer'),
            new C('owner', 'Owner'), new C('branch', 'Branch'), new C('state', 'State'), new C('valid_until', 'Valid until', C::DATE),
            new C('taxable_total', 'Value before tax', C::MONEY), new C('grand_total', 'Total', C::MONEY), new C('order_number', 'Sales order'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(QuotationAccess::class)->restrict(Quotation::query(), $user)
            ->leftJoin('leads', 'leads.id', '=', 'quotations.lead_id')
            ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
            ->leftJoin('locations', 'locations.id', '=', 'quotations.location_id')
            ->leftJoin('sales_orders', fn ($j) => $j->on('sales_orders.quotation_id', '=', 'quotations.id')->where('sales_orders.state', '<>', 'cancelled')->whereNull('sales_orders.deleted_at'))
            ->select([
                'quotations.id', 'quotations.quotation_number', 'quotations.revision_no as revision', 'quotations.quotation_date', 'quotations.party_name', 'quotations.state',
                'quotations.valid_until', 'quotations.taxable_total', 'quotations.grand_total',
                'users.name as owner', 'locations.name as branch', 'sales_orders.so_number as order_number',
            ]);

        $this->between($query, 'quotations.quotation_date', $input);
        $input->branch === null || $query->where('quotations.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('leads.assigned_to', $input->peopleIds);
        $input->choice === null || $query->where('quotations.state', $input->choice);

        return $query->orderByDesc('quotations.quotation_date')->orderByDesc('quotations.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['state'] = QuotationState::tryFrom((string) $row->getRawOriginal('state'))?->label();

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals(
            $this->query($input, $user),
            'count(distinct quotations.quotation_number) as offers, '
            .'count(distinct case when quotations.sent_at is not null then quotations.quotation_number end) as sent, '
            .'count(distinct case when quotations.accepted_at is not null then quotations.quotation_number end) as accepted, '
            .'count(distinct case when sales_orders.id is not null then quotations.quotation_number end) as ordered',
        );
        $sent = (int) ($t->sent ?? 0);
        $percent = fn (int $n) => $sent === 0 ? '—' : number_format(100 * $n / $sent, 1).' %';

        return [
            'Quotations' => number_format((int) ($t->offers ?? 0)), 'Sent' => number_format($sent),
            'Accepted' => number_format((int) ($t->accepted ?? 0)).' ('.$percent((int) ($t->accepted ?? 0)).')',
            'Ordered' => number_format((int) ($t->ordered ?? 0)).' ('.$percent((int) ($t->ordered ?? 0)).')',
        ];
    }
}
