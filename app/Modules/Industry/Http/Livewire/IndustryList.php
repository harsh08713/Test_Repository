<?php

namespace App\Modules\Industry\Http\Livewire;

use App\Modules\Industry\Models\CompanyIndustryTemplate;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Services\IndustryTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The 15 industry templates, with the ones the active company has switched on. */
#[Title('Industries')]
class IndustryList extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', IndustryTemplate::class);
    }

    public function enableTemplate(int $id, IndustryTemplateService $service): void
    {
        $this->change($id, fn (IndustryTemplate $t) => $service->enable($t, Auth::user()), 'Enabled');
    }

    public function disableTemplate(int $id, IndustryTemplateService $service): void
    {
        $this->change($id, fn (IndustryTemplate $t) => $service->disable($t, Auth::user()), 'Disabled');
    }

    public function render(): View
    {
        $pinned = CompanyIndustryTemplate::query()->pluck('version_pinned', 'industry_template_id');

        return view('industry.list', [
            'templates' => IndustryTemplate::query()->withCount(['kpis as kpi_count' => fn ($q) => $q->whereColumn('kpi_definitions.version', 'industry_templates.version'),
                'lossCategories as category_count' => fn ($q) => $q->whereColumn('loss_categories.version', 'industry_templates.version')])
                ->where('status', 'active')->orderBy('name')->get(),
            'pinned' => $pinned,
            'canConfigure' => Gate::allows('configure', IndustryTemplate::class),
        ]);
    }

    private function change(int $id, callable $action, string $verb): void
    {
        $template = IndustryTemplate::query()->findOrFail($id);

        try {
            $action($template);
        } catch (ValidationException $e) {
            $this->addError('list', (string) collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('status', "{$verb} {$template->name}.");
    }
}
