<?php

use App\Http\Controllers\Export;
use App\Http\Controllers\Export\ExportStructureController;
use App\Models\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\Models\PredictionFile;

Route::prefix('/export')->group(function () {
    Route::get('/upload-queue/raw/{record}', [Export\ExportUploadQueueController::class, 'raw'])
        ->middleware(['throttle:60,1'])
        ->name('export.upload-queue.raw');

    Route::get('/upload-queue/{record}', [Export\ExportUploadQueueController::class, 'index'])
        ->middleware(['auth', 'throttle:60,1'])
        ->name('export.upload-queue');
});

Route::get('/download/public/{hash}', function (string $hash) {
    $file = File::where('hash', $hash)->first();
    if (! $file || ! $file->isPubliclyDownloadable() || ! Storage::disk($file->storage)->exists($file->path)) {
        abort(404);
    }

    $disk = Storage::disk($file->storage);

    return response()->streamDownload(
        function () use ($disk, $file) {
            echo $disk->get($file->path);
        },
        $file->downloadName(),
        [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]
    );
})->middleware('throttle:60,1')
    ->withoutMiddleware('auth')
    ->name('public.download');

Route::prefix('/api/dump')->group(function () {
    Route::get('/idsm/info', [Export\ExportIdsmController::class, 'info'])
        ->middleware(['throttle:60,1'])
        ->name('export.dump.idsm.info');

    Route::get('/idsm/download', [Export\ExportIdsmController::class, 'download'])
        ->middleware(['throttle:10,1'])
        ->name('export.dump.idsm.download');
})
    ->withoutMiddleware('auth');

Route::get('/download/prediction/{hash}', function (string $hash) {

    $file = PredictionFile::where('hash', $hash)->first();

    if (! $file || ! Storage::disk($file->storage)->exists($file->path)) {
        abort(404);
    }

    $disk = Storage::disk($file->storage);

    return response()->streamDownload(
        function () use ($disk, $file) {
            echo $disk->get($file->path);
        },
        $file->downloadName()
    );
})->middleware('throttle:60,1')
    ->withoutMiddleware('auth')
    ->name('public.download-prediction');

Route::get('/download/predictionResult/{hash}', function (string $hash) {
    $file = PredictionFile::where('hash', $hash)->first();

    if (! $file || ! Storage::disk($file->storage)->exists($file->path)) {
        abort(404);
    }

    $disk = Storage::disk($file->storage);

    return response()->streamDownload(function () use ($disk, $file) {
        $stream = $disk->readStream($file->path);
        fpassthru($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }, $file->downloadName());
})
    ->middleware('throttle:60,1')
    ->withoutMiddleware('auth')
    ->name('predictionResult.download');

Route::prefix('export')
    ->group(function () {
        Route::prefix('/structure/{record}')
            ->controller(ExportStructureController::class)
            ->group(function () {
                Route::get('/passiveInteractions', 'passiveInteractions');
                Route::get('/activeInteractions', 'activeInteractions');
            });
    });
