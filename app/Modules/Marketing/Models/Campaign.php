<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Marketing\Policies\CampaignPolicy;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A campaign: who is written to, with which messages, in what order, for how long. An approval document — what the approver signs is the audience,
 * the steps and the words — with its own run state (draft → running ⇄ paused → completed | cancelled).
 *
 * Once it is sent for approval its content is fixed **in the model**: only the run state and the bookkeeping that goes with it can move. A change to
 * a campaign that was approved is a re-opened draft that has to be approved again (and only a campaign that has not been launched can be re-opened).
 *
 * @property int $id
 * @property string $code
 * @property string $state
 * @property int $location_id
 * @property string $name
 * @property string $type
 * @property string|null $objective
 * @property int $owner_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string|null $budget
 * @property string $audience_kind leads | customers
 * @property array<string, mixed> $audience
 * @property list<string>|null $exit_stages
 * @property int|null $lead_source_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property Carbon|null $launched_at
 * @property Carbon|null $completed_at
 * @property string|null $cancel_reason
 */
#[Fillable(['name', 'type', 'objective', 'start_date', 'end_date', 'budget', 'utm_source', 'utm_medium', 'utm_campaign', 'remarks'])]
#[UsePolicy(CampaignPolicy::class)]
class Campaign extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    public const DRAFT = 'draft';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** The table has no active/inactive `status` (TenantModel would add one): a campaign has its own `state`. */
    protected $attributes = ['state' => 'draft'];

    /** What can move on a campaign whose content is fixed. */
    private const MOVING = ['state', 'launched_at', 'completed_at', 'cancel_reason', 'updated_by', 'updated_at', 'approval_status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'audience' => 'array', 'exit_stages' => 'array', 'launched_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $campaign) {
            $changed = array_diff(array_keys($campaign->getDirty()), self::MOVING);

            if ($changed !== [] && ! in_array($campaign->getRawOriginal('approval_status'), ['draft', 'rejected'], true)) {
                throw new LogicException('A campaign that has been sent for approval does not change; re-open it first ('.implode(', ', $changed).').');
            }
        });

        static::deleting(fn () => throw new LogicException('A campaign is called off, never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** @return HasMany<CampaignStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    /** @return HasMany<CampaignMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }

    public function isDraft(): bool
    {
        return $this->state === self::DRAFT;
    }

    /** Running or paused: members have been chosen and messages may still go out. */
    public function isLive(): bool
    {
        return in_array($this->state, [self::RUNNING, self::PAUSED], true);
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Campaign;
    }

    public function approvalUrl(): ?string
    {
        return route('marketing.campaigns.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Campaign {$this->code} — {$this->name}";
    }

    /** A campaign goes for approval only when it is complete: steps with approved templates, an audience, dates that still make sense. */
    public function beforeSubmitForApproval(): void
    {
        if (! $this->isDraft()) {
            throw ApprovalStateException::notReady('A campaign that has been launched cannot be sent for approval.');
        }

        $missing = app(CampaignService::class)->missingForApproval($this);

        if ($missing !== []) {
            throw ApprovalStateException::notReady(implode(' ', $missing));
        }
    }

    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    protected function approvalFingerprintParts(): array
    {
        $steps = $this->steps()->with('template:id,body,subject,channel,purpose,approval_status')->get()->map(fn (CampaignStep $s) => [
            'position' => $s->position, 'delay_hours' => $s->delay_hours, 'template' => $s->message_template_id,
            'words' => hash('sha256', (string) $s->template?->subject."\n".(string) $s->template?->body), 'channel' => $s->template?->channel->value,
        ])->all();

        return [
            'campaign' => collect($this->getAttributes())->only([
                'company_id', 'location_id', 'code', 'name', 'type', 'objective', 'owner_id', 'start_date', 'end_date', 'budget', 'audience_kind', 'audience', 'exit_stages',
                'lead_source_id', 'utm_source', 'utm_medium', 'utm_campaign',
            ])->all(),
            'steps' => $steps,
        ];
    }

    protected function companyScopedParents(): array
    {
        return ['lead_source_id' => LeadSource::class];
    }

    protected function auditLogName(): string
    {
        return 'campaigns';
    }
}
