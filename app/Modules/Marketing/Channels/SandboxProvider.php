<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Models\Communication;
use Illuminate\Support\Str;

/** "Sandbox" mode: nothing leaves the system. The message is marked as handed over with a reference that starts with "sandbox-", so a campaign can be rehearsed end to end. */
final class SandboxProvider implements ChannelProvider
{
    public function send(Communication $message, int $companyId): string
    {
        return 'sandbox-'.Str::lower((string) Str::ulid());
    }
}
