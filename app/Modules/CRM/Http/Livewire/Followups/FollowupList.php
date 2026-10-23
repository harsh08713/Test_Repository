<?php

namespace App\Modules\CRM\Http\Livewire\Followups;

use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\LeadAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The working list of follow-ups: what is overdue, what is due today and what is coming, for the signed-in person and —
 * for a manager — the people on their teams. Acting on one happens on its lead's page, where the lead is in view.
 */
#[Title('Follow-ups')]
class FollowupList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 25;

    /** 'open' (everything planned), 'overdue', 'today', 'week', 'done', 'cancelled' or '' (all). */
    #[Url(as: 'show')]
    public string $show = 'open';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'team')]
    public string $team = '';

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Followup::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['show', 'person', 'team', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setShow(string $show): void
    {
        $this->show = in_array($show, ['open', 'overdue', 'today', 'week', 'done', 'cancelled', ''], true) ? $show : 'open';
        $this->resetPage();
    }

    /**
     * What the person may see, narrowed by person, team, type and search — but not yet by the "show" tab.
     *
     * @return Builder<Followup>
     */
    private function base(FollowupAgenda $agenda): Builder
    {
        $term = trim($this->search);

        return $agenda->query($this->actor(), ctype_digit($this->person) ? (int) $this->person : null, ctype_digit($this->team) ? (int) $this->team : null)
            ->when(FollowupType::tryFrom($this->type) !== null, fn (Builder $q) => $q->where('type', $this->type))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $term) ?? '';

                $q->whereHasMorph('subject', [Lead::class], function (Builder $lead) use ($like, $digits) {
                    $lead->where(function (Builder $w) use ($like, $digits) {
                        $w->where('name', 'like', $like)->orWhere('lead_number', 'like', $like)->orWhere('contact_person', 'like', $like);

                        if (strlen($digits) >= 4) {
                            $w->orWhere('mobile', 'like', '%'.substr($digits, -10).'%');
                        }
                    });
                });
            });
    }

    /**
     * One tab's slice of the base. Each tab is a slice of the same base, so the number on a tab is the number of rows
     * behind it.
     *
     * @param  Builder<Followup>  $query
     * @return Builder<Followup>
     */
    private function slice(Builder $query, string $show): Builder
    {
        $now = now();
        $pending = FollowupStatus::Pending->value;

        return match ($show) {
            'open' => $query->where('status', $pending),
            'overdue' => $query->where('status', $pending)->where('scheduled_at', '<', $now),
            'today' => $query->where('status', $pending)->whereBetween('scheduled_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
            'week' => $query->where('status', $pending)->whereBetween('scheduled_at', [$now->copy()->startOfDay(), $now->copy()->addDays(7)->endOfDay()]),
            'done' => $query->where('status', FollowupStatus::Done->value),
            'cancelled' => $query->where('status', FollowupStatus::Cancelled->value),
            default => $query,
        };
    }

    public function render(FollowupAgenda $agenda, LeadAccess $access): View
    {
        $actor = $this->actor();

        $query = $this->slice($this->base($agenda), $this->show)->with(['subject', 'assignee:id,name', 'outcome:id,name']);

        // planned work reads forwards from the most overdue; a record of what happened reads backwards from the latest
        $rows = (in_array($this->show, ['done', 'cancelled', ''], true)
            ? $query->orderByDesc('scheduled_at')->orderByDesc('id')
            : $query->orderBy('scheduled_at')->orderBy('id'))->paginate(self::PER_PAGE);

        $leads = $rows->getCollection()->map(fn (Followup $f) => $f->subject)->filter(fn ($subject) => $subject instanceof Lead);

        $tabs = [];
        foreach ([['open', 'Planned'], ['overdue', 'Overdue'], ['today', 'Today'], ['week', 'Next 7 days']] as [$key, $label]) {
            $tabs[] = [$key, $label, $this->slice($this->base($agenda), $key)->count()];
        }
        $tabs = [...$tabs, ['done', 'Done', null], ['cancelled', 'Called off', null], ['', 'All', null]];

        return view('crm.followups.list', [
            'rows' => $rows,
            'tabs' => $tabs,
            'people' => $this->visiblePeople(),
            'teams' => $agenda->teams($actor),
            'types' => FollowupType::cases(),
            'openable' => $actor->can('lead.view') ? $access->openable($actor, $leads) : [],
        ]);
    }
}
