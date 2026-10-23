<?php

namespace App\Modules\Analytics\Http\Middleware;

use App\Models\User;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\AnalyticsPages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /analytics/{page}?format=json` answers with the page's data as JSON (for the charts, and for anything else that wants the numbers); without it the
 * request goes on to the screen. The same rules apply as for the screen: a person who may not open the page gets 403, an unknown page 404, and the filter is
 * re-read and re-checked (AnalyticsFilter), never trusted. The answer is never cached.
 */
class AnswerAnalyticsJson
{
    public function __construct(private readonly AnalyticsPages $pages) {}

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if ($request->query('format') !== 'json') {
            return $next($request);
        }

        $user = $request->user();
        $page = (string) $request->route('page');

        abort_unless($user instanceof User && $this->pages->exists($page), 404);
        abort_unless($this->pages->allowed($user, $page), 403);

        $data = $this->pages->data($user, $page, AnalyticsFilter::fromArray($request->query()), ['sort' => $request->query('sort'), 'granularity' => $request->query('granularity')]);

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
