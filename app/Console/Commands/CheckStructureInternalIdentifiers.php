<?php

namespace App\Console\Commands;

use App\Libraries\Identifiers;
use App\Models\Config;
use App\Models\Structure;
use App\Services\Structures\LegacyStructureLinksPreprocessor;
use Illuminate\Console\Command;

class CheckStructureInternalIdentifiers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'structures:check-internal-identifiers {--startId=0} {--force} {--preprocess-legacy-links} {--ids=* : Process only selected structure IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Goes through all structures and checks for internal identifier.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('preprocess-legacy-links')) {
            $this->warn('Running legacy structure_links preprocessing...');

            $result = app(LegacyStructureLinksPreprocessor::class)->process();

            $this->table(['Metric', 'Value'], collect($result)
                ->map(fn (int $value, string $key): array => [$key, $value])
                ->values()
                ->all());

            $this->info('Legacy preprocessing finished. You can now validate data before removing structure_links.');

            return self::SUCCESS;
        }

        $ids = collect($this->option('ids'))
            ->flatMap(fn (string $id): array => explode(',', $id))
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isNotEmpty()) {
            $total = Structure::whereIn('id', $ids)->count();

            $this->info('Checking selected structures identifiers...');
            $this->warn('Total '.$total.' structures will be processed.');

            return $this->processStructures(
                Structure::whereIn('id', $ids)->orderBy('id')->cursor(),
                $total,
                false,
            );
        }

        $this->info('Checking structures identifiers...');
        if ($this->option('force')) {
            $startId = 1;
        } elseif (((int) $this->option('startId')) > 0) {
            $startId = (int) $this->option('startId');
        } else {
            $startId = (int) Config::get('cron:daily:check_structure_identifier:start_id');
        }

        $total = Structure::where('id', '>=', $startId)->count();

        if (! $total) {
            $startId = 1;
            Config::set('cron:daily:check_structure_identifier:start_id', $startId);
            $this->info('###### REWIND ##### - All structures processed');
            $total = Structure::where('id', '>=', $startId)->count();
        }

        $this->warn('Total '.$total.' structures will be processed.');

        return $this->processStructures(
            Structure::where('id', '>=', $startId)
                ->orderBy('id')
                ->cursor(),
            $total,
            true,
        );
    }

    private function processStructures(iterable $structures, int $total, bool $storeProgress): int
    {
        $i = 1;
        foreach ($structures as $structure) {
            $percent = round(($i++ / $total) * 100, 2);
            $this->info('# '.$percent.'% - Processing structure ID: '.$structure->id);

            if ($storeProgress) {
                Config::set('cron:daily:check_structure_identifier:start_id', $structure->id);
            }

            // At first, check parent identifier
            if ($structure->parent) {
                $identifier = Identifiers::generate($structure->parent);

                if (! $identifier) {
                    $this->error('Failed to generate identifier for parent structure ID: '.$structure->parent_id);

                    return self::FAILURE;
                }

                if ($identifier != $structure->parent->identifier) {
                    $structure->parent->changeMainIdentifier($identifier);
                    $this->warn('Parent identifier was changed for structure ID: '.$structure->id);
                }
            }

            // Reload structure record
            $structure->refresh();

            $identifier = Identifiers::generate($structure);

            if (! $identifier) {
                $this->error('Failed to generate identifier for structure ID: '.$structure->id);

                return self::FAILURE;
            }

            if ($identifier != $structure->identifier) {
                $structure->changeMainIdentifier($identifier);
                $this->warn('Identifier was changed for structure ID: '.$structure->id);

                continue;
            }

            $this->info('--- OK');
        }

        // A finished pass points past the last structure, so the next run starts over.
        if ($storeProgress && isset($structure)) {
            Config::set('cron:daily:check_structure_identifier:start_id', $structure->id + 1);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
