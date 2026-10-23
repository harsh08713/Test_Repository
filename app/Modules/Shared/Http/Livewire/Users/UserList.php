<?php

namespace App\Modules\Shared\Http\Livewire\Users;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class UserList extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleActive(int $userId): void
    {
        $target = User::query()->managedBy(Auth::user())->findOrFail($userId);

        Gate::authorize('update', $target);
        abort_if($target->is($this->actor()), 422, 'You cannot deactivate your own account.');

        $target->update(['is_active' => ! $target->is_active]);
    }

    private function actor(): User
    {
        return Auth::user();
    }

    public function render(): View
    {
        $users = User::query()
            ->managedBy(Auth::user())
            ->with(['roles:id,name'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('is_active', $this->status === 'active'))
            ->orderBy('name')
            ->paginate(15);

        return view('shared.users.index', ['users' => $users]);
    }
}
