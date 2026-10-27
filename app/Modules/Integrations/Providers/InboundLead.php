<?php

namespace App\Modules\Integrations\Providers;

use Carbon\CarbonImmutable;

/**
 * What any lead provider's record boils down to, before anything is decided about it: who asked, how to reach them, what they
 * asked for. Text is as the provider gave it (trimmed, never invented); what is *valid* — a mobile number, an e-mail address, a
 * PIN code — is decided by the intake, which keeps what it can use and notes the rest.
 */
final readonly class InboundLead
{
    public function __construct(
        public string $providerLeadId,
        public ?string $name,
        public ?string $contactPerson,
        public ?string $mobile,
        public ?string $email,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $pinCode = null,
        public ?string $address = null,
        public ?string $product = null,
        public ?string $message = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?string $kind = null,
        public ?string $countryIso = null,
        public bool $namePlaceholder = false,
        public bool $doNotCall = false,
        /**
         * Channels the visitor agreed to be contacted on, with the wording they agreed to (website forms only; empty = nothing was agreed).
         *
         * @var list<string>
         */
        public array $consentChannels = [],
        public ?string $consentText = null,
        /**
         * The page's UTM values as sent (the attribution service cleans them).
         *
         * @var array<string, string>
         */
        public array $utm = [],
        public ?string $campaignCode = null,
        public ?string $pageUrl = null,
    ) {}
}
