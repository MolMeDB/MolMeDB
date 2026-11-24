<?php

require_once __DIR__.'/prediction_helpers.php';

use App\Models\Config;
use App\Models\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Switches the remote prediction service on with a valid stored token.
 */
function enableRemotePredictionService(): void
{
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '1');
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, 'valid-token');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, now()->addDays(10)->toIso8601String());
    config()->set('prediction-workers.remote.retry_delays', [0]);
    config()->set('prediction-workers.remote.manager_secret', 'manager-secret');
}

/**
 * One Http fake routing by URL prefix, matched in the given order; anything
 * else answers 404 so unexpected requests fail loudly.
 *
 * @param  array<string, mixed>  $routes  URL prefix => response or closure(Request) returning one.
 */
function fakePredictionHttpRoutes(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        foreach ($routes as $prefix => $response) {
            if (str_starts_with($request->url(), $prefix)) {
                return $response instanceof Closure ? $response($request) : $response;
            }
        }

        return Http::response(['detail' => "Unexpected request to {$request->url()}"], 404);
    });
}

/**
 * Fakes the disk of the predictions storage filesystem and returns its name.
 */
function fakePredictionStorageDisk(): string
{
    $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_PREDICTIONS_STORAGE)->firstOrFail();
    config()->set("filesystems.disks.{$filesystem->systemName}", ['driver' => 'local']);
    Storage::fake($filesystem->systemName);

    return $filesystem->systemName;
}

/**
 * @param  array<string, string>  $entries  archive path => contents
 */
function predictionZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'prediction-test-zip-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $archive->addFromString($name, $contents);
    }

    $archive->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return $contents;
}

function cosmoResultXml(float $logPerm = -4.5): string
{
    return '<micoutput><joblist><job><jobnumber>1</jobnumber><symmetry>planar</symmetry><nrlayer>1</nrlayer><layerposition>0.0</layerposition><temperature>298.15</temperature><solutelist><solute><meanposition>1.5</meanposition><logP_micelle_water>2.75</logP_micelle_water><logPerm_membrane_cm_s>'.$logPerm.'</logPerm_membrane_cm_s><distribution>0.5</distribution></solute></solutelist></job></joblist></micoutput>';
}
