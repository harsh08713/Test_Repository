<?php

namespace App\Modules\Integrations\Providers;

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\Integrations\Exceptions\ProviderException;
use App\Modules\Integrations\Providers\Concerns\ReadsRecords;
use Carbon\CarbonInterface;

/**
 * The company's own website form — **push only**, and the only provider whose sender is a visitor's browser.
 *
 * The wire format is ours, so it is documented rather than guessed (docs/integrations.md): one flat JSON object (or ordinary form fields) with
 * at least one way to reach the person — `mobile` (or `phone`), or `email`. Everything else is optional:
 *
 *   name, company, mobile, email, city, state, pincode, address, product, message, form (what the form is called),
 *   page_url (the page it was on; the query string is dropped), utm_source / utm_medium / utm_campaign / utm_term / utm_content,
 *   campaign (a campaign code), submission_id (the page's own id for this submission, so a double click is one lead),
 *   consent + consent_text + consent_channels (the visitor ticked a box: see below), _gotcha (a hidden trap field: must stay empty).
 *
 * Consent is only taken as given when the form says **what the visitor agreed to** (`consent_text`, the wording beside the tick box) and **which
 * channels** it covered (`consent_channels`: email, whatsapp, sms). The intake records it; the adapter only reads it.
 *
 * A submission with no `submission_id` is identified by its content and the day, so the same person sending the same words twice in a day is one
 * lead (and the second is counted, not made).
 */
final class WebsiteProvider implements BrowserForm, LeadProvider
{
    use ReadsRecords;

    private const CHANNELS = ['email', 'whatsapp', 'sms'];

    public function key(): string
    {
        return 'website';
    }

    public function label(): string
    {
        return 'Website form';
    }

    public function sourceCode(): string
    {
        return 'WEBSITE';
    }

    public function channel(): LeadChannel
    {
        return LeadChannel::Website;
    }

    public function canPull(): bool
    {
        return false;
    }

    public function canPush(): bool
    {
        return true;
    }

    public function minPullGapMinutes(): int
    {
        return 0;
    }

    public function maxWindowDays(): int
    {
        return 0;
    }

    public function fetch(string $apiKey, CarbonInterface $from, CarbonInterface $to): array
    {
        throw ProviderException::invalid('The website form sends its leads to us; there is nothing to pull.');
    }

    /** One submission is one lead: a list, or a body with no way to reach the person, is not a form submission. */
    public function extractRecords(array $body): array
    {
        if (array_is_list($body) && $body !== []) {
            return [];
        }

        $inner = $body['lead'] ?? null;
        $record = is_array($inner) && ! array_is_list($inner) ? $inner : $body;

        $clean = [];

        foreach ($record as $key => $value) {
            $name = strtolower(trim((string) $key));

            if ($name === 'thanks_url') {
                continue;   // where to send the visitor next: the gate uses it, it is not part of the lead
            }

            if (is_array($value)) {
                $name === 'consent_channels' && $clean[$name] = array_values(array_filter($value, 'is_string'));

                continue;
            }

            is_scalar($value) && $clean[$name] = $value;
        }

        return $this->text($clean, 'mobile', 'phone', 'whatsapp', 'email') === null ? [] : [$clean];
    }

    public function providerLeadId(array $record): string
    {
        $given = $this->text($record, 'submission_id');

        if ($given !== null && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $given) === 1) {
            return "s-{$given}";
        }

        $digits = preg_replace('/\D+/', '', (string) $this->text($record, 'mobile', 'phone', 'whatsapp'));
        $key = implode('|', [$digits, strtolower((string) $this->text($record, 'email')), (string) $this->text($record, 'message'), (string) $this->text($record, 'name'), now()->toDateString()]);

        return 'w-'.substr(hash('sha256', $key), 0, 32);
    }

    public function normalise(array $record): InboundLead
    {
        $person = $this->text($record, 'name', 'full_name') ?? trim(($this->text($record, 'first_name') ?? '').' '.($this->text($record, 'last_name') ?? ''));
        $person = $person === '' ? null : $person;
        $company = $this->text($record, 'company', 'organisation', 'organization');
        $form = $this->text($record, 'form');

        $consentText = $this->text($record, 'consent_text');
        $channels = $this->truthy($record['consent'] ?? null) && $consentText !== null ? $this->channels($record['consent_channels'] ?? null) : [];

        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $name) {
            $value = $this->text($record, $name);
            $value === null || $utm[$name] = $value;
        }

        return new InboundLead(
            providerLeadId: $this->providerLeadId($record),
            name: $company ?? $person,
            contactPerson: $company === null ? null : $person,
            mobile: $this->text($record, 'mobile', 'phone', 'whatsapp'),
            email: $this->text($record, 'email'),
            city: $this->text($record, 'city'),
            state: $this->text($record, 'state'),
            pinCode: $this->text($record, 'pincode', 'pin_code', 'pin', 'zip'),
            address: $this->text($record, 'address'),
            product: $this->text($record, 'product', 'item', 'interest'),
            message: $this->text($record, 'message', 'requirement', 'comments', 'enquiry'),
            kind: $form === null ? null : mb_substr($form, 0, 60),
            namePlaceholder: $company === null && $person === null,
            consentChannels: $channels,
            consentText: $channels === [] ? null : mb_substr((string) $consentText, 0, 200),
            utm: $utm,
            campaignCode: $this->text($record, 'campaign'),
            pageUrl: $this->page($this->text($record, 'page_url', 'page')),
        );
    }

    /** @param  array<string, mixed>  $record */
    public function isBot(array $record): bool
    {
        return $this->text($record, '_gotcha') !== null;
    }

    public function snippet(string $address): string
    {
        $url = json_encode($address, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<form id="enquiry-form">
  <input name="name" placeholder="Your name" required>
  <input name="mobile" type="tel" placeholder="Mobile number" required>
  <input name="email" type="email" placeholder="E-mail">
  <textarea name="message" placeholder="What do you need?"></textarea>
  <input name="_gotcha" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-5000px">
  <button type="submit">Send</button>
</form>
<script>
document.getElementById('enquiry-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(event.target));
  data.page_url = location.href;
  data.submission_id = data.submission_id || crypto.randomUUID();
  new URLSearchParams(location.search).forEach((value, key) => { if (key.startsWith('utm_') || key === 'campaign') data[key] = value; });
  const reply = await fetch({$url}, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data) });
  alert(reply.ok ? 'Thank you. We will call you soon.' : 'Sorry, that did not go through. Please phone us.');
});
</script>
HTML;
    }

    /**
     * @return list<string>
     */
    private function channels(mixed $given): array
    {
        $names = is_array($given) ? $given : (is_string($given) ? preg_split('/[\s,;]+/', $given, -1, PREG_SPLIT_NO_EMPTY) : []);

        return array_values(array_unique(array_filter(array_map(fn ($name) => strtolower(trim((string) $name)), (array) $names), fn (string $name) => in_array($name, self::CHANNELS, true))));
    }

    /** The page without its query string or anything after it (a link may carry a token or a person's details), and only if it is a web address. */
    private function page(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        return mb_substr(strtok($url, '?#') ?: $url, 0, 200);
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true', 'on'], true);
    }
}
