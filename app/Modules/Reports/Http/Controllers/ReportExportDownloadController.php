<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Models\User;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over a finished export. Checked now, not when it was asked for: it is the requester's own, in the company they are working in, not
 * past its time, and they may still export that report. Anyone else gets a 404 (an export is not announced to a person it is not for).
 */
class ReportExportDownloadController
{
    public function __invoke(Request $request, ReportExport $export, ReportCatalog $catalog): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless((int) $export->user_id === (int) $user->getKey(), 404);

        $report = $catalog->find($export->report);
        abort_unless($report !== null && $report->canExport($user), 403);
        abort_unless($export->isReady() && Storage::disk(ReportExporter::DISK)->exists((string) $export->file_path), 404);

        $export->forceFill(['downloaded_at' => now()])->save();

        $name = Str::slug($report->key()).'-'.$export->created_at->format('Y-m-d').'.'.$export->format;

        return Storage::disk(ReportExporter::DISK)->download((string) $export->file_path, $name, [
            'Content-Type' => $export->format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
