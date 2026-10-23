<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Services\ResumeService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** The people on file: searched by name, mobile or e-mail, with how many openings each applied to; a panel to add or change one. */
#[Title('Candidates')]
class CandidateList extends Component
{
    use ActsAsStaff, WithFileUploads, WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $formOpen = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $f = [];

    public ?TemporaryUploadedFile $resume = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Candidate::class);
    }

    public function updating(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        Gate::authorize('create', Candidate::class);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->f = ['name' => '', 'mobile' => '', 'email' => '', 'source' => 'other', 'current_employer' => '', 'experience_years' => '', 'notes' => ''];
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $c = Candidate::query()->findOrFail($id);
        Gate::authorize('update', $c);
        $this->resetErrorBag();
        $this->editingId = $c->id;
        $this->f = ['name' => $c->name, 'mobile' => (string) $c->mobile, 'email' => (string) $c->email, 'source' => $c->source, 'current_employer' => (string) $c->current_employer,
            'experience_years' => (string) $c->experience_years, 'notes' => (string) $c->notes];
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->resume = null;
        $this->resetErrorBag();
    }

    public function save(RecruitmentService $service): void
    {
        $editing = $this->editingId === null ? null : Candidate::query()->findOrFail($this->editingId);

        if ($this->attempt(fn () => $service->saveCandidate($this->f, $this->actor(), $editing), $editing === null ? 'Candidate added.' : 'Candidate saved.')) {
            $this->formOpen = false;
        }
    }

    public function saveResume(ResumeService $service): void
    {
        $candidate = Candidate::query()->findOrFail((int) $this->editingId);

        if ($this->resume === null) {
            $this->addError('resume', 'Choose a file first.');

            return;
        }

        if ($this->attempt(fn () => $service->attach($candidate, $this->resume, $this->actor()), 'Résumé saved.')) {
            $this->resume = null;
        }
    }

    public function dropResume(ResumeService $service): void
    {
        $candidate = Candidate::query()->findOrFail((int) $this->editingId);
        $this->attempt(fn () => $service->remove($candidate, $this->actor()), 'Résumé removed.');
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('hr.candidates', [
            'candidates' => Candidate::query()->withCount('applications')->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('email', 'like', $like)))
                ->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE),
            'current' => $this->editingId === null ? null : Candidate::query()->find($this->editingId), 'sources' => Candidate::SOURCES, 'canCreate' => Gate::allows('create', Candidate::class),
        ]);
    }
}
