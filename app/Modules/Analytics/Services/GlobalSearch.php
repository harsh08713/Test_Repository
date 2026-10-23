<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Services\NavigationMenu;
use Illuminate\Database\Eloquent\Builder;

/**
 * The header's "search anything": screens, products, customers and suppliers, leads, sales orders, purchase orders and loss incidents. Each kind is searched
 * **only if the person holds its view permission, and only through the same access rule as that kind's own list** (a lead through LeadAccess, a sales order
 * through SalesOrderAccess, the rest through the tenant and branch scopes), so search can never show what a list would not. Two characters at least, five hits a
 * kind, and the term is escaped so `%` and `_` are plain characters.
 */
final class GlobalSearch
{
    public const MIN_LENGTH = 2;

    public const PER_KIND = 5;

    public function __construct(private readonly LeadAccess $leads, private readonly SalesOrderAccess $orders, private readonly NavigationMenu $menu) {}

    /**
     * @return list<array{group: string, hits: list<array{label: string, sub: string|null, url: string}>}>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > 80) {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $groups = [['group' => 'Go to', 'hits' => $this->screens($user, $term)]];

        if ($user->can('item.view')) {
            $groups[] = ['group' => 'Products', 'hits' => $this->hits(Item::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)
                ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like))), fn (Item $i) => [trim($i->code.' · '.$i->name), null, route('masters.items.show', $i)])];
        }

        if ($user->can('ledger.view')) {
            $groups[] = ['group' => 'Customers and suppliers', 'hits' => $this->hits(Ledger::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)),
                fn (Ledger $l) => [$l->name, ucfirst($l->ledger_type->value).' · '.$l->code, route('masters.ledgers.show', $l)])];
        }

        if ($user->can('lead.view')) {
            $groups[] = ['group' => 'Leads', 'hits' => $this->hits($this->leads->restrict(Lead::query(), $user)->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('lead_number', 'like', $like)->orWhere('mobile', 'like', $like)),
                fn (Lead $l) => [$l->name, $l->lead_number, route('crm.leads.show', $l)])];
        }

        if ($user->can('sales_order.view')) {
            $groups[] = ['group' => 'Sales orders', 'hits' => $this->hits($this->orders->restrict(SalesOrder::query(), $user)->where(fn (Builder $q) => $q->where('so_number', 'like', $like)->orWhere('party_name', 'like', $like)),
                fn (SalesOrder $o) => [$o->so_number, $o->party_name, route('sales.orders.show', $o)])];
        }

        if ($user->can('purchase_order.view')) {
            $groups[] = ['group' => 'Purchase orders', 'hits' => $this->hits(PurchaseOrder::query()->where(fn (Builder $q) => $q->where('po_number', 'like', $like)->orWhere('supplier_name', 'like', $like)),
                fn (PurchaseOrder $o) => [$o->po_number, $o->supplier_name, route('procurement.orders.show', $o)])];
        }

        if ($user->can('loss_incident.view')) {
            $groups[] = ['group' => 'Loss incidents', 'hits' => $this->hits(LossIncident::query()->where(fn (Builder $q) => $q->where('incident_number', 'like', $like)->orWhere('title', 'like', $like)),
                fn (LossIncident $i) => [$i->title, $i->incident_number, route('loss.incidents.show', $i)])];
        }

        return array_values(array_filter($groups, fn (array $g) => $g['hits'] !== []));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  callable(T): array{0: string, 1: string|null, 2: string}  $present
     * @return list<array{label: string, sub: string|null, url: string}>
     */
    private function hits(Builder $query, callable $present): array
    {
        return $query->orderByDesc('id')->limit(self::PER_KIND)->get()->map(function ($row) use ($present) {
            [$label, $sub, $url] = $present($row);

            return ['label' => $label, 'sub' => $sub, 'url' => $url];
        })->all();
    }

    /**
     * The screens the person can open whose name matches: the sidebar's own list, so search and menu always agree.
     *
     * @return list<array{label: string, sub: string|null, url: string}>
     */
    private function screens(User $user, string $term): array
    {
        $hits = [];

        foreach ($this->menu->for($user) as $section) {
            foreach ($section['items'] as $item) {
                if ($item['url'] === null || mb_stripos($item['label'], $term) === false) {
                    continue;
                }

                $hits[] = ['label' => $item['label'], 'sub' => $section['section'], 'url' => $item['url']];
            }
        }

        return array_slice($hits, 0, self::PER_KIND);
    }
}
