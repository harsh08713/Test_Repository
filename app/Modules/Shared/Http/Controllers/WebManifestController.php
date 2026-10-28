<?php

namespace App\Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The web app manifest: what a phone or tablet needs to install FormEzy as an app (name, icons, where it opens, how it looks).
 *
 * It is public on purpose — a browser fetches it without a session — so it holds nothing about a company, a user or a record: only
 * the product name from the configuration, fixed icons and a few starting points. A starting point the person has no right to
 * simply answers "not allowed" like any other page; the menu inside the app is what follows permissions.
 */
class WebManifestController
{
    /** Where the icon files are made: public/icons (see docs/pwa.md). */
    private const ICONS = [
        ['src' => 'icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');
        $icons = array_map(fn (array $icon) => ['src' => url($icon['src'])] + $icon, self::ICONS);

        $shortcut = fn (string $label, string $path) => [
            'name' => $label,
            'url' => url($path),
            'icons' => [['src' => url('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']],
        ];

        return response()->json([
            'id' => url('/'),
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Masters, CRM and marketing for the plant, the office and the field.',
            'start_url' => url('/dashboard').'?source=pwa',
            'scope' => url('/'),
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'any',
            'background_color' => '#1f252b',
            'theme_color' => '#1f252b',
            'lang' => 'en',
            'categories' => ['business', 'productivity'],
            'icons' => $icons,
            'shortcuts' => [
                $shortcut('Leads', '/crm/leads'),
                $shortcut('Follow-ups', '/crm/follow-ups'),
                $shortcut('Attendance', '/field/attendance'),
                $shortcut('Approvals', '/approvals'),
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
