<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Interview;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The interviews the signed-in person is to take, and the form for their feedback. Anyone with a login may be an interviewer; only they write the feedback. */
#[Title('My interviews')]
class MyInterviews extends Component
{
    use ActsAsStaff;

    #[Url(as: 'show')]
    public string $show = 'planned';

    public int $giving = 0;

    /** @var array<string, string> */
    public array $form = ['rating' => '', 'recommendation' => '', 'feedback' => ''];

    public function startFeedback(int $id): void
    {
        $this->resetErrorBag();
        $this->giving = $this->giving === $id ? 0 : $id;
        $this->form = ['rating' => '', 'recommendation' => '', 'feedback' => ''];
    }

    public function sendFeedback(RecruitmentService $service): void
    {
        $interview = Interview::query()->where('interviewer_id', $this->actor()->id)->findOrFail($this->giving);

        if ($this->attempt(fn () => $service->recordFeedback($interview, (int) $this->form['rating'], $this->form['recommendation'], $this->form['feedback'], $this->actor()), 'Thank you: your feedback is recorded.')) {
            $this->giving = 0;
        }
    }

    public function render(): View
    {
        $rows = Interview::query()->where('interviewer_id', $this->actor()->id)->with(['application.candidate:id,name,mobile', 'application.opening:id,title'])
            ->when($this->show === 'planned', fn ($q) => $q->where('state', 'scheduled'))->when($this->show === 'done', fn ($q) => $q->where('state', 'done'))
            ->orderBy('scheduled_at')->limit(100)->get();

        return view('hr.my-interviews', ['rows' => $rows, 'modes' => Interview::MODES]);
    }
}
