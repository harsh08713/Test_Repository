<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Models\User;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAttachment;
use App\Modules\Workspace\Services\TaskAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over a file of a task to someone who may open the task — as a download, never to be shown in the page (so a stored file can never run as
 * a script or a page of ours), with the browser told not to guess its type.
 */
class TaskAttachmentController
{
    public function __invoke(Request $request, Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        Gate::authorize('view', $task);

        abort_unless((int) $attachment->task_id === (int) $task->getKey() && $attachment->isPresent(), 404);
        abort_unless(Storage::disk(TaskAttachmentService::DISK)->exists($attachment->path), 404);

        return Storage::disk(TaskAttachmentService::DISK)->download($attachment->path, $attachment->name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
