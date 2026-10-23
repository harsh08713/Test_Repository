<?php

namespace App\Modules\Analytics\Http\Livewire;

use App\Models\User;
use App\Modules\Analytics\Services\GlobalSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** The header's “search anything” box. Results come from GlobalSearch, which only ever returns what the person may open. */
class SearchBox extends Component
{
    public string $term = '';

    public function render(GlobalSearch $search): View
    {
        $user = Auth::user();
        $groups = $user instanceof User ? $search->search($user, $this->term) : [];

        return view('analytics.search-box', ['groups' => $groups, 'searched' => mb_strlen(trim($this->term)) >= GlobalSearch::MIN_LENGTH]);
    }
}
