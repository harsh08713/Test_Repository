<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files on a task, on the private disk. Only a task that is still to be done takes a file, a task holds a few, and only the kinds of file that
 * are documents and pictures are accepted (by the file's real content as well as its name). A file is never served from a public path: it is
 * handed over by a controller that checks the person may open the task.
 */
final class TaskAttachmentService
{
    public const DISK = 'local';

    public const MAX_KB = 10240;

    public const MAX_FILES = 10;

    /** Extension => the MIME types that file really is. */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/x-ole-storage'],
        'doc' => ['application/msword', 'application/octet-stream', 'application/x-ole-storage'],
    ];

    /** @throws FieldRuleException */
    public function attach(Task $task, UploadedFile $file, User $actor): TaskAttachment
    {
        if (! $task->isLive()) {
            throw new FieldRuleException('This task is '.strtolower($task->status->label()).'; no more files can be added.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::TYPES)) {
            throw new FieldRuleException('That kind of file is not accepted. Use a PDF, a picture, a text or CSV file, or a Word, Excel or PowerPoint document.');
        }

        if (($file->getSize() ?: 0) > self::MAX_KB * 1024) {
            throw new FieldRuleException('The file is larger than '.(self::MAX_KB / 1024).' MB.');
        }

        $mime = (string) $file->getMimeType();

        if (! in_array($mime, self::TYPES[$extension], true)) {
            throw new FieldRuleException('The file’s content does not match its name (it looks like “'.$mime.'”), so it was not kept.');
        }

        return DB::transaction(function () use ($task, $file, $actor, $extension, $mime) {
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if (TaskAttachment::query()->where('task_id', $locked->getKey())->whereNull('removed_at')->count() >= self::MAX_FILES) {
                throw new FieldRuleException('A task holds at most '.self::MAX_FILES.' files. Remove one first.');
            }

            $path = "task-attachments/{$locked->company_id}/{$locked->getKey()}/".Str::uuid()->toString().".{$extension}";
            Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

            $row = new TaskAttachment;
            $row->forceFill([
                'company_id' => $locked->company_id, 'task_id' => $locked->getKey(), 'name' => $this->cleanName($file->getClientOriginalName(), $extension), 'path' => $path,
                'mime' => $mime, 'size' => (int) $file->getSize(), 'uploaded_by' => $actor->getKey(),
            ])->save();

            activity('tasks')->causedBy($actor)->performedOn($locked)->event('attached')->withProperties(['file' => $row->name, 'size' => $row->size])->log('File added to a task');

            return $row;
        });
    }

    /** @throws FieldRuleException */
    public function remove(TaskAttachment $attachment, User $actor): TaskAttachment
    {
        return DB::transaction(function () use ($attachment, $actor) {
            $fresh = TaskAttachment::query()->whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isPresent()) {
                throw new FieldRuleException('That file has already been removed.');
            }

            $task = Task::query()->findOrFail($fresh->task_id);

            if (! $task->isLive()) {
                throw new FieldRuleException('This task is '.strtolower($task->status->label()).'; its files are kept as they are.');
            }

            Storage::disk(self::DISK)->delete($fresh->path);
            $fresh->forceFill(['removed_at' => now(), 'removed_by' => $actor->getKey()])->save();
            activity('tasks')->causedBy($actor)->performedOn($task)->event('detached')->withProperties(['file' => $fresh->name])->log('File removed from a task');

            return $fresh;
        });
    }

    /** What a person typed as a file name, made safe to print and to put in a header: no path, no control characters, a sensible length. */
    private function cleanName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename($base)));
        $base = mb_substr($base === '' ? 'file' : $base, 0, 120);

        return "{$base}.{$extension}";
    }
}
