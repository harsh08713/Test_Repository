<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Services\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The only way a paper on an employee file leaves the private disk: the person must be allowed to see that file and its sensitive parts, each time. */
class EmployeeDocumentController extends Controller
{
    public function __invoke(Request $request, EmployeeDocument $document, EmployeeAccess $access): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $employee = Employee::query()->findOrFail($document->employee_id);   // tenant and branch scopes: another company's paper is a 404
        Gate::forUser($user)->authorize('view', $employee);
        abort_unless($access->seesSensitive($user, $employee), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
