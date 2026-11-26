<?php

namespace App\Console\Commands;

use App\Services\Structures\CanonicalSmilesConversion;
use App\Services\Structures\CanonicalSmilesConversionPlan;
use Illuminate\Console\Command;
use Modules\Rdkit\Rdkit;
use Throwable;

/**
 * Converts the canonical SMILES stored in structures and in the structures of
 * the predictions database to the form the RDKit service writes now (RDKit
 * canonical SMILES in the Kekulé form, which Bingo reads), and rebuilds the
 * Bingo index. Run it once after the RDKit service starts writing a new form,
 * with --dry-run first; running it again changes nothing.
 */
class ConvertCanonicalSmiles extends Command
{
    protected $signature = 'structures:convert-canonical-smiles
        {--dry-run : Only report what would change}
        {--only= : Convert only "structures" or only "predictions"}
        {--chunk=1000 : Records per RDKit batch and per update}
        {--report= : Write the records left as they are (collisions, unreadable) to this CSV file}
        {--skip-bingo-check : Do not count the records Bingo rejects before and after}
        {--skip-reindex : Do not rebuild the Bingo index of structures}';

    protected $description = 'Converts stored canonical SMILES to the current form of the RDKit service (Kekulé, readable by Bingo) and reports the records that would collide.';

    public function handle(): int
    {
        $only = $this->option('only');
        $targets = match ($only) {
            null, '' => [CanonicalSmilesConversion::TARGET_STRUCTURES, CanonicalSmilesConversion::TARGET_PREDICTIONS],
            CanonicalSmilesConversion::TARGET_STRUCTURES, CanonicalSmilesConversion::TARGET_PREDICTIONS => [$only],
            default => null,
        };

        if ($targets === null) {
            $this->error('--only takes "structures" or "predictions".');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $report = [];

        $this->info(($dryRun ? 'Dry run: nothing will be changed. ' : '').'Canonical SMILES form: '.Rdkit::CANONICAL_SMILES_FORM.'.');

        foreach ($targets as $target) {
            $conversion = app(CanonicalSmilesConversion::class);

            try {
                $plan = $this->scan($conversion, $target, $chunk);
            } catch (Throwable $e) {
                $this->error("{$target}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->printPlan($plan);

            if (! $this->option('skip-bingo-check')) {
                $this->printBingoReadability($conversion);
            }

            $report = [...$report, ...$this->reportRows($plan)];

            if ($dryRun || $plan->convertible === 0) {
                continue;
            }

            $started = microtime(true);
            $bar = $this->output->createProgressBar($plan->convertible);
            $result = $conversion->apply($chunk, fn (int $done) => $bar->setProgress($done));
            $bar->finish();
            $this->newLine();

            $this->info(sprintf('%s: %d records converted in %d s.', $target, $result['converted'], microtime(true) - $started));

            if ($result['changed_meanwhile'] > 0) {
                $this->warn("{$result['changed_meanwhile']} records were changed by someone else since they were read and are left as they are: run the command again.");
            }

            if ($result['collided_meanwhile'] > 0) {
                $this->warn("{$result['collided_meanwhile']} records collide with records written since the scan and are left as they are: run the command again to see them.");
            }

            if ($target === CanonicalSmilesConversion::TARGET_STRUCTURES && ! $this->option('skip-reindex') && $result['converted'] > 0) {
                $this->rebuildIndex($conversion);
            }
        }

        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $report);
        }

        return self::SUCCESS;
    }

    private function scan(CanonicalSmilesConversion $conversion, string $target, int $chunk): CanonicalSmilesConversionPlan
    {
        $this->newLine();
        $this->line("<comment>{$target}</comment>: reading the records...");

        $started = microtime(true);
        $bar = null;

        $plan = $conversion->scan($target, $chunk, function (int $read, int $total) use (&$bar): void {
            $bar ??= $this->output->createProgressBar($total);
            $bar->setProgress($read);
        });

        $bar?->finish();
        $this->newLine();
        $this->line(sprintf('Read %d records in %d s.', $plan->scanned, microtime(true) - $started));

        return $plan;
    }

    private function printPlan(CanonicalSmilesConversionPlan $plan): void
    {
        $this->table(['', 'records'], [
            ['already in the canonical form', $plan->unchanged],
            ['to convert', $plan->convertible],
            ['unreadable by RDKit (left as they are)', $plan->unreadableCount],
            ['colliding (left as they are)', $plan->collidingRecords().' in '.count($plan->collisions).' groups'],
        ]);

        if ($plan->unreadable !== []) {
            $this->warn('RDKit cannot read these records:');
            $this->table(['id', 'stored SMILES'], array_map(fn (array $row): array => [$row['id'], $row['stored']], $plan->unreadable));
        }

        if ($plan->collisions === []) {
            return;
        }

        $this->warn('These records would hold the same canonical SMILES. They are not converted; decide which one stays:');

        foreach ($plan->collisions as $group) {
            $this->line("  <info>{$group['form']}</info>");
            $this->table(
                ['id', 'identifier', 'stored SMILES', 'deleted'],
                array_map(fn (array $row): array => [$row['id'], $row['identifier'] ?? '', $row['stored'], $row['deleted'] ? 'yes' : ''], $group['records']),
            );

            if ($plan->target === CanonicalSmilesConversion::TARGET_STRUCTURES) {
                $ids = array_column($group['records'], 'id');
                $keep = array_shift($ids);

                foreach ($ids as $other) {
                    $this->line("    e.g. php artisan structures:join-records {$keep} {$other}");
                }
            }
        }
    }

    private function printBingoReadability(CanonicalSmilesConversion $conversion): void
    {
        $readability = $conversion->bingoReadability();

        if ($readability === null) {
            return;
        }

        $this->line(sprintf(
            'Bingo rejects %d of the records as they are stored and would reject %d after the conversion.',
            $readability['rejected_now'],
            $readability['rejected_after'],
        ));

        if ($readability['still_rejected'] !== []) {
            $this->table(['id', 'SMILES', 'Bingo error'], array_map(
                fn (array $row): array => [$row['id'], $row['smiles'], $row['error']],
                $readability['still_rejected'],
            ));
        }
    }

    private function rebuildIndex(CanonicalSmilesConversion $conversion): void
    {
        $this->line('Rebuilding the Bingo index of structures (structure queries wait until it is done)...');
        $started = microtime(true);

        if ($conversion->rebuildIndex()) {
            $this->info(sprintf('Index %s rebuilt in %d s.', CanonicalSmilesConversion::BINGO_INDEX, microtime(true) - $started));
        } else {
            $this->line('There is no Bingo index to rebuild on this database.');
        }
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function reportRows(CanonicalSmilesConversionPlan $plan): array
    {
        $rows = [];

        foreach ($plan->collisions as $group) {
            foreach ($group['records'] as $record) {
                $rows[] = [$plan->target, 'collision', $record['id'], $record['identifier'] ?? '', $record['stored'], $group['form'], $record['deleted'] ? 'yes' : ''];
            }
        }

        foreach ($plan->unreadable as $record) {
            $rows[] = [$plan->target, 'unreadable', $record['id'], '', $record['stored'], '', ''];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string|int>>  $rows
     */
    private function writeReport(string $path, array $rows): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("Cannot write the report to {$path}.");

            return;
        }

        fputcsv($handle, ['database', 'reason', 'id', 'identifier', 'stored_smiles', 'canonical_smiles', 'deleted'], escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        fclose($handle);
        $this->info("Report written to {$path}.");
    }
}
