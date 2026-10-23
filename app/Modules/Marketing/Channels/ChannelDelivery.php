<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Marketing\Services\ConsentService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a provider tells us afterwards, applied once and never backwards: a message that is *delivered* stays delivered if a late "sent" arrives; each timestamp is claimed by a conditional update,
 * so the same report twice (providers repeat them) changes nothing the second time. A failure report only counts for a message not yet delivered or read, and puts it out of the automatic retry
 * (the provider has already said no). Also: a reply of STOP (or its kin) is an opt-out, recorded in the consent history.
 */
final class ChannelDelivery
{
    public const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    public function __construct(private readonly ConsentService $consent) {}

    /** @return bool whether a message of ours was found for the reference */
    public function apply(int $companyId, Channel $channel, string $providerRef, string $event, ?string $reason = null): bool
    {
        $base = fn () => DB::table('communications')->where('company_id', $companyId)->where('channel', $channel->value)->where('provider_ref', $providerRef);

        if (! $base()->exists()) {
            return false;
        }

        match ($event) {
            'delivered' => $base()->whereNull('delivered_at')->update(['delivered_at' => now(), 'updated_at' => now()]),
            'read' => $base()->whereNull('read_at')->update(['read_at' => now(), 'delivered_at' => DB::raw('coalesce(delivered_at, now())'), 'updated_at' => now()]),
            'failed' => $base()->whereIn('status', [Communication::SENT, Communication::SENDING])->whereNull('delivered_at')->whereNull('read_at')
                ->update(['status' => Communication::FAILED, 'attempts' => CommunicationService::MAX_ATTEMPTS, 'failure' => 'The provider reported that it could not deliver the message'.($reason ? ': '.PlainText::safe($reason) : '.'), 'updated_at' => now()]),
            default => null,
        };

        return true;
    }

    /** A reply from a person. Returns whether it was an opt-out. */
    public function reply(int $companyId, Channel $channel, string $from, string $text): bool
    {
        $word = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) (preg_split('/\s+/', trim($text))[0] ?? '')));

        if (! in_array($word, self::STOP_WORDS, true)) {
            return false;
        }

        try {
            $this->consent->record($channel, $from, 'opted_out', 'reply', 'Replied '.$word, null, $companyId);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
