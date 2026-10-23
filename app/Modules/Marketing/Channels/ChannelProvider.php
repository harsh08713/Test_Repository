<?php

namespace App\Modules\Marketing\Channels;

use App\Modules\Marketing\Models\Communication;

/** Hands one message to one provider and says what the provider called it. A provider adapter knows only its wire format: it never touches our data. */
interface ChannelProvider
{
    /**
     * @return string the provider's own reference for the message (what its later reports are keyed by)
     *
     * @throws ChannelException
     */
    public function send(Communication $message, int $companyId): string;
}
