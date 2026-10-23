<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CommunicationAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every message the system sent or refused to send, to the people the signed-in person may see, with what happened to it. */
#[Title('Message log')]
class CommunicationList extends Component
{
    use WithPagination;

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'campaign')]
    public string $campaign = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    public int $reading = 0;

    public function mount(): void
    {
        Gate::authorize('viewAny', Communication::class);
    }

    public function updating(string $property): void
    {
        in_array($property, ['channel', 'status', 'campaign', 'from', 'to', 'search'], true) && $this->resetPage();
    }

    public function read(int $id): void
    {
        $this->reading = $this->reading === $id ? 0 : $id;
    }

    public function render(CommunicationAccess $access): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $valid = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && Carbon::hasFormat($d, 'Y-m-d');

        $messages = $access->restrict(Communication::query(), $user)->with('campaign:id,name,code')
            ->when(Channel::tryFrom($this->channel) !== null, fn (Builder $q) => $q->where('communications.channel', $this->channel))
            ->when(in_array($this->status, ['queued', 'sending', 'sent', 'failed', 'suppressed', 'not_connected', 'cancelled'], true), fn (Builder $q) => $q->where('communications.status', $this->status))
            ->when(ctype_digit($this->campaign), fn (Builder $q) => $q->where('communications.campaign_id', (int) $this->campaign))
            ->when($valid($this->from), fn (Builder $q) => $q->where('communications.queued_at', '>=', $this->from.' 00:00:00'))
            ->when($valid($this->to), fn (Builder $q) => $q->where('communications.queued_at', '<=', $this->to.' 23:59:59'))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('communications.to_name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%')->orWhere('communications.to_address', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], strtolower(trim($this->search))).'%')))
            ->orderByDesc('communications.id')->paginate(20);

        return view('marketing.messages', [
            'messages' => $messages, 'channels' => Channel::options(), 'campaigns' => Campaign::query()->orderByDesc('id')->limit(100)->pluck('name', 'id')->all(),
        ]);
    }
}
