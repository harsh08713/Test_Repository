<?php

namespace App\Modules\Industry\Http\Livewire;

use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Services\IndustryTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One template as the active company sees it: KPIs (with its own thresholds), loss categories, fields, workflow, reports. */
#[Title('Industry template')]
class IndustryShow extends Component
{
    public IndustryTemplate $industry;

    /** @var array<string, array{green: string, amber: string, red: string}> KPI code => the numbers being edited (no dots in codes) */
    public array $thresholds = [];

    public function mount(IndustryTemplateService $service): void
    {
        Gate::authorize('view', $this->industry);
        $this->fillThresholds($service);
    }

    public function saveThresholds(string $code, IndustryTemplateService $service): void
    {
        $this->resetErrorBag();
        $values = $this->thresholds[$code] ?? [];

        try {
            $service->setKpiThresholds($this->industry, $code, $values['green'] ?? null, $values['amber'] ?? null, $values['red'] ?? null, Auth::user());
        } catch (ValidationException $e) {
            $this->addError("kpi-{$code}", (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->fillThresholds($service);
        session()->flash('status', 'Thresholds saved.');
    }

    public function moveToCurrentVersion(IndustryTemplateService $service): void
    {
        try {
            $service->moveToCurrentVersion($this->industry, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('version', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->fillThresholds($service);
        session()->flash('status', 'Moved to the current version.');
    }

    public function render(IndustryTemplateService $service): View
    {
        $config = $service->resolve($this->industry);

        return view('industry.show', [
            'config' => $config,
            'overrides' => $service->thresholdOverrides($this->industry),
            'canConfigure' => Gate::allows('configure', IndustryTemplate::class),
            'behind' => $config['enabled'] && $config['version'] < $this->industry->version,
        ]);
    }

    private function fillThresholds(IndustryTemplateService $service): void
    {
        $this->thresholds = [];

        foreach ($service->resolve($this->industry)['kpis'] as $kpi) {
            $this->thresholds[$kpi->code] = [
                'green' => $this->plain($kpi->threshold_green), 'amber' => $this->plain($kpi->threshold_amber), 'red' => $this->plain($kpi->threshold_red),
            ];
        }
    }

    private function plain(?string $number): string
    {
        return $number === null ? '' : (rtrim(rtrim($number, '0'), '.') ?: '0');
    }
}
