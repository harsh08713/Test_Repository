<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Template data has one door: the form sends it what was typed, the service checks it (a channel, a purpose, a subject where the channel has one, only
 * the listed {{variables}}, the unsubscribe link in marketing e-mail, the channel's length limit) and saves it. A template that has been sent for
 * approval or approved is fixed; to change its words, re-open it (which needs approving again). Switching a template off or on is not a change of
 * words and is allowed on an approved one.
 */
final class TemplateService
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function save(?MessageTemplate $template, array $input, User $actor): MessageTemplate
    {
        $text = fn (string $k): ?string => is_scalar($input[$k] ?? null) && trim((string) $input[$k]) !== '' ? trim((string) $input[$k]) : null;

        $v = Validator::make(['name' => $text('name'), 'channel' => $text('channel'), 'purpose' => $text('purpose'), 'provider_template_id' => $text('provider_template_id')], [
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::in(array_keys(Channel::options()))],
            'purpose' => ['required', Rule::in(['marketing', 'transactional'])],
            'provider_template_id' => ['nullable', 'string', 'max:100'],
        ], ['name.required' => 'Give the template a name.', 'channel.required' => 'Choose the channel.', 'purpose.required' => 'Say whether it is marketing or about something the person asked for.'])->validate();

        $subject = $text('subject');
        $body = (string) ($input['body'] ?? '');
        $problems = $this->renderer->problems(Channel::from($v['channel']), $v['purpose'], $subject, $body);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return DB::transaction(function () use ($template, $v, $subject, $body, $actor) {
            $row = $template === null ? new MessageTemplate : MessageTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            if ($row->exists && ! $row->approval_status->isEditable()) {
                throw new FieldRuleException('This template has been sent for approval, so its words no longer change. Re-open it first.');
            }

            $row->fill([
                'name' => $v['name'], 'channel' => $v['channel'], 'purpose' => $v['purpose'], 'subject' => $v['channel'] === 'email' ? $subject : null,
                'body' => trim($body), 'provider_template_id' => $v['provider_template_id'],
            ]);
            $row->forceFill($row->exists ? ['updated_by' => $actor->getKey()] : ['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();

            return $row->refresh();
        });
    }

    /** Switch a template off (it is no longer offered or sent) or on again. */
    public function setActive(MessageTemplate $template, bool $active, User $actor): MessageTemplate
    {
        $template->forceFill(['status' => $active ? 'active' : 'inactive', 'updated_by' => $actor->getKey()])->save();

        return $template->refresh();
    }
}
