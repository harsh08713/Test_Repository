<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Services\TemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The message templates: what each says, for which channel and purpose, whether it is approved, and a switch to take one out of use. */
#[Title('Message templates')]
class TemplateList extends Component
{
    use WithPagination;

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', MessageTemplate::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['channel', 'approval', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $id, TemplateService $service): void
    {
        $template = MessageTemplate::query()->findOrFail($id);
        Gate::authorize('switch', $template);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $service->setActive($template, $template->status !== 'active', $user);
        } catch (FieldRuleException $e) {
            $this->addError('list', $e->getMessage());

            return;
        }

        session()->flash('status', $template->status === 'active' ? 'Switched on.' : 'Switched off: it will not be sent.');
    }

    public function render(): View
    {
        $templates = MessageTemplate::query()
            ->when(Channel::tryFrom($this->channel) !== null, fn (Builder $q) => $q->where('channel', $this->channel))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('approval_status', $this->approval))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%'))
            ->orderBy('name')->orderBy('id')->paginate(20);

        return view('marketing.templates.list', ['templates' => $templates, 'channels' => Channel::options(), 'canCreate' => Gate::allows('create', MessageTemplate::class)]);
    }
}
