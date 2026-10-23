<?php

namespace App\Modules\Integrations\Enums;

/** Where a provider connection stands, as the brief words it: configured, connected, degraded or disabled (plus "needs setup"). */
enum ConnectionStatus: string
{
    case Disabled = 'disabled';
    case NeedsSetup = 'needs_setup';
    case Configured = 'configured';
    case Connected = 'connected';
    case Degraded = 'degraded';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::NeedsSetup => 'Needs setup',
            self::Configured => 'Configured — nothing received yet',
            self::Connected => 'Connected',
            self::Degraded => 'Degraded',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Disabled => 'bg-slate-100 text-slate-600',
            self::NeedsSetup => 'bg-amber-100 text-amber-800',
            self::Configured => 'bg-sky-100 text-sky-800',
            self::Connected => 'bg-emerald-100 text-emerald-800',
            self::Degraded => 'bg-rose-100 text-rose-800',
        };
    }
}
