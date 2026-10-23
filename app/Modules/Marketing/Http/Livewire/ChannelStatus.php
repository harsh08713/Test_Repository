<?php

namespace App\Modules\Marketing\Http\Livewire;

use App\Modules\Marketing\Channels\ChannelRegistry;
use App\Modules\Marketing\Channels\ChannelWebhooks;
use App\Modules\Marketing\Enums\Channel;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Where WhatsApp and SMS stand for this company: mode, what is still missing, and the address to give each provider for its delivery reports. The credentials themselves live in Company settings. */
#[Title('Channels')]
class ChannelStatus extends Component
{
    public function mount(): void
    {
        Gate::authorize('setting.view');
    }

    public function makeToken(SettingsService $settings, TenantContext $tenant): void
    {
        Gate::authorize('setting.edit');

        $settings->set('channels.webhook_token', ChannelWebhooks::newToken());

        // The token itself is never written down in the audit log: only the fact that it changed
        activity('settings')->causedBy(Auth::user())->event('updated')->withProperties(['secrets' => ['channels.webhook_token (replaced)']])
            ->tap(fn ($a) => $a->company_id = $tenant->activeCompanyId())->log('updated');

        session()->flash('status', 'A new webhook address was made. Give the providers the new address; the old one no longer works.');
    }

    public function render(ChannelRegistry $registry, SettingsService $settings, TenantContext $tenant): View
    {
        $company = (int) $tenant->activeCompanyId();
        $hasToken = ChannelWebhooks::token($company, $settings) !== null;
        $can = Gate::allows('setting.edit');

        $rows = collect([Channel::WhatsApp, Channel::Sms])->map(fn (Channel $c) => [
            'channel' => $c, 'mode' => $registry->mode($c, $company), 'modes' => ChannelRegistry::MODES, 'missing' => $registry->missing($c, $company),
            'connected' => $registry->isConnected($c, $company), 'url' => $hasToken && $can ? ChannelWebhooks::url($c->value, $company, $settings) : null,
        ]);

        return view('marketing.channels', ['rows' => $rows, 'hasToken' => $hasToken, 'canEdit' => $can]);
    }
}
