<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Filesystem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionFile;
use Modules\PredictionWorkers\Models\PredictionMembrane;

class UpdateFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:remap-old';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Goes through all files....';

    private function new_filesystem($path)
    {
        if (preg_match('/^media\/files\/rdf/', $path)) {
            return Filesystem::where('type', Filesystem::TYPE_RDF_STORAGE)->first();
        }

        if (preg_match('/^media\/files\/membranes\/\d+\/cosmo/', $path)) {
            return Filesystem::where('type', Filesystem::TYPE_UPLOAD_STORAGE)->first();
        }

        if (preg_match('/^media\/files\/datasets/', $path)) {
            return Filesystem::where('type', Filesystem::TYPE_UPLOAD_STORAGE)->first();
        }
    }

    /**
     * Execute the console command.
     *
     * @deprecated - Not needed anymore
     */
    public function handle()
    {
        $q = File::whereNull('hash')->cursor();

        foreach ($q as $file) {
            $new_filesystem = $this->new_filesystem($file->path);
            if (! $new_filesystem) {
                $this->warn('No filesystem for '.$file->path);

                continue;
            }

            if ($new_filesystem->type == Filesystem::TYPE_RDF_STORAGE) {
                $new_path = str_replace('media/files/rdf/', '', $file->path);

                if (! Storage::disk($new_filesystem->systemName)->exists($new_path)) {
                    $this->warn('File '.Storage::disk($new_filesystem->systemName)->path($new_path).' does not exist in new storage '.$new_filesystem->name.'. Skipping.');

                    continue;
                }

                $file->path = $new_path;
                $file->storage = $new_filesystem->systemName;
                $file->save();
            } elseif (preg_match('/^media\/files\/membranes\/(\d+)\/cosmo/', $file->path, $matches)) {
                // Check if membrane exists
                $mem = PredictionMembrane::where('remote_id', (int) $matches[1])->first();

                if (! $mem) {
                    $this->warn('No membrane for '.$file->path.'. Skipping.');

                    continue;
                }

                // Does file exists in a new storage?
                $new_path = str_replace('media/files/membranes/', 'Membranes/', $file->path);

                if (! Storage::disk($new_filesystem->systemName)->exists($new_path)) {
                    $this->warn('File '.Storage::disk($new_filesystem->systemName)->path($new_path).' does not exist in new storage '.$new_filesystem->name.'. Skipping.');

                    continue;
                }

                // Move to the prediction DB
                $new_file = PredictionFile::firstOrCreate([
                    'type' => PredictionFile::TYPE_MEMBARNE_COSMO,
                    'name' => $file->name,
                    'mime' => $file->mime,
                    'storage' => $new_filesystem->systemName,
                    'path' => $new_path,
                ]);

                $file->forceDelete();
            } elseif (preg_match('/^media\/files\/datasets/', $file->path)) {
                $new_path = str_replace('media/files/datasets/', 'Datasets/', $file->path);

                if (! Storage::disk($new_filesystem->systemName)->exists($new_path)) {
                    $this->warn('File '.Storage::disk($new_filesystem->systemName)->path($new_path).' does not exist in new storage '.$new_filesystem->name.'. Skipping.');

                    continue;
                }

                $file->path = $new_path;
                $file->storage = $new_filesystem->systemName;
                $file->save();
            }
        }
    }
}
