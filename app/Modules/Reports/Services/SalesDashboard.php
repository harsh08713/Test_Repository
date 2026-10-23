<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Services\CampaignStats;
use App\Modules\Reports\Reports\TargetVsAchievement;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\TaskAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the sales dashboard. Every number is read through the same access classes the screens and the reports use, so the
 * dashboard of a salesperson shows their own work, a manager's their teams', an administrator's the company’s — and only for the sections the
 * person may open at all. **Actual** figures (approved sales orders, follow-ups done) and **forecast** figures (open deals × probability) are
 * returned apart and named so on the screen, and are never added together.
 */
final class SalesDashboard
{
    public function __construct(
        private readonly LeadAccess $leads,
        private readonly DealAccess $deals,
        private readonly QuotationAccess $quotations,
        private readonly SalesOrderAccess $orders,
        private readonly FollowupAgenda $agenda,
        private readonly TenantContext $tenant,
        private readonly TaskAccess $tasks,
        private readonly SalesScope $scope,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @return array<string, mixed> a key per section; a section the person may not open is null
     */
    public function build(User $user, string $from, string $to, ?int $branch): array
    {
        return [
            'period' => ['from' => $from, 'to' => $to],
            'approvals' => $this->approvals->pendingFor($user)->count(),
            'leads' => $user->can('lead.view') ? $this->leadSection($user, $from, $to, $branch) : null,
            'followups' => $user->can('followup.view') ? $this->followupSection($user, $branch) : null,
            'targets' => $user->can('target.view') && $user->can('report.view') ? $this->targetSection($user, $branch) : null,
            'orders' => $user->can('sales_order.view') ? $this->orderSection($user, $from, $to, $branch) : null,
            'deals' => $user->can('deal.view') ? $this->dealSection($user, $branch) : null,
            'tasks' => $user->can('task.view') ? $this->taskSection($user, $branch) : null,
            'campaigns' => $user->can('campaign.view') ? $this->campaignSection() : null,
            'sla' => $user->can('sla_policy.view') ? $this->slaSection($user, $from, $to) : null,
            'quotes' => $user->can('quotation.view') ? $this->quoteSection($user, $from, $to, $branch) : null,
            'branches' => $this->branchSection($user, $from, $to, $branch),
        ];
    }

    /** @return array<string, mixed> */
    private function leadSection(User $user, string $from, string $to, ?int $branch): array
    {
        $base = fn (): Builder => $this->leads->restrict(Lead::query(), $user)
            ->whereBetween('leads.lead_date', [$from, $to])
            ->when($branch !== null, fn ($q) => $q->where('leads.location_id', $branch));

        $byStage = $base()->toBase()->selectRaw('leads.stage as stage, count(*) as n')->groupBy('leads.stage')->pluck('n', 'stage')->map(fn ($n) => (int) $n)->all();
        $stages = [];

        foreach (LeadStage::cases() as $stage) {
            $stages[] = ['label' => $stage->label(), 'value' => $byStage[$stage->value] ?? 0];
        }

        $sources = $base()->toBase()->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw("coalesce(lead_sources.name, '(no source)') as label, count(*) as n")->groupBy('leads.lead_source_id', 'lead_sources.name')
            ->orderByDesc('n')->limit(8)->get()->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->n])->all();

        $total = array_sum($byStage);
        $reach = fn (array $names): int => (int) array_sum(array_intersect_key($byStage, array_flip($names)));

        return [
            'total' => $total,
            'stages' => $stages,
            'sources' => $sources,
            'funnel' => [
                ['label' => 'Leads made', 'value' => $total],
                ['label' => 'Qualified or further', 'value' => $reach(['qualified', 'deal', 'quotation', 'won'])],
                ['label' => 'Became deals', 'value' => $reach(['deal', 'quotation', 'won'])],
                ['label' => 'Quoted', 'value' => $reach(['quotation', 'won'])],
                ['label' => 'Won', 'value' => $reach(['won'])],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function followupSection(User $user, ?int $branch): array
    {
        $now = now();
        $pending = fn (): Builder => Followup::query()->whereIn('followups.id', $this->agenda->query($user)->select('followups.id'))
            ->where('followups.status', 'pending')
            ->when($branch !== null, fn ($q) => $q->where('followups.location_id', $branch));

        $today = (clone $pending())->whereBetween('followups.scheduled_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->where('followups.scheduled_at', '>=', $now)->count();
        $overdue = (clone $pending())->where('followups.scheduled_at', '<', $now)->count();

        $next = $pending()->with('assignee:id,name')->where(fn ($q) => $q->where('followups.scheduled_at', '<', $now->copy()->endOfDay()))
            ->orderBy('followups.scheduled_at')->limit(6)->get()
            ->map(fn (Followup $f) => ['when' => $f->scheduled_at, 'what' => $this->agenda->subjectLabel($f), 'who' => $f->assignee->name ?? '—', 'overdue' => $f->scheduled_at->lt($now)])->all();

        return ['today' => $today, 'overdue' => $overdue, 'next' => $next];
    }

    /** @return array<string, mixed> */
    private function taskSection(User $user, ?int $branch): array
    {
        $live = fn (): Builder => $this->tasks->restrict(Task::query(), $user)->whereIn('tasks.status', ['open', 'in_progress'])
            ->when($branch !== null, fn ($q) => $q->where('tasks.location_id', $branch));

        $mine = (clone $live())->where('tasks.assigned_to', $user->getKey());

        return [
            'mine' => (clone $mine)->count(),
            'mine_overdue' => (clone $mine)->where('tasks.due_at', '<', now())->count(),
            'due_today' => (clone $mine)->whereBetween('tasks.due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'all_overdue' => (clone $live())->where('tasks.due_at', '<', now())->count(),
        ];
    }

    /**
     * The campaigns launched most recently with what they brought (company figures about a campaign, as on the campaign's own page).
     *
     * @return array<string, mixed>
     */
    private function campaignSection(): array
    {
        $campaigns = Campaign::query()->whereNotNull('launched_at')->orderByDesc('launched_at')->limit(5)->get(['id', 'code', 'name', 'budget', 'state']);
        $stats = app(CampaignStats::class)->for($campaigns->pluck('id')->map(fn ($id) => (int) $id)->all());

        return ['rows' => $campaigns->map(fn (Campaign $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'budget' => $c->budget, 'state' => $c->state] + $stats[$c->id])->all()];
    }

    /** @return array<string, mixed> */
    private function slaSection(User $user, string $from, string $to): array
    {
        $visible = $this->scope->visibleUserIds($user);
        $t = SlaClock::query()->when($visible !== null, fn ($q) => $q->whereIn('owner_id', $visible))
            ->whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])->toBase()
            ->selectRaw("coalesce(sum(state = 'met' and breached_at is null), 0) as on_time")
            ->selectRaw("coalesce(sum(state = 'met' and breached_at is not null), 0) as late")
            ->selectRaw("coalesce(sum(state = 'running' and breached_at is not null), 0) as open_late")
            ->selectRaw("coalesce(sum(state = 'running' and breached_at is null), 0) as running")->first();

        $measured = (int) $t->on_time + (int) $t->late + (int) $t->open_late;

        return [
            'on_time' => (int) $t->on_time, 'missed' => (int) $t->late + (int) $t->open_late, 'running' => (int) $t->running,
            'percent' => $measured === 0 ? null : round(100 * (int) $t->on_time / $measured, 1),
        ];
    }

    /** @return array<string, mixed>|null */
    private function targetSection(User $user, ?int $branch): ?array
    {
        $report = new TargetVsAchievement;

        if (! $report->canRun($user)) {
            return null;
        }

        $input = new ReportInput(branch: $branch, choice: 'current');
        $rows = [];

        foreach ($report->query($input, $user)->limit(6)->get() as $target) {
            $rows[] = $report->present($target);
        }

        return ['rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function orderSection(User $user, string $from, string $to, ?int $branch): array
    {
        $approved = fn (): Builder => $this->orders->restrict(SalesOrder::query(), $user)
            ->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled')
            ->whereBetween('sales_orders.order_date', [$from, $to])
            ->when($branch !== null, fn ($q) => $q->where('sales_orders.location_id', $branch));

        $t = $approved()->toBase()->selectRaw('count(*) as n, coalesce(sum(sales_orders.taxable_total), 0) as net, coalesce(sum(sales_orders.grand_total), 0) as gross')->first();

        $people = $approved()->toBase()->join('users', 'users.id', '=', 'sales_orders.assigned_to')
            ->selectRaw('users.name as label, count(*) as orders, sum(sales_orders.taxable_total) as value')->groupBy('sales_orders.assigned_to', 'users.name')
            ->orderByDesc('value')->limit(5)->get()->map(fn ($r) => ['label' => $r->label, 'orders' => (int) $r->orders, 'value' => (string) $r->value])->all();

        // a team’s sales are its members’ for the days they were on it
        $teams = $approved()->toBase()
            ->join('sales_team_members as m', function ($j) {
                $j->on('m.user_id', '=', 'sales_orders.assigned_to')->on('m.joined_on', '<=', 'sales_orders.order_date')
                    ->where(fn ($w) => $w->whereNull('m.left_on')->orWhereColumn('m.left_on', '>=', 'sales_orders.order_date'));
            })
            ->join('sales_teams', 'sales_teams.id', '=', 'm.sales_team_id')
            ->selectRaw('sales_teams.name as label, count(*) as orders, sum(sales_orders.taxable_total) as value')->groupBy('m.sales_team_id', 'sales_teams.name')
            ->orderByDesc('value')->limit(5)->get()->map(fn ($r) => ['label' => $r->label, 'orders' => (int) $r->orders, 'value' => (string) $r->value])->all();

        $open = $this->orders->restrict(SalesOrder::query(), $user)->where('sales_orders.state', '<>', 'cancelled')
            ->when($branch !== null, fn ($q) => $q->where('sales_orders.location_id', $branch))
            ->toBase()->selectRaw("coalesce(sum(sales_orders.approval_status = 'pending'), 0) as waiting")->first();

        return [
            'count' => (int) ($t->n ?? 0), 'net' => (string) ($t->net ?? '0'), 'gross' => (string) ($t->gross ?? '0'),
            'people' => $people, 'teams' => $teams, 'waiting' => (int) ($open->waiting ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function dealSection(User $user, ?int $branch): array
    {
        $open = fn (): Builder => $this->deals->restrict(Deal::query(), $user)->whereNotIn('deals.stage', ['won', 'lost'])
            ->when($branch !== null, fn ($q) => $q->where('deals.location_id', $branch));

        $weighted = 'coalesce(sum(round(deals.expected_value * deals.probability / 100, 2)), 0)';

        $bands = [];

        foreach ([[0, 24, 'Under 25 %'], [25, 49, '25 – 49 %'], [50, 74, '50 – 74 %'], [75, 100, '75 % and over']] as [$low, $high, $label]) {
            $r = $open()->toBase()->whereBetween('deals.probability', [$low, $high])->selectRaw("count(*) as n, coalesce(sum(deals.expected_value), 0) as value, {$weighted} as weighted")->first();
            $bands[] = ['label' => $label, 'count' => (int) ($r->n ?? 0), 'value' => (string) ($r->value ?? '0'), 'weighted' => (string) ($r->weighted ?? '0')];
        }

        $monthEnd = now()->endOfMonth()->toDateString();
        $nextEnd = now()->addMonthNoOverflow()->endOfMonth()->toDateString();
        $closes = [];

        foreach ([
            ['Past the close date', fn ($q) => $q->where('deals.expected_close_date', '<', now()->toDateString())],
            ['Later this month', fn ($q) => $q->whereBetween('deals.expected_close_date', [now()->toDateString(), $monthEnd])],
            ['Next month', fn ($q) => $q->whereBetween('deals.expected_close_date', [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), $nextEnd])],
            ['After that', fn ($q) => $q->where('deals.expected_close_date', '>', $nextEnd)],
            ['No close date', fn ($q) => $q->whereNull('deals.expected_close_date')],
        ] as [$label, $filter]) {
            $q = $open()->toBase();
            $filter($q);
            $r = $q->selectRaw("count(*) as n, coalesce(sum(deals.expected_value), 0) as value, {$weighted} as weighted")->first();
            $closes[] = ['label' => $label, 'count' => (int) ($r->n ?? 0), 'value' => (string) ($r->value ?? '0'), 'weighted' => (string) ($r->weighted ?? '0')];
        }

        $t = $open()->toBase()->selectRaw("count(*) as n, coalesce(sum(deals.expected_value), 0) as value, {$weighted} as weighted")->first();

        return ['count' => (int) ($t->n ?? 0), 'value' => (string) ($t->value ?? '0'), 'weighted' => (string) ($t->weighted ?? '0'), 'bands' => $bands, 'closes' => $closes];
    }

    /** @return array<string, mixed> */
    private function quoteSection(User $user, string $from, string $to, ?int $branch): array
    {
        $t = $this->quotations->restrict(Quotation::query(), $user)
            ->leftJoin('sales_orders', fn ($j) => $j->on('sales_orders.quotation_id', '=', 'quotations.id')->where('sales_orders.state', '<>', 'cancelled')->whereNull('sales_orders.deleted_at'))
            ->whereBetween('quotations.quotation_date', [$from, $to])
            ->when($branch !== null, fn ($q) => $q->where('quotations.location_id', $branch))
            ->toBase()
            ->selectRaw('count(distinct case when quotations.sent_at is not null then quotations.quotation_number end) as sent')
            ->selectRaw('count(distinct case when quotations.accepted_at is not null then quotations.quotation_number end) as accepted')
            ->selectRaw('count(distinct case when sales_orders.id is not null then quotations.quotation_number end) as ordered')
            ->first();

        return ['sent' => (int) ($t->sent ?? 0), 'accepted' => (int) ($t->accepted ?? 0), 'ordered' => (int) ($t->ordered ?? 0)];
    }

    /**
     * Leads made, deals still open and approved sales (net of tax) for each branch the person works in. Only the sections the person may open
     * are filled; the rest are zero rather than hidden, so the columns line up.
     *
     * @return list<array{label: string, leads: int, deals: int, sales: string}>
     */
    private function branchSection(User $user, string $from, string $to, ?int $branch): array
    {
        $names = DB::table('locations')->whereNull('deleted_at')->when($branch !== null, fn ($q) => $q->where('id', $branch))->pluck('name', 'id')->all();
        $allowed = $this->tenant->accessibleLocationIds();
        $names = $allowed === null ? $names : array_intersect_key($names, array_flip($allowed));

        if ($names === []) {
            return [];
        }

        $leads = $user->can('lead.view')
            ? $this->leads->restrict(Lead::query(), $user)->whereBetween('leads.lead_date', [$from, $to])->toBase()->selectRaw('leads.location_id as b, count(*) as n')->groupBy('leads.location_id')->pluck('n', 'b')->all()
            : [];
        $deals = $user->can('deal.view')
            ? $this->deals->restrict(Deal::query(), $user)->whereNotIn('deals.stage', ['won', 'lost'])->toBase()->selectRaw('deals.location_id as b, count(*) as n')->groupBy('deals.location_id')->pluck('n', 'b')->all()
            : [];
        $sales = $user->can('sales_order.view')
            ? $this->orders->restrict(SalesOrder::query(), $user)->where('sales_orders.approval_status', 'approved')->where('sales_orders.state', '<>', 'cancelled')
                ->whereBetween('sales_orders.order_date', [$from, $to])->toBase()->selectRaw('sales_orders.location_id as b, sum(sales_orders.taxable_total) as v')->groupBy('sales_orders.location_id')->pluck('v', 'b')->all()
            : [];

        $out = [];

        foreach ($names as $id => $name) {
            $out[] = ['label' => $name, 'leads' => (int) ($leads[$id] ?? 0), 'deals' => (int) ($deals[$id] ?? 0), 'sales' => (string) ($sales[$id] ?? '0')];
        }

        return $out;
    }
}
