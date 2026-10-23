<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Policies\MessageTemplatePolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Support\Arr;

/**
 * The words of a message, with {{variables}} from a fixed list (see TemplateRenderer). An approval document: only an approved, active template can
 * be sent, and what the approver read is exactly what goes out.
 *
 * @property int $id
 * @property string $name
 * @property Channel $channel
 * @property string $purpose marketing | transactional
 * @property string|null $subject
 * @property string $body
 * @property string|null $provider_template_id
 * @property string $status
 */
#[Fillable(['name', 'channel', 'purpose', 'subject', 'body', 'provider_template_id', 'status', 'remarks'])]
#[UsePolicy(MessageTemplatePolicy::class)]
class MessageTemplate extends TenantModel implements ApprovableDocument
{
    use Approvable;

    protected function casts(): array
    {
        return ['channel' => Channel::class];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::MessageTemplate;
    }

    public function approvalUrl(): ?string
    {
        return route('marketing.templates.edit', $this);
    }

    public function approvalTitle(): string
    {
        return "Message template “{$this->name}” ({$this->channel->label()})";
    }

    public function isMarketing(): bool
    {
        return $this->purpose === 'marketing';
    }

    /** @return array<string, mixed> */
    protected function approvalFingerprintParts(): array
    {
        return Arr::only($this->getAttributes(), ['company_id', 'name', 'channel', 'purpose', 'subject', 'body', 'provider_template_id']);
    }

    protected function auditLogName(): string
    {
        return 'message_templates';
    }
}
