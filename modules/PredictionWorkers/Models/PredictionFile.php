<?php

namespace Modules\PredictionWorkers\Models;

use App\Models\File;
use finfo;
use Illuminate\Support\Facades\File as FacadesFile;
use Illuminate\Support\Facades\Storage;

class PredictionFile extends PredictionBaseModel
{
    protected $connection = 'predictions';

    protected $table = 'files';

    const TYPE_MEMBARNE_COSMO = 1;

    const TYPE_RESULT_COSMO_XML = 2;

    const TYPE_RESULT_ARCHIVE = 3;

    const TYPE_COSMO_CONFORMERS = 4;

    protected static function booted()
    {
        static::saving(function ($file) {
            if (! $file->mime) {
                $realPath = null;
                if (Storage::disk($file->storage)->exists($file->path)) {
                    $realPath = Storage::disk($file->storage)->path($file->path);
                } else {
                    throw new \Exception('File does not exist.');
                }

                switch ($file->storage) {
                    case 'private':
                        $file->mime = FacadesFile::mimeType($realPath);
                        break;
                    case 'public':
                        $file->mime = FacadesFile::mimeType($realPath);
                        break;
                    default:
                        $contents = Storage::disk($file->storage)->get($file->path);
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $file->mime = $finfo->buffer($contents);
                        break;
                }
            }

            if (! $file->hash) {
                $file->hash = File::hash($file->path, $file->storage);
            }
        });
    }

    public function name()
    {
        $name = ! empty($this->name) ? $this->name : pathinfo($this->path, PATHINFO_FILENAME);
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        if (str_ends_with($name, '.'.$extension)) {
            return $name;
        }

        return "$name.$extension";
    }

    public function downloadName(): string
    {
        return $this->name();
    }

    public function exists(): bool
    {
        return filled($this->storage)
            && filled($this->path)
            && Storage::disk($this->storage)->exists($this->path);
    }
}
