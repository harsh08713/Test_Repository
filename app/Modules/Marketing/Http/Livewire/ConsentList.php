<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\ContactNormalizer;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What each address has agreed to or refused, channel by channel: the newest answer for each, and the whole history of one on request. Anyone with
 * the right can record an answer (an opt-out someone asked for on the phone, or an opt-in on a paper form); it is added to the history, never
 * overwriting one, and says who recorded it.
 */
#[Title('Consent and opt-outs')]
class ConsentList extends Component
{
    use WithPagination;

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** @var array<string, string> */
    public array $f = ['channel' => 'email', 'address' => '', 'status' => 'opted_out', 'note' => ''];

    /** The address whose history is open: "channel|address". */
    public string $open = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ContactConsent::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['channel', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function record(ConsentService $consent): void
    {
        Gate::authorize('create', ContactConsent::class);
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->resetErrorBag();

        $channel = Channel::tryFrom($this->f['channel']);

        if ($channel === null) {
            $this->addError('channel', 'Choose a channel.');

            return;
        }

        try {
            $consent->record($channel, $this->f['address'], $this->f['status'], 'manual', $this->f['note'] ?: null, $user);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key === 'address' ? 'address' : 'status', $messages[0]);
            }

            return;
        }

        $this->f['address'] = '';
        $this->f['note'] = '';
        session()->flash('status', 'Recorded. It takes effect for the next message.');
    }

    public function toggleHistory(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
    }

    public function render(): View
    {
        $latest = DB::table('contact_consents')->selectRaw('max(id)')->where('company_id', app(TenantContext::class)->activeCompanyId())->groupBy('channel', 'address');

        $rows = ContactConsent::query()->with('recorder:id,name')->whereIn('id', $latest)
            ->when(Channel::tryFrom($this->channel) !== null, fn (Builder $q) => $q->where('channel', $this->channel))
            ->when(in_array($this->status, ['opted_in', 'opted_out'], true), fn (Builder $q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('address', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], strtolower(trim($this->search))).'%'))
            ->orderByDesc('id')->paginate(20);

        $history = [];

        if ($this->open !== '' && str_contains($this->open, '|')) {
            [$ch, $address] = explode('|', $this->open, 2);
            $history = Channel::tryFrom($ch) === null ? [] : ContactConsent::query()->with('recorder:id,name')->where('channel', $ch)->where('address', ContactNormalizer::address(Channel::from($ch), $address) ?? '')->orderByDesc('id')->get()->all();
        }

        return view('marketing.consent', [
            'rows' => $rows, 'history' => $history, 'channels' => Channel::options(), 'canRecord' => Gate::allows('create', ContactConsent::class),
        ]);
    }
}
