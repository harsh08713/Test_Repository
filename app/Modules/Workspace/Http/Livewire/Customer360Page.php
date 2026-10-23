<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Models\User;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Workspace\Services\Customer360;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One page for one customer: the master record, contacts, credit position, and the leads, deals, quotations, orders, invoices, follow-ups and tasks
 * the person may see for them. Opened by anyone who may open the customer's ledger; each section by the right to its own records.
 */
#[Title('Customer 360')]
class Customer360Page extends Component
{
    #[Locked]
    public int $ledgerId = 0;

    public function mount(int $customer): void
    {
        $ledger = Ledger::query()->findOrFail($customer);

        abort_unless($ledger->ledger_type === LedgerType::Customer, 404);
        Gate::authorize('view', $ledger);

        $this->ledgerId = $ledger->getKey();
    }

    public function render(Customer360 $profile): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $ledger = Ledger::query()->findOrFail($this->ledgerId);
        Gate::authorize('view', $ledger);

        return view('workspace.customer360', [
            'ledger' => $ledger,
            'data' => $profile->build($ledger, $user),
            'canTask' => $user->can('task.create'),
        ]);
    }
}
