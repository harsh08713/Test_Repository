<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Services\TemplateRenderer;
use App\Modules\Marketing\Services\TemplateService;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Make or change a message template, with a preview filled in with made-up details. An approved template is read-only until it is re-opened. */
#[Title('Message template')]
class TemplateForm extends Component
{
    /** @var array<string, string> */
    public array $t = ['name' => '', 'channel' => 'email', 'purpose' => 'marketing', 'subject' => '', 'body' => '', 'provider_template_id' => ''];

    #[Locked]
    public int $templateId = 0;

    public bool $reopening = false;

    public string $reason = '';

    public function mount(?int $editing = null): void
    {
        if ($editing === null) {
            Gate::authorize('create', MessageTemplate::class);
            $this->t['body'] = "Dear {{name}},\n\n\n\nStop receiving these messages: {{unsubscribe_url}}";

            return;
        }

        $template = MessageTemplate::query()->findOrFail($editing);
        Gate::authorize('view', $template);

        $this->templateId = $template->getKey();
        $this->t = [
            'name' => $template->name, 'channel' => $template->channel->value, 'purpose' => $template->purpose, 'subject' => (string) $template->subject,
            'body' => $template->body, 'provider_template_id' => (string) $template->provider_template_id,
        ];
    }

    public function save(TemplateService $service): mixed
    {
        $user = $this->actor();
        $template = $this->templateId === 0 ? null : MessageTemplate::query()->findOrFail($this->templateId);
        Gate::authorize($template === null ? 'create' : 'update', $template ?? MessageTemplate::class);
        $this->resetErrorBag();

        try {
            $saved = $service->save($template, $this->t, $user);
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        session()->flash('status', $template === null ? 'Template saved. Send it for approval when it is ready.' : 'Template saved.');

        return $this->redirectRoute('marketing.templates.edit', $saved, navigate: true);
    }

    public function openReopen(): void
    {
        abort_unless($this->actor()->can('message_template.edit'), 403);
        Gate::authorize('view', MessageTemplate::query()->findOrFail($this->templateId));
        $this->reopening = true;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeReopen(): void
    {
        $this->reopening = false;
    }

    public function reopen(ApprovalService $approvals): mixed
    {
        $template = MessageTemplate::query()->findOrFail($this->templateId);
        abort_unless($this->actor()->can('message_template.edit'), 403);
        $this->resetErrorBag();

        try {
            $approvals->reopen($template, $this->actor(), $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return null;
        }

        session()->flash('status', 'The template is a draft again. It has to be approved again before anything is sent with it.');

        return $this->redirectRoute('marketing.templates.edit', $template, navigate: true);
    }

    public function render(TemplateRenderer $renderer, TenantContext $tenant): View
    {
        $template = $this->templateId === 0 ? null : MessageTemplate::query()->findOrFail($this->templateId);
        $channel = Channel::tryFrom($this->t['channel']) ?? Channel::Email;
        $company = (string) Company::withoutGlobalScopes()->whereKey($tenant->activeCompanyId())->value('name');
        $locked = $template !== null && ! $template->approval_status->isEditable();

        return view('marketing.templates.form', [
            'template' => $template,
            'channels' => Channel::options(),
            'variables' => TemplateRenderer::VARIABLES,
            'problems' => $renderer->problems($channel, $this->t['purpose'], $channel === Channel::Email ? $this->t['subject'] : null, $this->t['body']),
            'preview' => $renderer->render($channel === Channel::Email ? $this->t['subject'] : null, $this->t['body'], $renderer->sample($company)),
            'channel' => $channel,
            'locked' => $locked,
            'canReopen' => $template !== null && $template->isApproved() && $this->actor()->can('message_template.edit'),
        ]);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
