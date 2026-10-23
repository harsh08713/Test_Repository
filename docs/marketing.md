# Campaigns, consent and workflow automation (Phase 15)

Code: `app/Modules/Marketing`. Screens under **Marketing** in the menu: Campaigns, Message templates, Workflows, Consent, Message log.
Nothing here talks to WhatsApp, SMS or telephony yet (Phase 16): **e-mail is the only channel that can be handed over**; a message on any other
channel is kept as `not_connected`, never "sent".

## The pieces

| Piece | What it is |
|---|---|
| **Message template** (`message_templates`) | A channel, a purpose (**marketing** or **transactional**), a subject and a body with `{{placeholders}}`. An **approval document** (CP series); only an *approved, active* template is ever used. Approved templates are locked — re-open with a reason to change one. |
| **Campaign** (`campaigns`, `campaign_steps`, `campaign_members`) | A named audience plus an ordered list of steps (a delay in hours and a template). An approval document. States: draft → (approval) → launched/running → paused / completed / cancelled. |
| **Audience** (`AudienceBuilder`) | A *definition* (filters), previewed while editing and **frozen into members at launch**. Leads: stages, sources, branch, owners, dates, text. Customers: kind, text, ordered / not ordered within N days. Worked out with the **campaign owner's own reach** — a campaign cannot write to people its owner may not see. |
| **Consent** (`contact_consents`) | Append-only events per channel and address; the newest wins; an opt-out always wins until the person opts in again. |
| **Message log** (`communications`) | One row for every message a campaign, a workflow or a person asked for — what was sent (rendered text kept as it went out), to whom, why it was held back if it was. |
| **Workflow** (`workflows`, `workflow_versions`, `workflow_runs`) | "When *X* happens, if *conditions*, do *actions*" — built only from the listed parts, versioned, run as a named person. |

## Sending rules (`CommunicationService`, `ConsentService`)

* **Idempotency key** (`c{campaign}:m{member}:s{step}`, `w{run}:a{action}`): unique, so the same step for the same person can never produce two messages,
  however often the runner runs or crashes. The member is moved on only by a conditional UPDATE on the step they were at.
* **Consent** is checked when a message is made and again just before it is handed over. An opted-out address is never written to. Marketing to
  WhatsApp/SMS needs an explicit opt-in; to e-mail it needs one too unless the company turns off `marketing.email_requires_optin`. Transactional messages
  need only that the person has not opted out. A refused message is **kept** as `suppressed` with the reason.
* **Frequency cap**: `marketing.max_per_contact_per_week` marketing messages to one address over 7 days; over the cap the member is **held** and tried
  again the next day (no message is made).
* **Statuses**: `queued` → `sent` (the mail server accepted it — **not** "delivered") / `failed` (tried 3 times) / `suppressed` / `not_connected` /
  `cancelled`. Delivery, open and click tracking arrive with the Phase 16 providers.
* **Unsubscribe**: every marketing e-mail carries `{{unsubscribe_url}}`, a signed, throttled link. Opening it (GET) shows a page and changes nothing;
  confirming (POST) records the opt-out. A marketing e-mail template that does not contain `{{unsubscribe_url}}` cannot be saved.
* A sequence **ends** for a member when they respond, when the lead reaches a stage the campaign lists as an exit, when the record is gone, or when they
  opt out or never agreed to marketing; the reason is recorded.
* The message log shows a person only the messages about records they may open (`CommunicationAccess`: a lead through `LeadAccess`, a customer through `ledger.view`).

## Running a campaign

1. Make templates (they go through approval). 2. Make the campaign: audience, steps, owner, branch, dates, exit stages; the preview shows how many
would be held back for lack of a contact detail or of consent. 3. Send it for approval (the page lists what is missing). 4. **Launch**: the audience is
frozen into members. 5. `marketing:run` (every five minutes) works through due members. Pause/resume, cancel (reason) and re-open (reason) are on the
campaign page; a member can be marked **responded** by hand.

### Attribution (`CampaignAttribution`)

A lead is credited to a campaign three ways, kept apart in `leads.attribution`: **manual** (chosen on the lead form), **link** (`?campaign=CP/…&utm_*`),
**inferred** (the lead's mobile or e-mail matches somebody a campaign wrote to in the last 30 days — the most recent campaign wins and that member is
marked responded). Only the five standard UTM keys are stored, 100 plain-text characters each. The sales dashboard's *Campaign attribution* table and the
*Campaign performance* report count these; **attribution is a credit, not proof of cause**.

## Workflows (`WorkflowService`, `WorkflowEngine`)

* **Triggers**: a lead is created, a lead changes stage, a follow-up is overdue (by N hours), a deal is won, a deal is lost, a sent quotation is nearing its
  expiry (N days before), a sales order is approved. **Conditions** (source, stage, branch, owner, value) and **actions** are picked from fixed lists (create a task, schedule a reminder, notify the owner's
  manager, assign the lead, queue an approved message). There is **no free code** and no action that approves a document, skips consent or
  creates a lead or changes a stage — so workflows cannot set each other off.
* **Versions are immutable**: saving makes a new version; runs record the version they used.
* **Run as**: every action is done as the person who saved the workflow, with that person's rights — they must still be active, hold the permission and
  be able to see the record. If not, the run fails with the reason.
* **Once per event**: `unique(workflow_id, trigger_key)` is claimed *before* anything is done; a retry skips the actions that already worked.
* **Starts from the moment it is switched on** — it does not act on yesterday's records.
* **Limits**: runs per hour per workflow and per record per day; a run is marked skipped when conditions fail.
* **Dry run**: describes what would be done and does none of it; **Test** on the workflow page runs it against one real record the viewer can open.
* `automation:run` (every five minutes) performs the sweep.

## Permissions

New forms: `campaign` and `message_template` (the approval set), `consent` (view, create, export), `communication` (view, export), `workflow` (view, create,
edit, configure, view_audit). Sales Manager: campaigns/templates/workflows create+edit, consent and message log; BDE: view campaigns,
view and record consent, view the message log; Company Admin and Super Admin: everything. Full matrix: [permission-matrix.md](permission-matrix.md).
Settings: `marketing.email_requires_optin`, `marketing.max_per_contact_per_week`, `marketing.sender_name`.

## Commands

```bash
php artisan marketing:run        # one round of campaign steps (the scheduler runs it every 5 minutes)
php artisan automation:run       # one round of workflows (the scheduler runs it every 5 minutes)
```

The scheduler (`schedule:run` every minute) and a queue worker must be running outside local development — see **System health** (`/admin/health`).

## Tests

`tests/Feature/Marketing/*` (services, workflows, screens) and `tests/Concurrency/MarketingConcurrencyTest.php`: four real processes over twenty due
members make twenty messages, not eighty, and four engines over twelve new leads make twelve runs and twelve tasks.

## Known gaps and decisions to confirm

* **E-mail only.** WhatsApp, SMS and telephony templates can be written and consent recorded, but nothing is sent until the company connects a channel (Phase 16: see [channels.md](channels.md)).
* "Sent" means accepted by the mail server. No delivery, bounce, open or click tracking yet; bounces do not opt anyone out.
* Consent is **recorded by us** (form, manual, import, unsubscribe link). It is the company's duty to hold a lawful basis; the tool refuses to write
  to opted-out addresses but cannot prove that a past opt-in was genuine.
* Attribution is last-touch within 30 days; multi-touch is not built.
* No A/B variants, no send-time optimisation, no HTML template designer (plain text with placeholders).
* Workflows have no branching, loops or waits beyond the fixed triggers (a "wait" is a trigger that fires after N days).
* A campaign's audience cannot exceed 20,000 people.
