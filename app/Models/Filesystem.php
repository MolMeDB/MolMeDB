<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Throwable;

class Filesystem extends BaseModel
{
    use LogsActivity;

    /** Supported drivers */
    const DRIVER_SSH = 'ssh';

    const DRIVER_FTP = 'ftp';

    const DRIVER_SFTP = 'sftp';

    const DRIVER_LOCAL = 'local';

    /** Special service types */
    const TYPE_PUBLIC = -1;

    const TYPE_PRIVATE = -2;

    const TYPE_PREDICTIONS_METACENTRUM = 1;

    const TYPE_EXPORTS = 2;

    const TYPE_BACKUPS = 3;

    const TYPE_UPLOAD_STORAGE = 4;

    const TYPE_STRUCTURE_STORAGE = 5;

    const TYPE_PREDICTIONS_STORAGE = 6;

    const TYPE_RDF_STORAGE = 7;

    const TYPE_DB_FULL_BACKUP = 8;

    const TYPE_DB_PUBLIC_BACKUP = 9;

    const TYPE_DB_PREDICTIONS_BACKUP = 10;

    const TYPE_PREDICTIONS_COSMO_EXPORT = 11;

    public static $types = [
        self::TYPE_PUBLIC => 'Public',
        self::TYPE_PRIVATE => 'Private',
        self::TYPE_PREDICTIONS_METACENTRUM => 'Remote Metacentrum prediction service',
        self::TYPE_EXPORTS => 'Exports storage',
        self::TYPE_BACKUPS => 'Backups storage',
        self::TYPE_UPLOAD_STORAGE => 'Uploaded files storage',
        self::TYPE_STRUCTURE_STORAGE => 'Structures (sdf) storage',
        self::TYPE_PREDICTIONS_STORAGE => 'Prediction results storage',
        self::TYPE_RDF_STORAGE => 'RDF related-files storage',
        self::TYPE_DB_FULL_BACKUP => 'Database full backups',
        self::TYPE_DB_PUBLIC_BACKUP => 'Database public backups (restricted)',
        self::TYPE_DB_PREDICTIONS_BACKUP => 'Predictions database backups',
        self::TYPE_PREDICTIONS_COSMO_EXPORT => 'Prediction COSMO export (weekly dump)',
    ];

    public static function drivers(): array
    {
        return [
            self::DRIVER_SSH => self::DRIVER_SSH,
            self::DRIVER_FTP => self::DRIVER_FTP,
            self::DRIVER_SFTP => self::DRIVER_SFTP,
            self::DRIVER_LOCAL => self::DRIVER_LOCAL,
        ];
    }

    public static function types(): array
    {
        return self::$types;
    }

    public function sshCredential(): BelongsTo
    {
        return $this->belongsTo(SshCredential::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(self::class, 'scope_id');
    }

    public function isDiskConnected(): bool
    {
        try {
            $storage_disk = Storage::disk($this->systemName);
            $storage_disk->makeDirectory('');

            return $storage_disk?->exists('') ?? false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function isConfigured(): bool
    {
        if (filled($this->scope_id) && $this->scope?->isConfigured()) {
            return filled($this->root_path);
        }

        if (! $this->scope && $this->driver !== self::DRIVER_LOCAL) {
            return $this->sshCredential()->exists();
        }

        return $this->driver === self::DRIVER_LOCAL && filled($this->root_path);
    }

    public function isInitialized(): bool
    {
        try {
            if (! Config::has('filesystems.disks.'.$this->systemName)) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getSystemNameAttribute(): string
    {
        if ($this->type == self::TYPE_PRIVATE) {
            return 'private';
        }

        if ($this->type == self::TYPE_PUBLIC) {
            return 'public';
        }

        if ($this->type == self::TYPE_BACKUPS) {
            return 'backups';
        }

        return 'disk-'.$this->id;
    }

    public function getIsInitializedAttribute(): bool
    {
        return $this->isInitialized();
    }

    public function testConnection(): bool
    {
        try {
            $diskName = $this->systemName;

            Storage::forgetDisk($diskName);
            $disk = Storage::disk($diskName);

            $testFile = 'connection_test_'.uniqid().'.txt';

            $disk->put($testFile, 'Test connection at '.now());

            $exists = $disk->exists($testFile);

            if (! $exists) {
                throw new Exception('File does not exist.');
            }

            $disk->delete($testFile);

            return $exists;
        } catch (Throwable $e) {
            throw $e;

            return false;
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('system')
            ->logOnly([
                'type',
                'name',
                'description',
                'scope_id',
                'driver',
                'host',
                'port',
                'root_path',
                'ssh_credential_id',
                'sshCredential.name',
            ])
            ->dontSubmitEmptyLogs()
            ->logOnlyDirty();
    }
}
