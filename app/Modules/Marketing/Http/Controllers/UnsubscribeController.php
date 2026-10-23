<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Enums\Channel;
use App\Modules\Marketing\Services\ConsentService;
use App\Modules\Marketing\Services\ContactNormalizer;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * The page behind the link at the bottom of a marketing message, for someone who is not signed in. The link is signed (it cannot be altered or
 * made up) and carries the address encrypted; the page shows the address half-hidden, asks for one click to confirm, and records the opt-out.
 * Opening the link changes nothing — a mail program that fetches links to scan them does not unsubscribe anyone; only the button does.
 */
class UnsubscribeController
{
    public function show(Request $request, int $company, string $channel): View
    {
        [$address, $channelEnum, $name] = $this->resolve($request, $company, $channel);

        return view('marketing.unsubscribe', ['masked' => ContactNormalizer::mask($channelEnum, $address), 'channel' => $channelEnum, 'company' => $name, 'done' => false, 'url' => $request->fullUrl()]);
    }

    public function confirm(Request $request, int $company, string $channel, ConsentService $consent, TenantContext $tenant): View
    {
        [$address, $channelEnum, $name] = $this->resolve($request, $company, $channel);

        $tenant->withCompany($company, fn () => $consent->record($channelEnum, $address, 'opted_out', 'unsubscribe', 'Used the unsubscribe link', null, $company));

        return view('marketing.unsubscribe', ['masked' => ContactNormalizer::mask($channelEnum, $address), 'channel' => $channelEnum, 'company' => $name, 'done' => true, 'url' => $request->fullUrl()]);
    }

    /** @return array{0: string, 1: Channel, 2: string} */
    private function resolve(Request $request, int $company, string $channel): array
    {
        $channelEnum = Channel::tryFrom($channel);
        $name = Company::withoutGlobalScopes()->whereKey($company)->value('name');

        abort_if($channelEnum === null || $name === null, 404);

        try {
            $address = Crypt::decryptString((string) $request->query('a'));
        } catch (DecryptException) {
            abort(404);
        }

        abort_if(ContactNormalizer::address($channelEnum, $address) === null, 404);

        return [$address, $channelEnum, (string) $name];
    }
}
