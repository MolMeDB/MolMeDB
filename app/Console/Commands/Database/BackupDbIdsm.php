<?php

namespace App\Console\Commands\Database;

use App\Models\Config;
use App\Models\Filesystem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDbIdsm extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:dump-idsm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a safe database backup for IDSM and saves it to the config specified location.';

    /**
     * The dump is downloadable by anyone (/api/dump/idsm/download), so it
     * holds only these tables of published data. A table not listed here is
     * left out; tests make every table of the schema either listed here or
     * in PRIVATE_TABLES, so a new table needs a decision.
     *
     * @var array<int, string>
     */
    public const PUBLIC_TABLES = [
        'public.categories',
        'public.datasets',
        'public.fluorescent_properties',
        'public.identifiers',
        'public.interactions_active',
        'public.interactions_passive',
        'public.keywords',
        'public.membranes',
        'public.methods',
        'public.model_has_categories',
        'public.model_has_publications',
        'public.protein_identifiers',
        'public.proteins',
        'public.publications',
        'public.structure_links',
        'public.structures',
    ];

    /**
     * Tables that never go into the public dump: accounts, contact data,
     * notifications, queues, credentials, internal bookkeeping and the Bingo
     * search index (rebuilt on import).
     *
     * @var array<int, string>
     */
    public const PRIVATE_TABLES = [
        'bingo.bingo_config',
        'bingo.bingo_tau_config',
        'public.activity_log',
        'public.application_errors',
        'public.authors',
        'public.cache',
        'public.cache_locks',
        'public.configs',
        'public.dataset_groups',
        'public.document_articles',
        'public.download_queue',
        'public.failed_jobs',
        'public.feedback_email_verifications',
        'public.feedback_submissions',
        'public.files',
        'public.filesystems',
        'public.job_batches',
        'public.jobs',
        'public.migrations',
        'public.model_has_files',
        'public.model_has_permissions',
        'public.model_has_roles',
        'public.notification_preferences',
        'public.notification_templates',
        'public.password_reset_tokens',
        'public.permissions',
        'public.personal_access_tokens',
        'public.publication_has_authors',
        'public.push_subscriptions',
        'public.queued_notifications',
        'public.role_has_permissions',
        'public.roles',
        'public.sessions',
        'public.ssh_credentials',
        'public.stats',
        'public.structures_canonical_smiles_bingo_index_shadow',
        'public.structures_canonical_smiles_bingo_index_shadow_hash',
        'public.upload_queue',
        'public.user_notifications',
        'public.users',
    ];

    /**
     * pg_dump options selecting the public tables by their exact names (owned
     * sequences come with them).
     *
     * @return array<int, string>
     */
    public static function tableArguments(): array
    {
        return array_map(fn (string $table): string => '--table='.$table, self::PUBLIC_TABLES);
    }

    /**
     * Makes pg_dump's output of the public tables restorable into an empty
     * database: a dump of selected tables has no extensions, so the citext
     * extension of the identifier columns is created first; the Bingo index
     * (an access method of the private bingo schema) and foreign keys to
     * tables left out of the dump are dropped, and so is a setting that
     * servers older than the pg_dump that made the dump reject.
     *
     * pg_dump starts every object with a comment header such as
     * "-- Name: x; Type: FK CONSTRAINT; Schema: public; Owner: y", so whole
     * objects are kept or dropped. Only indexes and foreign keys are held in
     * memory; table data streams through.
     *
     * @param  iterable<int, string>  $lines  pg_dump output, line by line with line endings
     * @return \Generator<int, string>
     */
    public static function restorableDump(iterable $lines): \Generator
    {
        yield "CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;\n";

        $heldBlock = null;
        $heldType = null;
        $separator = null;

        foreach ($lines as $line) {
            if ($separator !== null) {
                if (preg_match('/^-- (?:Data for )?Name: .*; Type: ([^;]+);/', $line, $match) === 1) {
                    if ($heldBlock !== null && self::keepsDumpObject((string) $heldType, $heldBlock)) {
                        yield $heldBlock;
                    }

                    $heldBlock = null;
                    $heldType = null;

                    if (in_array($match[1], ['INDEX', 'FK CONSTRAINT'], true)) {
                        $heldType = $match[1];
                        $heldBlock = $separator.$line;
                        $separator = null;

                        continue;
                    }
                }

                $held = $separator;
                $separator = null;

                if ($heldBlock !== null) {
                    $heldBlock .= $held;
                } else {
                    yield $held;
                }
            }

            // Set by pg_dump 17+ only; servers before 17 reject it.
            if (str_starts_with($line, 'SET transaction_timeout')) {
                continue;
            }

            if (rtrim($line, "\r\n") === '--') {
                $separator = $line;

                continue;
            }

            if ($heldBlock !== null) {
                $heldBlock .= $line;
            } else {
                yield $line;
            }
        }

        if ($heldBlock !== null && self::keepsDumpObject((string) $heldType, $heldBlock)) {
            yield $heldBlock;
        }

        if ($separator !== null) {
            yield $separator;
        }
    }

    private static function keepsDumpObject(string $type, string $block): bool
    {
        if ($type === 'INDEX') {
            return ! str_contains($block, 'USING bingo_idx');
        }

        if (preg_match('/REFERENCES\s+("?[\w]+"?)\.("?[\w]+"?)\s*\(/', $block, $match) !== 1) {
            return true;
        }

        return in_array(str_replace('"', '', $match[1]).'.'.str_replace('"', '', $match[2]), self::PUBLIC_TABLES, true);
    }

    public static function datePath(?string $date = null): string
    {
        $timestamp = $date ? strtotime($date) : time();

        return date('Y', $timestamp).'/'.date('m', $timestamp).'/'.date('d', $timestamp).'/';
    }

    public function make_folder_structure($disk): void
    {
        if ($disk) {
            // Make basic directories if not exists
            $disk->makeDirectory(self::datePath());
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $lastBackup = Config::get('db_backup_idsm_last');

        // From the last backup to now: Carbon 3 returns a signed difference, so
        // now()->diffInDays($past) is negative and the dump was always skipped.
        if ($lastBackup && Carbon::parse($lastBackup)->diffInDays(now()) < 7) {
            $this->info('Last IDSM backup was made at '.$lastBackup.', skipping (younger than 7 days).');

            return Command::SUCCESS;
        }

        $this->info('Making IDSM database backup...');

        $db = config('database.connections.pgsql.database');
        $user = config('database.connections.pgsql.username');
        $pass = config('database.connections.pgsql.password');
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');

        $filesystem = Filesystem::where('type', Filesystem::TYPE_DB_PUBLIC_BACKUP)->first();

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
        $targetFilename = self::datePath($date).'backup-idsm-'.$date.'.sql.gz';
        $temporaryDirectory = TemporaryDirectory::make();
        $tmpFile = $temporaryDirectory->path('backup-idsm-'.$date.'.sql');
        $compressedTmpFile = $tmpFile.'.gz';

        try {
            $arguments = [
                'pg_dump',
                '-U',
                (string) $user,
                '-p',
                (string) $port,
                '-h',
                (string) $host,
            ];

            $arguments = [...$arguments, ...self::tableArguments(), (string) $db];

            $this->runPgDump($arguments, $tmpFile, (string) $pass);

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

            Config::set('db_backup_idsm_last', date('Y-m-d H:i:s'));

            $this->info('Backup created and uploaded.');

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return Command::FAILURE;
        } finally {
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
        $process = new Process($arguments, null, ['PGPASSWORD' => $password]);

        try {
            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer) use ($stream, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($stream, $buffer);

                    return;
                }

                $stderr .= $buffer;
            });
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($stderr) ?: 'pg_dump failed.');
        }
    }

    /**
     * Compresses the dump, made restorable on the way.
     */
    private function gzipFile(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($target, 'wb9');

        if (! $input || ! $output) {
            throw new \RuntimeException('Could not create gzip archive.');
        }

        $lines = (function () use ($input): \Generator {
            while (($line = fgets($input)) !== false) {
                yield $line;
            }
        })();

        foreach (self::restorableDump($lines) as $chunk) {
            gzwrite($output, $chunk);
        }

        fclose($input);
        gzclose($output);
    }
}
