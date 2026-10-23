<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The only way a résumé leaves the private disk: the person must be allowed to see candidates (HR), each time; another company's candidate is a 404. */
class CandidateResumeController extends Controller
{
    public function __invoke(Request $request, Candidate $candidate): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('view', $candidate);

        $path = $candidate->getAttribute('resume_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, (string) ($candidate->getAttribute('resume_name') ?: 'resume'), ['X-Content-Type-Options' => 'nosniff']);
    }
}
