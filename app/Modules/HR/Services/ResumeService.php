<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Candidate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** A candidate's résumé: one file on the private disk, kept by HR (`recruitment.edit`); a new file replaces the old one, and it leaves the disk only through CandidateResumeController. */
final class ResumeService
{
    public const MAX_KB = 5120;

    public const EXTENSIONS = ['pdf', 'doc', 'docx'];

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function attach(Candidate $candidate, UploadedFile $file, User $actor): Candidate
    {
        $this->need($actor);

        if (! in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true) || $file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['resume' => 'Upload a PDF or Word file of at most '.(self::MAX_KB / 1024).' MB.']);
        }

        $old = $candidate->getAttribute('resume_path');
        $path = $file->store('hr/'.$candidate->company_id.'/candidates/'.$candidate->id, 'local');

        $candidate->forceFill([
            'resume_path' => $path, 'resume_size' => (int) $file->getSize(),
            'resume_name' => mb_substr(str_replace(['/', '\\'], '', preg_replace('/[\x00-\x1F\x7F]/', '', $file->getClientOriginalName()) ?? 'resume'), 0, 200),
        ])->save();

        if (is_string($old) && $old !== '' && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $candidate;
    }

    /** @throws AuthorizationException */
    public function remove(Candidate $candidate, User $actor): Candidate
    {
        $this->need($actor);

        $old = $candidate->getAttribute('resume_path');
        $candidate->forceFill(['resume_path' => null, 'resume_name' => null, 'resume_size' => null])->save();

        if (is_string($old) && $old !== '') {
            Storage::disk('local')->delete($old);
        }

        return $candidate;
    }

    private function need(User $actor): void
    {
        if (! $actor->can('recruitment.edit')) {
            throw new AuthorizationException('You may not do this in recruitment.');
        }
    }
}
