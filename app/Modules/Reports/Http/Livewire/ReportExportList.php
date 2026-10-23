<?php

namespace App\Modules\Reports\Http\Livewire;

use App\Models\User;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** The exports the signed-in person asked for — nobody else’s — and their files while they last. */
#[Title('My exports')]
class ReportExportList extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('report.export'), 403);
    }

    public function render(ReportCatalog $catalog): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $exports = ReportExport::query()->where('user_id', $user->getKey())->orderByDesc('id')->paginate(15);

        return view('reports.exports', [
            'exports' => $exports,
            'titles' => collect($catalog->all())->map(fn ($r) => $r->title())->all(),
            'busy' => $exports->getCollection()->contains(fn (ReportExport $e) => in_array($e->status, [ReportExport::QUEUED, ReportExport::RUNNING], true)),
        ]);
    }
}
