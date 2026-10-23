<?php

namespace App\Modules\Shared\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar, worked out for one person from `config('formezy.navigation')`: only what exists and what they may open. The header search reads the same
 * list, so the menu and "go to" can never disagree. An item may name several permissions (`can` as a list: all are needed), route parameters (`params`),
 * and be `soon` (shown dimmed with a “Soon” tag, no link) for a module that is on the roadmap but not built.
 */
final class NavigationMenu
{
    /**
     * @return list<array{section: string, items: list<array{label: string, icon: string, url: string|null, active: bool, soon: bool}>}>
     */
    public function for(User $user, ?Request $request = null): array
    {
        $out = [];

        foreach ((array) config('formezy.navigation') as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                $soon = (bool) ($item['soon'] ?? false);

                if (! $soon && (empty($item['route']) || ! Route::has($item['route']))) {
                    continue;
                }

                if (! $this->allowed($user, $item['can'] ?? null)) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'], 'icon' => $item['icon'], 'soon' => $soon,
                    'url' => $soon ? null : route($item['route'], $item['params'] ?? []),
                    'active' => ! $soon && $request !== null && $this->active($request, $item),
                ];
            }

            if ($items !== []) {
                $out[] = ['section' => $section['section'], 'items' => $items];
            }
        }

        return $out;
    }

    /** @param  string|list<string>|null  $can */
    private function allowed(User $user, string|array|null $can): bool
    {
        foreach ((array) $can as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $item */
    private function active(Request $request, array $item): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $matches = $request->routeIs($item['route'].'*') || (isset($item['also']) && $request->routeIs($item['also']));

        foreach ((array) ($item['params'] ?? []) as $name => $value) {
            $matches = $matches && (string) $route->parameter($name) === (string) $value;
        }

        return $matches;
    }
}
