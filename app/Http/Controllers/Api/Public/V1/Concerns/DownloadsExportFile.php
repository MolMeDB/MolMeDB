<?php

namespace App\Http\Controllers\Api\Public\V1\Concerns;

use App\Libraries\Export\ExportLicenseFiles;
use App\Models\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the latest pre-generated (daily cron) export file attached to a
 * membrane/method, instead of building the interaction listing on demand.
 */
trait DownloadsExportFile
{
    protected function downloadLatestExport(Model $owner, int $fileType): StreamedResponse
    {
        $file = $owner->files()->where('type', $fileType)->first();

        abort_unless($file, 404, 'Export file is not available yet.');

        $filesystem = Filesystem::where('type', Filesystem::TYPE_EXPORTS)->first();

        abort_unless($filesystem && $filesystem->isInitialized(), 404, 'Export storage is not available.');

        $disk = Storage::disk($filesystem->systemName);

        abort_unless($disk->exists($file->path), 404, 'Export file is not available yet.');

        return $disk->download($file->path, $file->downloadName(), ['Link' => ExportLicenseFiles::linkHeader()]);
    }
}
