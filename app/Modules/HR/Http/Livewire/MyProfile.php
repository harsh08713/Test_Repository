<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Services\EmployeeAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "My profile": opens the person's own employee file, or says there is none linked to their login. */
#[Title('My profile')]
class MyProfile extends Component
{
    public function mount(EmployeeAccess $access): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $own = $access->own($user);

        if ($own !== null) {
            $this->redirectRoute('hr.employees.show', $own, navigate: false);
        }
    }

    public function render(): View
    {
        return view('hr.my-profile');
    }
}
