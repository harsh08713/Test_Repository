<?php

namespace App\Modules\CRM\Http\Livewire\Followups;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Follow-ups on a calendar — day, week and month — for one person, a team, or everyone the signed-in person may see.
 *
 * The calendar itself (FullCalendar) runs in the browser; this component is what it talks to. It asks for the entries of
 * the window it is showing (`events`) and, when an entry is dragged to another time, asks for it to be moved (`move`).
 * Both are ordinary server calls: the window is bounded, the entries come through FollowupAgenda (never wider than the
 * person's reach), and a move is authorised against the follow-up and made by FollowupService like any other.
 *
 * Times are the application's wall-clock times throughout, as everywhere else in the system.
 */
#[Title('Follow-up calendar')]
class FollowupCalendar extends Component
{
    use ScopesToPeople;

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'team')]
    public string $team = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Followup::class);
    }

    /** A different person or team: tell the calendar to ask again. */
    public function updated(string $property): void
    {
        if (in_array($property, ['person', 'team'], true)) {
            $this->dispatch('followups-filter-changed');
        }
    }

    /**
     * The entries between two moments ("2026-10-12T00:00:00"), as FullCalendar event objects.
     *
     * @return list<array<string, mixed>>
     */
    #[Renderless]
    public function events(string $start, string $end): array
    {
        Gate::authorize('viewAny', Followup::class);

        $from = $this->moment($start);
        $to = $this->moment($end);

        if ($from === null || $to === null) {
            return [];
        }

        $actor = $this->actor();
        $agenda = app(FollowupAgenda::class);
        $rows = $agenda->between($actor, $from, $to, ctype_digit($this->person) ? (int) $this->person : null, ctype_digit($this->team) ? (int) $this->team : null);

        $leads = $rows->map(fn (Followup $f) => $f->subject)->filter(fn ($subject) => $subject instanceof Lead);
        $openable = $actor->can('lead.view') ? app(LeadAccess::class)->openable($actor, $leads) : [];
        $canMove = $actor->can('followup.edit');

        return $rows->map(function (Followup $f) use ($agenda, $openable, $canMove) {
            $lead = $f->subject instanceof Lead ? $f->subject : null;
            $state = $f->isPending() ? ($f->isOverdue() ? 'overdue' : 'planned') : 'done';

            return [
                'id' => (string) $f->id,
                'title' => $f->type->label().' · '.$agenda->subjectLabel($f),
                'start' => $f->scheduled_at->format('Y-m-d\TH:i:s'),
                'end' => $f->scheduled_at->copy()->addMinutes(30)->format('Y-m-d\TH:i:s'),
                'url' => $lead !== null && isset($openable[$lead->id]) ? route('crm.leads.show', $lead->id, false).'#follow-ups' : null,
                // only what is still planned can be dragged; the server decides again when it is dropped
                'editable' => $canMove && $f->isPending(),
                'extendedProps' => [
                    'state' => $state,
                    'person' => $f->assignee?->name,
                    'outcome' => $f->outcome?->name,
                    'summary' => trim(implode(' · ', array_filter([
                        match ($state) {
                            'overdue' => 'Overdue', 'planned' => 'Planned', default => 'Done'
                        },
                        $f->outcome?->name, $f->assignee?->name, $lead?->lead_number, $lead?->mobile,
                    ]))),
                ],
            ];
        })->values()->all();
    }

    /**
     * Move a planned follow-up to another time (an entry was dragged). Never throws for a refusal: the calendar puts the
     * entry back and shows the message.
     *
     * @return array{ok: bool, message: string}
     */
    #[Renderless]
    public function move(int $id, string $start): array
    {
        // the tenant scopes and the agenda's reach apply: somebody else's follow-up is simply not found
        $followup = app(FollowupAgenda::class)->query($this->actor())->find($id);

        if ($followup === null || ! Gate::allows('update', $followup)) {
            return ['ok' => false, 'message' => 'That follow-up cannot be moved.'];
        }

        try {
            $moved = app(FollowupService::class)->reschedule($followup, $start, $this->actor());
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) $e->validator->errors()->first()];
        } catch (FieldRuleException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Moved to '.$moved->scheduled_at->format('D, j M Y, H:i').'. The reminder will be sent again.'];
    }

    /** "2026-10-12T00:00:00" as a moment in the application's time zone, or null when it is not one. */
    private function moment(string $value): ?Carbon
    {
        $value = substr(trim($value), 0, 19);

        if (! Carbon::hasFormat($value, 'Y-m-d\TH:i:s')) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d\TH:i:s', $value);
    }

    public function render(FollowupAgenda $agenda): View
    {
        return view('crm.followups.calendar', [
            'people' => $this->visiblePeople(),
            'teams' => $agenda->teams($this->actor()),
            // minutes the application's clock is ahead of UTC, so the browser can mark "now" on the server's clock
            'offsetMinutes' => (int) (now()->getOffset() / 60),
            'canMove' => $this->actor()->can('followup.edit'),
        ]);
    }
}
