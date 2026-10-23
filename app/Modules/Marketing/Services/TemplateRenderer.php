<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Enums\Channel;

/**
 * Fills a message template with a person's details. A template may use only the variables in `VARIABLES` — a typo is an error when the template is
 * saved, never a message that goes out with `{{nmae}}` in it — and values are inserted as plain text (the e-mail is made safe to show as HTML later,
 * where it is sent). A marketing e-mail must carry the unsubscribe link.
 */
final class TemplateRenderer
{
    /** @var array<string, string> variable => what it is */
    public const VARIABLES = [
        'name' => 'The person’s full name',
        'first_name' => 'Their first name',
        'company' => 'Our company’s name',
        'owner_name' => 'The salesperson who owns the lead, or the campaign’s owner',
        'campaign_name' => 'The campaign’s name',
        'reference' => 'The lead, quotation or order number the message is about',
        'amount' => 'The amount of the quotation or order (₹)',
        'unsubscribe_url' => 'A link to stop receiving these messages (required in marketing e-mail)',
    ];

    /** The longest text a channel takes. */
    public const LIMITS = ['email' => 20000, 'whatsapp' => 1024, 'sms' => 480];

    /** @return list<string> the variables a text uses, in order, once each */
    public function variablesIn(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $text, $found);

        return array_values(array_unique(array_map('strtolower', $found[1])));
    }

    /**
     * What is wrong with a template, as sentences keyed by field; empty when it is fine.
     *
     * @return array<string, string>
     */
    public function problems(Channel $channel, string $purpose, ?string $subject, string $body): array
    {
        $problems = [];
        $subject = $subject === null ? null : trim($subject);
        $body = trim($body);

        if ($body === '') {
            $problems['body'] = 'Write the message.';
        } elseif (mb_strlen($body) > self::LIMITS[$channel->value]) {
            $problems['body'] = $channel->label().' takes at most '.number_format(self::LIMITS[$channel->value]).' characters.';
        }

        if ($channel === Channel::Email && ($subject === null || $subject === '')) {
            $problems['subject'] = 'An e-mail needs a subject.';
        }

        if ($channel !== Channel::Email && $subject !== null && $subject !== '') {
            $problems['subject'] = $channel->label().' messages have no subject.';
        }

        $unknown = array_values(array_diff([...$this->variablesIn((string) $subject), ...$this->variablesIn($body)], array_keys(self::VARIABLES)));

        if ($unknown !== []) {
            $problems['body'] = 'Unknown '.(count($unknown) === 1 ? 'variable' : 'variables').': {{'.implode('}}, {{', $unknown).'}}. Use only the ones listed.';
        }

        if ($purpose === 'marketing' && $channel === Channel::Email && $body !== '' && ! in_array('unsubscribe_url', $this->variablesIn($body), true)) {
            $problems['body'] = ($problems['body'] ?? '').' A marketing e-mail must include {{unsubscribe_url}} so the person can opt out.';
        }

        return array_map('trim', $problems);
    }

    /**
     * @param  array<string, string>  $values  variable => value (a missing one is left empty)
     * @return array{subject: string|null, body: string}
     */
    public function render(?string $subject, string $body, array $values): array
    {
        $fill = fn (string $text): string => (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn (array $m) => (string) ($values[strtolower($m[1])] ?? ''), $text);

        return ['subject' => $subject === null ? null : trim($fill($subject)), 'body' => trim($fill($body))];
    }

    /** @return array<string, string> made-up values for a preview */
    public function sample(string $companyName): array
    {
        return [
            'name' => 'Rakesh Patel', 'first_name' => 'Rakesh', 'company' => $companyName, 'owner_name' => 'Your salesperson', 'campaign_name' => 'Autumn offer',
            'reference' => 'LD/AHD/26-27/00012', 'amount' => '₹ 1,25,000.00', 'unsubscribe_url' => 'https://example.invalid/unsubscribe/…',
        ];
    }
}
