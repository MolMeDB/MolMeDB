<?php

namespace App\Console\Commands\Database;

use App\Models\Filesystem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

class BackupDb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:dump';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a whole-database backup and saves it to the config specified location.';

    public function datePath(?string $date = null): string
    {
        $timestamp = $date ? strtotime($date) : time();

        return date('Y', $timestamp).'/'.date('m', $timestamp).'/'.date('d', $timestamp).'/';
    }

    public function make_folder_structure($disk): void
    {
        if ($disk) {
            // Make basic directories if not exists
            $disk->makeDirectory($this->datePath());
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Making database backup...');

        $db = config('database.connections.pgsql.database');
        $user = config('database.connections.pgsql.username');
        $pass = config('database.connections.pgsql.password');
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');

        $filesystem = Filesystem::where('type', Filesystem::TYPE_DB_FULL_BACKUP)->first();

        if (! $filesystem) {
            $this->error('No backup filesystem configured! Aborting.');

            return Command::FAILURE;
        }

        if (! $filesystem->isDiskConnected()) {
            $this->error('Could not access backup filesystem "'.$filesystem->systemName.'"! Aborting.');

            return Command::FAILURE;
        }

        $disk = Storage::disk($filesystem->systemName);
        $this->make_folder_structure($disk);

        $date = date('Y-m-d');
        $targetFilename = $this->datePath($date).'backup-base-'.$date.'.sql.gz';
        $exportTable = 'ssh_credentials_export_'.strtolower(bin2hex(random_bytes(6)));
        $temporaryDirectory = TemporaryDirectory::make();
        $tmpFile = $temporaryDirectory->path('backup-base-'.$date.'.sql');
        $securedTmpFile = $temporaryDirectory->path('secured.sql');
        $compressedTmpFile = $tmpFile.'.gz';

        try {
            // ---------------------------------------------------------
            // 1) CREATE ANONYMIZATION TABLE
            // ---------------------------------------------------------
            DB::statement("DROP TABLE IF EXISTS {$exportTable}");

            DB::statement("
                CREATE TABLE {$exportTable} AS
                SELECT
                    id,
                    name,
                    username,
                    type,
                    NULL::text AS password,
                    NULL::text AS private_key,
                    NULL::text AS passphrase,
                    NULL AS created_by,
                    created_at,
                    updated_at
                FROM ssh_credentials
            ");

            $this->info('# Anonymization table created.');

            $this->runPgDump([
                'pg_dump',
                '-U',
                (string) $user,
                '-p',
                (string) $port,
                '-h',
                (string) $host,
                '--exclude-table-data=public.ssh_credentials',
                '--exclude-table=public.'.$exportTable,
                (string) $db,
            ], $tmpFile, (string) $pass);

            $this->runPgDump([
                'pg_dump',
                '-U',
                (string) $user,
                '-p',
                (string) $port,
                '-h',
                (string) $host,
                '--data-only',
                '--table=public.'.$exportTable,
                (string) $db,
            ], $securedTmpFile, (string) $pass);

            file_put_contents(
                $tmpFile,
                "\n\n".str_replace($exportTable, 'ssh_credentials', file_get_contents($securedTmpFile) ?: ''),
                FILE_APPEND
            );

            $this->info('# Dump created.');
            $this->info('# Archiving...');

            $this->gzipFile($tmpFile, $compressedTmpFile);

            $this->info('# Dump archived. Uploading...');

            if ($disk->exists($targetFilename)) {
                $disk->delete($targetFilename);
                $this->warn('# Old backup deleted.');
            }

            $stream = fopen($compressedTmpFile, 'rb');
            if (! $stream) {
                throw new \RuntimeException('Could not open compressed backup for upload.');
            }

            try {
                if (! $disk->put($targetFilename, $stream)) {
                    throw new \RuntimeException('Could not upload backup to target filesystem.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->info('Backup created and uploaded.');

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return Command::FAILURE;
        } finally {
            try {
                DB::statement("DROP TABLE IF EXISTS {$exportTable}");
            } catch (Throwable) {
                // Ignore cleanup failure.
            }

            $temporaryDirectory->delete();
        }
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runPgDump(array $arguments, string $outputFile, string $password): void
    {
        $stream = fopen($outputFile, 'wb');
        if (! $stream) {
            throw new \RuntimeException("Could not open temporary dump file [{$outputFile}].");
        }

        $stderr = '';

        try {
            // Started and awaited (not run) so that the output handler also
            // receives the output of a faked process; the password goes in the
            // environment, never on the command line.
            $result = Process::env(['PGPASSWORD' => $password])
                ->forever()
                ->start($arguments, function (string $type, string $buffer) use ($stream, &$stderr): void {
                    if ($type === SymfonyProcess::OUT) {
                        fwrite($stream, $buffer);

                        return;
                    }

                    $stderr .= $buffer;
                })
                ->wait();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $result->successful()) {
            throw new \RuntimeException(trim($stderr) ?: 'pg_dump failed.');
        }
    }

    private function gzipFile(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($target, 'wb9');

        if (! $input || ! $output) {
            throw new \RuntimeException('Could not create gzip archive.');
        }

        while (! feof($input)) {
            gzwrite($output, fread($input, 1024 * 1024));
        }

        fclose($input);
        gzclose($output);
    }
}
