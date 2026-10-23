<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\LedgerContact;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Who a campaign writes to. The audience is a **definition** (filters), turned into people twice: as a preview while the campaign is being made, and
 * again — frozen into members — when it is launched.
 *
 *  - It is worked out **with the reach of the campaign's owner** (`LeadAccess::restrict()` for leads), never wider: a campaign cannot write to people its
 *    owner may not see. Customers are the company's approved, active customer ledgers.
 *  - Consent is not part of the definition: it is looked at for each message (`CommunicationService`). The preview *reports* how many would be held
 *    back for lack of a contact detail or of consent, so nothing is a surprise.
 *
 * Definition (leads): `stages[]`, `sources[]`, `branch`, `owners[]`, `from`, `to` (lead date), `text` (in name or requirement).
 * Definition (customers): `kinds[]` (b2b, b2c), `text` (in name), `ordered_within_days`, `not_ordered_within_days`.
 */
final class AudienceBuilder
{
    /** The most people one campaign can have. */
    public const MAX = 20000;

    public function __construct(private readonly LeadAccess $leads, private readonly ConsentService $consent) {}

    /**
     * Check and tidy a definition typed on a screen.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function clean(string $kind, array $raw): array
    {
        $ints = fn (mixed $v): array => array_values(array_unique(array_map('intval', array_filter((array) $v, fn ($x) => is_scalar($x) && ctype_digit((string) $x)))));
        $text = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, 100) : null;
        $day = fn (mixed $v): ?string => is_scalar($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1 ? (string) $v : null;
        $days = fn (mixed $v): ?int => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0 && (int) $v <= 3650 ? (int) $v : null;

        if ($kind === 'leads') {
            $stages = array_values(array_intersect(array_map('strval', (array) ($raw['stages'] ?? [])), array_map(fn (LeadStage $s) => $s->value, LeadStage::cases())));

            return array_filter([
                'stages' => $stages, 'sources' => $ints($raw['sources'] ?? []), 'owners' => $ints($raw['owners'] ?? []),
                'branch' => ($b = $ints([$raw['branch'] ?? null])) === [] ? null : $b[0],
                'from' => $day($raw['from'] ?? null), 'to' => $day($raw['to'] ?? null), 'text' => $text($raw['text'] ?? null),
            ], fn ($v) => $v !== null && $v !== []);
        }

        if ($kind === 'customers') {
            return array_filter([
                'kinds' => array_values(array_intersect(array_map('strval', (array) ($raw['kinds'] ?? [])), ['b2b', 'b2c'])), 'text' => $text($raw['text'] ?? null),
                'ordered_within_days' => $days($raw['ordered_within_days'] ?? null), 'not_ordered_within_days' => $days($raw['not_ordered_within_days'] ?? null),
            ], fn ($v) => $v !== null && $v !== []);
        }

        throw ValidationException::withMessages(['audience_kind' => 'Choose leads or customers.']);
    }

    /**
     * The people the definition matches, for $owner.
     *
     * @param  array<string, mixed>  $audience
     * @return Builder<*>
     */
    public function query(string $kind, array $audience, User $owner): Builder
    {
        return $kind === 'leads' ? $this->leadQuery($audience, $owner) : $this->customerQuery($audience);
    }

    /**
     * What the audience looks like for a first message on $channel: how many, how many have a usable address, how many may be written to.
     *
     * @param  array<string, mixed>  $audience
     * @return array{total: int, with_address: int, reachable: int, no_address: int, held_back: int, sample: list<string>, too_many: bool}
     */
    public function preview(string $kind, array $audience, User $owner, Channel $channel, string $purpose): array
    {
        $out = ['total' => 0, 'with_address' => 0, 'reachable' => 0, 'no_address' => 0, 'held_back' => 0, 'sample' => [], 'too_many' => false];

        foreach ($this->people($kind, $audience, $owner, self::MAX + 1) as $person) {
            $out['total']++;
            count($out['sample']) < 5 && $out['sample'][] = $person['name'];
            $address = ContactNormalizer::address($channel, $channel === Channel::Email ? $person['email'] : $person['mobile']);

            if ($address === null) {
                $out['no_address']++;

                continue;
            }

            $out['with_address']++;
            $this->consent->check($channel, $address, $purpose)['ok'] ? $out['reachable']++ : $out['held_back']++;
        }

        $out['too_many'] = $out['total'] > self::MAX;

        return $out;
    }

    /**
     * The people, a few at a time, as plain arrays.
     *
     * @param  array<string, mixed>  $audience
     * @return \Generator<int, array{subject_type: string, subject_id: int, name: string, email: string|null, mobile: string|null, lead_id: int|null}>
     */
    public function people(string $kind, array $audience, User $owner, int $limit = self::MAX + 1): \Generator
    {
        $query = $this->query($kind, $audience, $owner);
        $sent = 0;

        foreach ($query->lazyById(500, $query->getModel()->getTable().'.id', 'id') as $row) {
            if ($sent++ >= $limit) {
                return;
            }

            if ($row instanceof Lead) {
                yield ['subject_type' => 'lead', 'subject_id' => (int) $row->getKey(), 'name' => (string) $row->name, 'email' => $row->email, 'mobile' => $row->mobile, 'lead_id' => (int) $row->getKey()];

                continue;
            }

            /** @var Ledger $row */
            $contact = LedgerContact::query()->where('ledger_id', $row->getKey())->orderByDesc('is_primary')->orderBy('id')->first(['email', 'mobile', 'phone']);

            yield [
                'subject_type' => 'ledger', 'subject_id' => (int) $row->getKey(), 'name' => (string) $row->name,
                'email' => $row->email ?: $contact?->email, 'mobile' => $contact?->mobile ?: ($contact?->phone ?: $row->phone), 'lead_id' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @return Builder<Lead>
     */
    private function leadQuery(array $a, User $owner): Builder
    {
        $like = fn (string $t) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $t).'%';
        $stages = $a['stages'] ?? [];

        return $this->leads->restrict(Lead::query(), $owner)
            ->when($stages !== [], fn (Builder $q) => $q->whereIn('leads.stage', $stages), fn (Builder $q) => $q->whereNotIn('leads.stage', ['won', 'lost']))
            ->when(! empty($a['sources']), fn (Builder $q) => $q->whereIn('leads.lead_source_id', $a['sources']))
            ->when(! empty($a['owners']), fn (Builder $q) => $q->whereIn('leads.assigned_to', $a['owners']))
            ->when(! empty($a['branch']), fn (Builder $q) => $q->where('leads.location_id', $a['branch']))
            ->when(! empty($a['from']), fn (Builder $q) => $q->where('leads.lead_date', '>=', $a['from']))
            ->when(! empty($a['to']), fn (Builder $q) => $q->where('leads.lead_date', '<=', $a['to']))
            ->when(! empty($a['text']), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('leads.name', 'like', $like($a['text']))->orWhere('leads.requirement', 'like', $like($a['text']))));
    }

    /**
     * @param  array<string, mixed>  $a
     * @return Builder<Ledger>
     */
    private function customerQuery(array $a): Builder
    {
        $like = fn (string $t) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $t).'%';
        $ordered = fn (int $days) => SalesOrder::query()->select('customer_ledger_id')->whereColumn('customer_ledger_id', 'ledgers.id')->where('state', '<>', 'cancelled')
            ->where('approval_status', 'approved')->where('order_date', '>=', now()->subDays($days)->toDateString());

        return Ledger::query()->usable()->ofType(LedgerType::Customer)
            ->when(! empty($a['kinds']), fn (Builder $q) => $q->whereIn('ledgers.customer_kind', $a['kinds']))
            ->when(! empty($a['text']), fn (Builder $q) => $q->where('ledgers.name', 'like', $like($a['text'])))
            ->when(! empty($a['ordered_within_days']), fn (Builder $q) => $q->whereExists($ordered((int) $a['ordered_within_days'])))
            ->when(! empty($a['not_ordered_within_days']), fn (Builder $q) => $q->whereNotExists($ordered((int) $a['not_ordered_within_days'])));
    }
}
