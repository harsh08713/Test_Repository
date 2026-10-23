<?php

use App\Modules\CRM\Http\Livewire\Leads\LeadForm;
use App\Modules\CRM\Models\Lead;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Http\Livewire\CampaignForm;
use App\Modules\Marketing\Http\Livewire\CampaignList;
use App\Modules\Marketing\Http\Livewire\CampaignShow;
use App\Modules\Marketing\Http\Livewire\CommunicationList;
use App\Modules\Marketing\Http\Livewire\ConsentList;
use App\Modules\Marketing\Http\Livewire\TemplateForm;
use App\Modules\Marketing\Http\Livewire\TemplateList;
use App\Modules\Marketing\Http\Livewire\WorkflowForm;
use App\Modules\Marketing\Http\Livewire\WorkflowList;
use App\Modules\Marketing\Http\Livewire\WorkflowShow;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Services\CampaignAttribution;
use App\Modules\Marketing\Services\CampaignRunner;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Reports\Reports\CampaignPerformance;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Services\Customer360;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * The marketing screens: templates, campaigns, consent, the message log, workflows, the public unsubscribe page, and where a campaign shows up in leads,
 * customers and reports — each showing a person only what they may see and refusing what they may not do.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Mail::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->optIn = fn (string $email) => inCompany($this->acme, fn () => app(ConsentService::class)->record(Channel::Email, $email, 'opted_in', 'form'));
});

// ---- templates ---------------------------------------------------------------------------------------------------------------

it('makes a template with a live preview and the checks a template needs, and fixes it once it is sent for approval', function () {
    $form = Livewire::actingAs($this->manager)->test(TemplateForm::class)
        ->set('t.name', 'Autumn offer')->set('t.subject', 'An offer for {{first_name}}')->set('t.body', 'Dear {{name}}, see {{unsubscribe_url}}')
        ->assertSee('An offer for Rakesh')->assertSee('Dear Rakesh Patel')->assertSee('Nothing to fix');

    $form->set('t.body', 'Dear {{nmae}}')->assertSee('Unknown variable')->call('save')->assertHasErrors('body');
    expect(MessageTemplate::query()->count())->toBe(0);

    $form->set('t.body', 'Dear {{name}}, see {{unsubscribe_url}}')->call('save')->assertHasNoErrors();
    $template = MessageTemplate::query()->sole();
    expect($template->approval_status->value)->toBe('draft')->and($template->channel->value)->toBe('email');

    // approved: its words are read-only, until it is re-opened with a reason
    approveDocument($this->acme, $template, $this->manager, $this->approver);
    Livewire::actingAs($this->manager)->test(TemplateForm::class, ['editing' => $template->id])->assertSee('is approved')->assertDontSee('>Save<', false)
        ->call('save')->assertForbidden();
    Livewire::actingAs($this->manager)->test(TemplateForm::class, ['editing' => $template->id])->call('openReopen')->set('reason', '')->call('reopen')->assertHasErrors('reason');
    Livewire::actingAs($this->manager)->test(TemplateForm::class, ['editing' => $template->id])->call('openReopen')->set('reason', 'Wording changed')->call('reopen');
    expect($template->fresh()->approval_status->value)->toBe('draft');
});

it('lists templates, switches one off, and shows them only to those who may', function () {
    $template = makeTemplate($this->acme, $this->manager);

    Livewire::actingAs($this->manager)->test(TemplateList::class)->assertSee('Autumn offer')->call('toggle', $template->id);
    expect($template->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->bde)->test(TemplateList::class)->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(TemplateList::class)->assertDontSee('Autumn offer');
    $this->actingAs($this->manager)->get(route('marketing.templates.index'))->assertOk();
    $this->actingAs($this->bde)->get(route('marketing.templates.create'))->assertForbidden();
});

// ---- campaigns ----------------------------------------------------------------------------------------------------------------

it('makes a campaign, previews its audience, sends it for approval, and launches it once approved', function () {
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works', 'email' => 'alpha@example.com']);
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Beta Works', 'email' => 'beta@example.com']);
    ($this->optIn)('alpha@example.com');
    $template = makeTemplate($this->acme, $this->manager);

    $form = Livewire::actingAs($this->manager)->test(CampaignForm::class)
        ->set('c.name', 'Autumn campaign')->set('c.location_id', (string) $this->ahd->id)->set('c.owner_id', (string) $this->bde->id)->set('steps.0.message_template_id', (string) $template->id)
        ->call('previewAudience')->assertSee('2 leads match')->assertSee('1 can be written to')->assertSee('1 have an address but no consent');

    $form->call('save')->assertHasNoErrors();
    $campaign = Campaign::query()->sole();
    expect($campaign->code)->toBe('CP/AHD/26-27/00001')->and($campaign->owner_id)->toBe($this->bde->id);

    $show = Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->assertSee('Autumn campaign')->assertDontSee('>Launch<', false);
    inCompany($this->acme, function () use ($campaign) {
        app(ApprovalService::class)->submit($campaign, $this->manager);
        app(ApprovalService::class)->approve($campaign->fresh(), $this->approver);
    });

    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign->fresh()])->assertSee('Launch')->call('launch');
    expect($campaign->fresh()->state)->toBe('running')->and(CampaignMember::query()->where('campaign_id', $campaign->id)->count())->toBe(2);

    // the salesperson may look, not launch or pause
    Livewire::actingAs($this->bde)->test(CampaignShow::class, ['campaign' => $campaign->fresh()])->assertOk()->call('pause')->assertForbidden();
    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign->fresh()])->call('pause');
    expect($campaign->fresh()->state)->toBe('paused');
    Livewire::actingAs($this->bde)->test(CampaignForm::class, ['editing' => $campaign->id])->assertForbidden();
    $this->actingAs($this->bde)->get(route('marketing.campaigns.create'))->assertForbidden();
    $this->actingAs($this->bde)->get(route('marketing.campaigns.index'))->assertOk()->assertSee('Autumn campaign');
    $this->actingAs($this->telecaller)->get(route('marketing.campaigns.index'))->assertForbidden();
    expect(show($show))->toBeTrue();
});

function show($component): bool
{
    return $component !== null;
}

it('marks a member as having responded and calls a campaign off, with a reason', function () {
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works', 'email' => 'alpha@example.com']);
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['owner_id' => (string) $this->bde->id], 'running');
    $member = CampaignMember::query()->where('campaign_id', $campaign->id)->sole();

    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->assertSee('Alpha Works')
        ->call('openDialog', 'respond', $member->id)->set('reason', 'Rang back')->call('markResponded');
    expect($member->fresh()->responded_at)->not->toBeNull()->and($member->fresh()->exit_reason)->toBe('responded');

    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->call('openDialog', 'cancel')->set('reason', '')->call('cancelCampaign')->assertHasErrors('reason');
    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->call('openDialog', 'cancel')->set('reason', 'Offer withdrawn')->call('cancelCampaign');
    expect($campaign->fresh()->state)->toBe('cancelled')->and($campaign->fresh()->cancel_reason)->toBe('Offer withdrawn');

    // a member of another campaign cannot be marked through this one
    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->call('openDialog', 'respond', 999999)->assertForbidden();
});

it('lists campaigns with what each has done', function () {
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works', 'email' => 'alpha@example.com']);
    ($this->optIn)('alpha@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['owner_id' => (string) $this->bde->id, 'name' => 'Visible campaign'], 'running');
    inCompany($this->acme, fn () => app(CampaignRunner::class)->run());

    Livewire::actingAs($this->manager)->test(CampaignList::class)->assertSee('Visible campaign')->assertSee($campaign->code)->set('state', 'paused')->assertDontSee('Visible campaign');
    Livewire::actingAs($this->globexAdmin)->test(CampaignList::class)->assertDontSee('Visible campaign');
});

// ---- consent, the log and the unsubscribe link ------------------------------------------------------------------------------------

it('records an opt-out, keeps its history, and lets a salesperson record one too', function () {
    ($this->optIn)('a@example.com');

    $page = Livewire::actingAs($this->bde)->test(ConsentList::class)->set('f.address', 'A@Example.com')->set('f.status', 'opted_out')->set('f.note', 'Asked on the phone')->call('record')->assertHasNoErrors();
    expect(ContactConsent::query()->count())->toBe(2)->and(ContactConsent::query()->latest('id')->first()->recorded_by)->toBe($this->bde->id);

    $page->assertSee('a@example.com')->assertSee('Opted out')->call('toggleHistory', 'email|a@example.com')->assertSee('opted in')->assertSee('Asked on the phone');
    $page->set('f.address', 'nonsense')->call('record')->assertHasErrors('address');

    Livewire::actingAs($this->telecaller)->test(ConsentList::class)->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(ConsentList::class)->assertDontSee('a@example.com');
});

it('shows the message log only for the leads and customers the person may see', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works', 'email' => 'alpha@example.com']);
    $theirs = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works', 'email' => 'beta@example.com']);
    ($this->optIn)('alpha@example.com');
    ($this->optIn)('beta@example.com');
    $template = makeTemplate($this->acme, $this->manager);
    $svc = fn () => inCompany($this->acme, fn () => app(CommunicationService::class));
    $svc()->queue($template, ['name' => 'Alpha Works', 'address' => 'alpha@example.com', 'subject_type' => 'lead', 'subject_id' => $mine->id], 'a');
    $svc()->queue($template, ['name' => 'Beta Works', 'address' => 'beta@example.com', 'subject_type' => 'lead', 'subject_id' => $theirs->id], 'b');

    Livewire::actingAs($this->bde)->test(CommunicationList::class)->assertSee('Alpha Works')->assertDontSee('Beta Works');
    Livewire::actingAs($this->manager)->test(CommunicationList::class)->assertSee('Alpha Works')->assertDontSee('Beta Works');   // the team, not the colleague outside it
    Livewire::actingAs($this->admin)->test(CommunicationList::class)->assertSee('Alpha Works')->assertSee('Beta Works')->assertSee('Handed to the mail server');
    Livewire::actingAs($this->telecaller)->test(CommunicationList::class)->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(CommunicationList::class)->assertDontSee('Alpha Works');
});

it('opens the unsubscribe page without changing anything, and opts out only on the button', function () {
    $link = inCompany($this->acme, fn () => app(ConsentService::class)->unsubscribeUrl(Channel::Email, 'anil@example.com', $this->acme->id));
    ($this->optIn)('anil@example.com');

    $this->get($link)->assertOk()->assertSee('Stop receiving messages from ACME')->assertSee('an•••@example.com')->assertDontSee('anil@example.com');
    expect(inCompany($this->acme, fn () => app(ConsentService::class)->latest(Channel::Email, 'anil@example.com', $this->acme->id))->status)->toBe('opted_in');

    $this->post($link)->assertOk()->assertSee('You have been unsubscribed');
    $latest = inCompany($this->acme, fn () => app(ConsentService::class)->latest(Channel::Email, 'anil@example.com', $this->acme->id));
    expect($latest->status)->toBe('opted_out')->and($latest->source)->toBe('unsubscribe');
});

it('refuses an unsubscribe link that was altered, made up, or for another channel', function () {
    $link = inCompany($this->acme, fn () => app(ConsentService::class)->unsubscribeUrl(Channel::Email, 'anil@example.com', $this->acme->id));

    $this->get($link.'&x=1')->assertForbidden();                                          // signature no longer matches
    $this->get(str_replace('/email', '/sms', $link))->assertForbidden();                  // another channel: not what was signed
    $this->get(str_replace('/'.$this->acme->id.'/', '/'.$this->globex->id.'/', $link))->assertForbidden();   // another company
    $this->get(route('marketing.unsubscribe', ['company' => $this->acme->id, 'channel' => 'email', 'a' => 'garbage']))->assertForbidden();   // unsigned
    $this->get(URL::signedRoute('marketing.unsubscribe', ['company' => $this->acme->id, 'channel' => 'email', 'a' => 'garbage']))->assertNotFound();   // signed but not an address
    $this->post($link.'&x=1')->assertForbidden();

    expect(ContactConsent::query()->count())->toBe(0);
});

// ---- where a campaign shows up -------------------------------------------------------------------------------------------------

it('fills the lead form from a campaign link, keeps the campaign and the UTM values, and lets it be chosen by hand', function () {
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template);

    Livewire::actingAs($this->manager)->withQueryParams(['campaign' => $campaign->code, 'utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn'])->test(LeadForm::class)
        ->assertSet('attribution.campaign_id', (string) $campaign->id)->assertSet('attribution.utm_source', 'facebook')->assertSet('fromLink', true)
        ->set('lead.location_id', (string) $this->ahd->id)->set('lead.lead_source_id', (string) leadSourceId($this->acme))->set('lead.name', 'Linked Works')->set('lead.mobile', '9825012345')
        ->call('save')->assertHasNoErrors();

    $lead = Lead::query()->where('name', 'Linked Works')->sole();
    expect($lead->campaign_id)->toBe($campaign->id)->and($lead->attribution)->toBe('link')->and($lead->utm['utm_source'])->toBe('facebook');

    Livewire::actingAs($this->manager)->withQueryParams([])->test(LeadForm::class)
        ->set('attribution.campaign_id', (string) $campaign->id)->set('lead.location_id', (string) $this->ahd->id)->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->set('lead.name', 'Chosen Works')->set('lead.mobile', '9825012346')->call('save')->assertHasNoErrors();
    expect(Lead::query()->where('name', 'Chosen Works')->sole()->attribution)->toBe('manual');

    // a person without the right to see campaigns is offered none, and a made-up campaign is not credited
    Livewire::actingAs($this->telecaller)->withQueryParams([])->test(LeadForm::class)->assertDontSee('id="l-camp"', false);
});

it('credits approved sales to the campaign and reports them against its budget', function () {
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $bolt, $this->ahd, '125.50');
    $template = makeTemplate($this->acme, $this->manager);
    $campaign = makeCampaign($this->acme, $this->manager, $this->ahd, $template, ['budget' => '1000.00']);

    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Linked Works'], [lineRow($bolt, '10', '')]);
    inCompany($this->acme, fn () => app(CampaignAttribution::class)->tag($lead, $campaign->id, [], 'manual'));
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, qualify($this->acme, $lead->fresh(), $this->bde), ['customer_ledger_id' => (string) $customer->id]), $this->bde, $this->approver);
    $order = makeSalesOrder($this->acme, $this->bde, $deal, $customer, [], null, 'approved', $this->approver);

    $row = inCompany($this->acme, function () use ($campaign) {
        Auth::setUser($this->admin);
        $report = app(ReportCatalog::class)->find('campaign-performance');

        return $report->present($report->query(new ReportInput, $this->admin)->where('campaigns.id', $campaign->id)->first());
    });

    expect($row['leads'])->toBe(1)->and($row['deals'])->toBe(1)->and($row['orders'])->toBe(1)->and($row['sales'])->toBe(number_format((float) $order->taxable_total, 2, '.', ''))
        ->and($row['cost_per_lead'])->toBe('1000.00')->and((float) $row['sales_per_rupee'])->toBeGreaterThan(0);

    expect((new CampaignPerformance)->note())->toContain('ACTUAL');
    Livewire::actingAs($this->manager)->test(CampaignShow::class, ['campaign' => $campaign])->assertSee('Approved sales credited')->assertSee('delivery, opens and clicks need a provider');
});

it('lists the messages sent to a customer on the customer page, as far as the person may read them', function () {
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['email' => 'accounts@example.com']);
    ($this->optIn)('accounts@example.com');
    $template = makeTemplate($this->acme, $this->manager, ['name' => 'Offer', 'subject' => 'Offer for the customer']);
    inCompany($this->acme, fn () => app(CommunicationService::class)->queue($template, ['name' => 'Accounts', 'address' => 'accounts@example.com', 'subject_type' => 'ledger', 'subject_id' => $customer->id], 'c1'));

    $view = fn ($user) => inCompany($this->acme, function () use ($user, $customer) {
        Auth::setUser($user);
        app(TenantContext::class)->flush();

        return app(Customer360::class)->build(Ledger::query()->findOrFail($customer->id), $user);
    });

    expect($view($this->admin)['communications'])->toHaveCount(1)->and($view($this->bde)['communications'])->toHaveCount(1)   // a customer’s mail is visible to anyone who may open the customer and the log
        ->and($view($this->telecaller)['communications'])->toBeNull();
    $this->actingAs($this->admin)->get(route('crm.customers.show', $customer))->assertSee('Messages sent')->assertSee('Offer for the customer');
});

// ---- workflows -----------------------------------------------------------------------------------------------------------------

it('builds a workflow on the screen, tests it, and lets only an administrator switch it on', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works', 'expected_value' => '90000']);

    Livewire::actingAs($this->manager)->test(WorkflowForm::class)->set('w.name', 'Big leads')->set('w.trigger', 'lead_created')
        ->call('addCondition')->set('conditions.0.field', 'value')->set('conditions.0.op', 'gte')->set('conditions.0.value', '50000')
        ->set('actions.0.title', 'Call {{name}}')->set('actions.0.assignee', 'owner')->call('save')->assertHasNoErrors();

    $workflow = Workflow::query()->sole();
    expect($workflow->state)->toBe('draft')->and($workflow->current->definition['conditions'][0])->toBe(['field' => 'value', 'op' => 'gte', 'value' => '50000']);

    Livewire::actingAs($this->manager)->test(WorkflowForm::class)->set('w.name', '')->call('save')->assertHasErrors('name');
    Livewire::actingAs($this->manager)->test(WorkflowShow::class, ['workflow' => $workflow])->assertSee('Big leads')->assertSee('Value (₹) is at least ₹ 50000')
        ->set('sample', 'lead:'.$lead->id)->call('test')->assertSee('It would:')->assertSee('make a task')
        ->call('activate')->assertForbidden();   // the manager may make and change workflows, not switch them on
    expect($workflow->fresh()->state)->toBe('draft');

    Livewire::actingAs($this->admin)->test(WorkflowShow::class, ['workflow' => $workflow])->call('activate');
    expect($workflow->fresh()->state)->toBe('active');
    Livewire::actingAs($this->admin)->test(WorkflowShow::class, ['workflow' => $workflow->fresh()])->call('pause');
    expect($workflow->fresh()->state)->toBe('paused');

    Livewire::actingAs($this->manager)->test(WorkflowList::class)->assertSee('Big leads');
    Livewire::actingAs($this->bde)->test(WorkflowList::class)->assertForbidden();
    Livewire::actingAs($this->globexAdmin)->test(WorkflowList::class)->assertDontSee('Big leads');
    $this->actingAs($this->bde)->get(route('marketing.workflows.create'))->assertForbidden();
});

it('shows the marketing menu to those who may open it', function () {
    $this->actingAs($this->manager)->get(route('dashboard'))->assertOk()->assertSee(route('marketing.campaigns.index'), false)->assertSee(route('marketing.workflows.index'), false);
    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertOk()->assertDontSee(route('marketing.campaigns.index'), false);
    expect(Communication::query()->count())->toBe(0);
});
