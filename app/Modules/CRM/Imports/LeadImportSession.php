<?php

namespace App\Modules\CRM\Imports;

use App\Models\User;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Imports\FinishesImport;
use App\Modules\Shared\Imports\ImportSession;
use App\Modules\Shared\Imports\RowOutcome;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\LocationScope;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Rules\IndianMobile;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * One pass over a lead file for one company, on behalf of the person who uploaded it.
 *
 * A row becomes exactly the input the lead form would send, and goes through the same `LeadService` — so a lead that
 * arrives in a spreadsheet obeys every rule a typed-in one does. On top of that the session applies the uploader's own
 * reach, which a background job would otherwise not have: only branches they work in, only people they may assign to.
 *
 * Duplicates: a mobile number already on an *open* lead of the company is "already a lead" (unchanged — which is what
 * makes re-uploading a file harmless); the same number twice in the file is an error on the second row.
 *
 * The check and the import run the same code; the only difference is the write at the end.
 */
final class LeadImportSession implements FinishesImport, ImportSession
{
    private ?User $uploader;

    /** @var array<int, array{count: int, first: string, id: int}> owner id => what this import gave them (how many, and the first) */
    private array $given = [];

    private bool $canAssign = false;

    /** @var list<int>|null people the uploader may give leads to (null = anyone in the company) */
    private ?array $assignable = [];

    /** @var array<string, array{id: int, name: string}> branch code (upper case) => branch, only those the uploader works in */
    private array $branches = [];

    /** @var array<int, string> */
    private array $branchNames = [];

    /** @var array<string, int> source code or name (normalised) => id */
    private array $sources = [];

    /** @var array<string, int> state name / code / GST code (normalised) => id */
    private array $states = [];

    /** @var array<string, int|null> "stateId|district name" => id */
    private array $districts = [];

    /** @var array<string, array{id: int, name: string}> e-mail (lower case) => active user of the company */
    private array $people = [];

    /** @var array<string, string> mobile => "LD/… (Stage)" for the open leads the chunk could clash with */
    private array $openLeads = [];

    /** @var array<string, int> mobile => the row of this file that already used it */
    private array $seenMobiles = [];

    /** @var array<string, int> */
    private array $counters = ['already_leads' => 0, 'followups_planned' => 0, 'geography_filled' => 0];

    private ?int $defaultLocationId;

    private ?int $defaultSourceId;

    private ?int $defaultOwnerId;

    private ?int $batchId;

    private LeadService $leads;

    private FollowupService $followups;

    /** @param  array<string, mixed>  $options */
    public function __construct(private readonly int $companyId, ?int $userId, array $options)
    {
        $this->leads = app(LeadService::class);
        $this->followups = app(FollowupService::class);
        $this->uploader = $userId === null ? null : User::query()->where('is_active', true)->find($userId);

        $this->defaultLocationId = is_numeric($options['location_id'] ?? null) ? (int) $options['location_id'] : null;
        $this->defaultSourceId = is_numeric($options['lead_source_id'] ?? null) ? (int) $options['lead_source_id'] : null;
        $this->defaultOwnerId = is_numeric($options['assigned_to'] ?? null) ? (int) $options['assigned_to'] : null;
        $this->batchId = is_numeric($options['_batch_id'] ?? null) ? (int) $options['_batch_id'] : null;

        if ($this->uploader === null) {
            return;
        }

        $tenant = app(TenantContext::class);

        // The uploader's own branches: the job runs for the whole company, so their reach has to be applied by hand.
        $reach = $tenant->accessibleLocationIdsFor($this->uploader, $companyId);
        foreach (Location::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')->get(['id', 'code', 'name']) as $branch) {
            if ($reach === null || in_array((int) $branch->id, $reach, true)) {
                $this->branches[strtoupper($branch->code)] = ['id' => (int) $branch->id, 'name' => $branch->name];
                $this->branchNames[(int) $branch->id] = $branch->name;
            }
        }

        foreach (LeadSource::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')->get(['id', 'code', 'name']) as $source) {
            $this->sources[$this->identity($source->code)] = (int) $source->id;
            $this->sources[$this->identity($source->name)] = (int) $source->id;
        }

        foreach (State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->get(['id', 'code', 'gst_code', 'name']) as $state) {
            $this->states[$this->identity($state->name)] = (int) $state->id;
            $this->states[$this->identity($state->code)] = (int) $state->id;
            $this->states[$this->identity((string) $state->gst_code)] = (int) $state->id;
        }

        $members = DB::table('company_user')->where('company_id', $companyId)->select('user_id');
        foreach (User::query()->where('is_active', true)->whereIn('id', $members)->get(['id', 'name', 'email']) as $person) {
            $this->people[mb_strtolower($person->email)] = ['id' => (int) $person->id, 'name' => $person->name];
        }

        $this->canAssign = $this->uploader->can('lead.assign');
        $managed = app(SalesScope::class)->managedUserIds($this->uploader);
        $this->assignable = $managed === null ? null : [...$managed, (int) $this->uploader->getKey()];
    }

    public function prepareChunk(array $rows): void
    {
        $mobiles = [];
        foreach ($rows as $cells) {
            $mobile = IndianMobile::normalise($cells['mobile'] ?? '');

            if ($mobile !== null && ! array_key_exists($mobile, $this->openLeads)) {
                $mobiles[$mobile] = true;
            }
        }

        if ($mobiles === []) {
            return;
        }

        // Company-wide, whatever branch: a duplicate is a duplicate even if the uploader could not open the other lead.
        $found = Lead::query()->withoutGlobalScope(LocationScope::class)->where('company_id', $this->companyId)
            ->whereIn('mobile', array_keys($mobiles))->open()->orderBy('id')->get(['id', 'mobile', 'lead_number', 'stage']);

        foreach ($found as $lead) {
            $this->openLeads[(string) $lead->mobile] ??= "{$lead->lead_number} ({$lead->stage->label()})";
        }
    }

    public function process(int $rowNumber, array $cells, bool $apply, ?int $userId): RowOutcome
    {
        if ($this->uploader === null) {
            return RowOutcome::error('The person who uploaded this file is no longer an active user, so it cannot be imported.');
        }

        $cell = fn (string $key): string => (string) ($cells[$key] ?? '');
        $errors = [];

        // ---- branch, source, owner: from the row, else from the choices made when uploading ----
        $locationId = $this->defaultLocationId !== null && isset($this->branchNames[$this->defaultLocationId]) ? $this->defaultLocationId : null;
        if ($cell('branch') !== '') {
            $locationId = $this->branches[strtoupper($cell('branch'))]['id'] ?? null;

            if ($locationId === null) {
                $errors[] = "Branch “{$cell('branch')}” is not the code of one of your active branches.";
            }
        } elseif ($locationId === null) {
            $errors[] = 'No branch: fill in the Branch column, or choose a branch when uploading.';
        }

        $sourceId = $this->defaultSourceId ?? ($this->sources[$this->identity(LeadSource::EXCEL)] ?? null);
        if ($cell('source') !== '') {
            $sourceId = $this->sources[$this->identity($cell('source'))] ?? null;

            if ($sourceId === null) {
                $errors[] = "Source “{$cell('source')}” is not one of the company’s active lead sources.";
            }
        } elseif ($sourceId === null) {
            $errors[] = 'No source: fill in the Source column, or choose a source when uploading.';
        }

        $ownerId = $this->defaultOwnerId ?? (int) $this->uploader->getKey();
        if ($cell('assigned_to') !== '') {
            $person = $this->people[mb_strtolower($cell('assigned_to'))] ?? null;

            if ($person === null) {
                $errors[] = "Assigned To “{$cell('assigned_to')}” is not the e-mail address of an active user of this company.";
            } else {
                $ownerId = $person['id'];
            }
        }

        if ($ownerId !== (int) $this->uploader->getKey()) {
            if (! $this->canAssign) {
                $errors[] = 'You may not assign leads to other people, so leave Assigned To empty (the leads become yours).';
            } elseif ($this->assignable !== null && ! in_array($ownerId, $this->assignable, true)) {
                $errors[] = 'You can assign leads only to yourself or to people on the teams you manage.';
            }
        }

        // ---- dates ----
        $leadDate = now()->toDateString();
        if ($cell('lead_date') !== '') {
            $leadDate = $this->parseDay($cell('lead_date'));

            if ($leadDate === null) {
                $errors[] = "Lead Date “{$cell('lead_date')}” is not a date. Use DD-MM-YYYY or YYYY-MM-DD.";
            }
        }

        $followupAt = null;
        if ($cell('next_followup') !== '') {
            $followupAt = $this->parseMoment($cell('next_followup'));

            if ($followupAt === null) {
                $errors[] = "Next Follow-up “{$cell('next_followup')}” is not a date. Use DD-MM-YYYY, optionally followed by HH:MM.";
            } elseif ($followupAt->lt(now())) {
                $errors[] = 'Next Follow-up must be in the future.';
            } elseif ($followupAt->gt(now()->addDays(FollowupService::MAX_DAYS_AHEAD))) {
                $errors[] = 'Next Follow-up is too far ahead: plan within the next two years.';
            }
        }

        // ---- geography by name ----
        [$stateId, $districtId] = $this->geography($cell('state'), $cell('district'), $errors);

        // ---- the mobile number, and duplicates ----
        $mobile = IndianMobile::normalise($cell('mobile'));

        if ($errors !== []) {
            return RowOutcome::error($errors);
        }

        // ---- everything else: exactly the rules of the lead form ----
        $input = ['lead' => [
            'location_id' => (string) $locationId, 'lead_date' => (string) $leadDate, 'lead_source_id' => (string) $sourceId,
            'name' => $cell('name'), 'contact_person' => $cell('contact_person'), 'mobile' => $cell('mobile'), 'email' => $cell('email'),
            'address_line1' => $cell('address'), 'city' => $cell('city'), 'state_id' => (string) ($stateId ?? ''), 'district_id' => (string) ($districtId ?? ''),
            'pin_code' => $cell('pin_code'), 'requirement' => $cell('requirement'),
            'expected_value' => (string) preg_replace('/[₹\s]|(?i:rs\.?)/u', '', $cell('expected_value')),
            'assigned_to' => (string) $ownerId, 'remarks' => $cell('remarks'),
            'acknowledge_duplicate' => true,   // duplicates are this session's business (below), not a question to ask
        ], 'interests' => []];

        try {
            $data = $this->leads->validate($input, null, $this->uploader, LeadChannel::Import);
        } catch (ValidationException $e) {
            return RowOutcome::error(array_values(array_unique(collect($e->errors())->flatten()->map(fn ($m) => (string) $m)->all())));
        }

        if ($mobile !== null && isset($this->seenMobiles[$mobile])) {
            return RowOutcome::error("The same mobile number is on row {$this->seenMobiles[$mobile]} of this file. Keep one of them.");
        }

        if ($mobile !== null && isset($this->openLeads[$mobile])) {
            $this->counters['already_leads']++;
            $this->seenMobiles[$mobile] = $rowNumber;

            return RowOutcome::unchanged("Already a lead: {$this->openLeads[$mobile]}");
        }

        if ($apply) {
            try {
                // A savepoint of its own: a lead and its first follow-up are created together or not at all.
                $lead = DB::transaction(function () use ($input, $followupAt, $ownerId) {
                    $lead = $this->leads->create($input, $this->uploader, LeadChannel::Import, $this->batchId);

                    if ($followupAt !== null) {
                        $this->followups->schedule($lead, ['plan' => ['scheduled_at' => $followupAt->format('Y-m-d H:i'), 'type' => 'call', 'assigned_to' => (string) $ownerId]], $this->uploader);
                    }

                    return $lead;
                });

                $this->given[$ownerId] ??= ['count' => 0, 'first' => $lead->title(), 'id' => (int) $lead->getKey()];
                $this->given[$ownerId]['count']++;
            } catch (ValidationException $e) {
                return RowOutcome::error(array_values(array_unique(collect($e->errors())->flatten()->map(fn ($m) => (string) $m)->all())));
            }
        }

        // Only now — the row has succeeded — does it affect the rows after it.
        if ($mobile !== null) {
            $this->seenMobiles[$mobile] = $rowNumber;
        }
        if ($followupAt !== null) {
            $this->counters['followups_planned']++;
        }
        if ($cell('pin_code') !== '' && $cell('district') === '' && $data['lead']['district_id'] !== null) {
            $this->counters['geography_filled']++;
        }

        $owner = collect($this->people)->firstWhere('id', $ownerId)['name'] ?? 'you';
        $detail = "New lead: {$data['lead']['name']} ({$data['lead']['mobile']}) for {$owner}, ".($this->branchNames[(int) $locationId] ?? 'branch');

        return RowOutcome::created($followupAt === null ? $detail : $detail.', follow-up on '.$followupAt->format('j M Y H:i'));
    }

    public function counters(): array
    {
        return $this->counters;
    }

    /** Tell each person what the import gave them — once per file, not once per lead — except the uploader, who knows. */
    public function finished(): void
    {
        if ($this->uploader === null) {
            return;
        }

        foreach ($this->given as $ownerId => $what) {
            if ($ownerId === (int) $this->uploader->getKey()) {
                continue;
            }

            User::query()->where('is_active', true)->find($ownerId)?->notify(new LeadsAssignedNotification(
                $what['count'], $what['first'], $this->uploader->name,
                $what['count'] === 1 ? route('crm.leads.show', $what['id'], false) : route('crm.leads.index', [], false),
            ));
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /**
     * A state and a district by name. Either may be blank (a PIN code can fill them later).
     *
     * @param  list<string>  $errors
     * @return array{0: int|null, 1: int|null}
     */
    private function geography(string $state, string $district, array &$errors): array
    {
        $stateId = null;

        if ($state !== '') {
            $stateId = $this->states[$this->identity($state)] ?? null;

            if ($stateId === null) {
                $errors[] = "State “{$state}” is not one of the company’s states. Use the names under Masters → Geography → States.";

                return [null, null];
            }
        }

        if ($district === '') {
            return [$stateId, null];
        }

        $key = ($stateId ?? 0).'|'.$this->identity($district);

        if (! array_key_exists($key, $this->districts)) {
            $matches = District::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->companyId)->where('status', 'active')
                ->when($stateId !== null, fn ($q) => $q->where('state_id', $stateId))
                ->where('name', $district)->limit(2)->pluck('id');

            // a district name on its own must not be ambiguous between two states
            $this->districts[$key] = $matches->count() === 1 ? (int) $matches->first() : null;
        }

        if ($this->districts[$key] === null) {
            $errors[] = $stateId === null
                ? "District “{$district}” was not found (or exists in more than one state: add the State)."
                : "District “{$district}” was not found in {$state}.";
        }

        return [$stateId, $this->districts[$key]];
    }

    /** A day as people type it, or as Excel stores a date cell (a serial number), as YYYY-MM-DD. */
    private function parseDay(string $value): ?string
    {
        return $this->parseMoment($value)?->toDateString();
    }

    /** A day, optionally with a time (10:00 when there is none). */
    private function parseMoment(string $value): ?Carbon
    {
        $value = trim(str_replace('T', ' ', $value));

        // Excel keeps a date as the number of days since 30 Dec 1899, with the time as the fraction.
        if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1 && (float) $value >= 20000 && (float) $value <= 80000) {
            $days = (int) floor((float) $value);
            $seconds = (int) round(((float) $value - $days) * 86400);
            $moment = Carbon::create(1899, 12, 30, 0, 0, 0)->addDays($days)->addSeconds($seconds);

            return $seconds === 0 ? $moment->setTime(10, 0) : $moment->seconds(0);
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i', 'd/m/Y H:i', 'd.m.Y H:i'] as $format) {
            if (Carbon::hasFormat($value, $format)) {
                $moment = Carbon::createFromFormat($format, $value);

                return $moment !== null && $moment->format($format) === $value ? $moment->seconds(0) : null;
            }
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y'] as $format) {
            if (Carbon::hasFormat($value, $format)) {
                $moment = Carbon::createFromFormat('!'.$format, $value);

                // createFromFormat rolls 31-02-2026 over to March: refuse it rather than import the wrong day
                return $moment !== null && $moment->format($format) === $value ? $moment->setTime(10, 0) : null;
            }
        }

        return null;
    }

    /** Names are compared ignoring capitals, accents and extra spaces. */
    private function identity(string $text): string
    {
        $text = Normalizer::normalize(trim($text), Normalizer::FORM_D) ?: trim($text);
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

        return mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
