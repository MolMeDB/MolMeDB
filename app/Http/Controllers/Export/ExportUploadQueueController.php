<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Models\UploadQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportUploadQueueController extends Controller
{
    /**
     * Export raw uploaded file from the queue
     */
    public function raw(Request $request, UploadQueue $record)
    {
        $guestToken = $request->query('guest_token');
        $guestToken = is_string($guestToken) && trim($guestToken) !== '' ? trim($guestToken) : null;

        // Authorize before looking at the file, so a stranger cannot tell
        // which upload queue records exist or still have their file.
        if (! $record->isAccessibleBy($request->user(), $guestToken)) {
            abort(401, 'Unauthenticated');
        }

        $file = $record->file;

        if (! $file || ! Storage::disk($file->storage)->exists($file->path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($file->storage)->download($file->path);
    }

    /**
     * Export uploaded file from the queue - parsed
     */
    public function index(UploadQueue $record)
    {
        // TODO
        abort(404, 'Not implemented');

        // $file = $record->file;

        // if(!$file || !$file->existsOnDisk('private'))
        // {
        //     abort(404, 'File not found');
        // }

        // return Storage::disk('private')->download($file->path);
    }
}
