<?php

namespace App\Console\Commands;

use App\DTO\Stats\BarChart;
use App\DTO\Stats\Counts;
use App\DTO\Stats\LineChart;
use App\Models\Category;
use App\Models\Config;
use App\Models\Identifier;
use App\Models\InteractionActive;
use App\Models\InteractionPassive;
use App\Models\Membrane;
use App\Models\Method;
use App\Models\Protein;
use App\Models\Publication;
use App\Models\Stats;
use App\Models\Structure;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class UpdateStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:update-all {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates all statistics in the database and cache them to redis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating statistics...');

        $last_update = $this->option('force') ? Carbon::parse(0) : Carbon::parse(Config::get('command:stats:update-all:last-run', 0));

        if ($last_update->isCurrentWeek()) {
            $this->warn('Last update was less than a week ago. Skipping...');

            return Command::SUCCESS;
        }

        // Update counts
        $this->warn('... 1) Updating counts statistics');
        $counts = Counts::from([
            'total_passive_interactions' => InteractionPassive::count(),
            'total_active_interactions' => InteractionActive::count(),
            'total_structures' => Structure::count(),
            'total_membranes' => Membrane::count(),
            'total_methods' => Method::count(),
            'total_proteins' => Protein::count(),
        ]);
        Stats::setCountStats($counts);
        $this->info('... 1) Finished.');

        // Update interaction substance history
        $this->warn('... 2) Updating interaction substance chart counts');
        $minDate = Date::parse(Structure::min('created_at'))->addMonth()->startOfMonth();
        $maxDate = now()->startOfMonth();

        $totalMonths = (int) $minDate->diffInMonths($maxDate) + 1;
        // Aim for about 22 bars, but at least one month per step, so a history shorter than 22 months still works.
        $step = max(1, intdiv($totalMonths, 22));

        // Months from the first one up to the current month, never a future one.
        $steps = $totalMonths > 0 ? range(0, $totalMonths - 1, $step) : [];
        $data = [];

        foreach ($steps as $i) {
            $date = $minDate->copy()->addMonths($i);
            $this->info('... ## Processing date: '.$date->format('m/Y'));
            $data[] = LineChart::makeItem(
                $date->valueOf(),
                Structure::where('created_at', '<=', $date->endOfMonth())
                    ->count(),
                InteractionActive::where('created_at', '<=', $date->endOfMonth())
                    ->count()
                + InteractionPassive::where('created_at', '<=', $date->endOfMonth())
                    ->count()

            );
        }

        Stats::setInteractionSubstanceHistory(
            LineChart::from($data)
        );
        $this->info('... 2) Finished.');

        // Update databases bar counts
        $this->warn('... 3) Updating databases bar counts');
        $databases = [
            Identifier::TYPE_PUBCHEM => Identifier::enumType(Identifier::TYPE_PUBCHEM),
            Identifier::TYPE_DRUGBANK => Identifier::enumType(Identifier::TYPE_DRUGBANK),
            Identifier::TYPE_CHEBI => Identifier::enumType(Identifier::TYPE_CHEBI),
            Identifier::TYPE_PDB => Identifier::enumType(Identifier::TYPE_PDB),
            Identifier::TYPE_CHEMBL => Identifier::enumType(Identifier::TYPE_CHEMBL),
        ];

        $data = [];
        foreach ($databases as $type => $name) {
            $count = Identifier::where('type', $type)
                ->distinct()
                ->count('structure_id');
            $data[] = BarChart::makeItem(
                $name,
                $count
            );
        }

        Stats::setDatabasesBarData(
            BarChart::from($data)
        );
        $this->info('... 3) Finished.');

        // Update proteins bar counts
        $this->warn('... 4) Updating proteins bar counts');
        $proteinGroups = Category::where('type', Category::TYPE_PROTEIN)
            ->where('parent_id', -1)
            ->get();

        $data = [];
        foreach ($proteinGroups as $group) {
            $this->info('... ## Processing group: '.$group->title);
            $subcats = [$group->id];
            $last = 0;

            while (count($subcats) > $last) {
                $last = count($subcats);
                $subcats = array_unique(array_merge($subcats, Category::whereIn('parent_id', $subcats)
                    ->pluck('id')
                    ->toArray()));
            }

            $proteins = Protein::whereHas('categories', function ($query) use ($subcats) {
                $query->whereIn('category_id', $subcats);
            })
                ->get();

            $total_interactions = InteractionActive::whereIn('protein_id', $proteins->pluck('id'))
                ->count();

            $data[] = BarChart::makeItem(
                $group->title,
                $proteins->count(),
                $total_interactions
            );
        }

        Stats::setProteinBarData(
            BarChart::from($data)
        );
        $this->info('... 4) Finished.');

        // Update publication by year stats
        $this->warn('... 5) Updating publication by year stats');
        $minYear = Publication::min('year');
        $maxYear = date('Y');

        $data = [];

        // Without any publication year there is nothing to chart.
        $years = $minYear === null ? [] : range($minYear - ($minYear % 5), $maxYear, 5);

        foreach ($years as $year) {
            $this->info('... ## Processing year: '.$year);
            $data[] = LineChart::makeItem(
                "$year - ".$year + 4,
                InteractionActive::whereHas('publication', function ($query) use ($year) {
                    $query->whereBetween('year', [$year, $year + 4]);
                })->count()
                + InteractionPassive::whereHas('publication', function ($query) use ($year) {
                    $query->whereBetween('year', [$year, $year + 4]);
                })->count(),
                null
            );
        }

        Stats::setPublicationByYearStatsData(
            LineChart::from($data)
        );
        $this->info('... 5) Finished.');

        // Update publication by journal counts
        $this->warn('... 6) Updating publications by journal bar counts');

        $data = [];

        $journals = Publication::select('journal')
            ->whereNotNull('journal')
            ->distinct()
            ->get();

        foreach ($journals as $journal) {
            $this->info('... ## Processing journal: '.$journal->journal);
            $data[] = BarChart::makeItem(
                $journal->journal,
                InteractionActive::whereHas('publication', function ($query) use ($journal) {
                    $query->where('journal', $journal->journal);
                })->count()
                + InteractionPassive::whereHas('publication', function ($query) use ($journal) {
                    $query->where('journal', $journal->journal);
                })->count(),
            );
        }

        usort($data, function ($a, $b) {
            return $b->value1 <=> $a->value1;
        });

        $data = array_filter($data, function ($item) {
            return $item->value1 > 100;
        });

        Stats::setPublicationByJournalStatsData(
            BarChart::from($data)
        );

        $this->info('... 6) Finished.');

        // Only a finished run counts, so a failed one is retried the next day.
        if (! $this->option('force')) {
            Config::set('command:stats:update-all:last-run', Carbon::now());
        }

        return Command::SUCCESS;
    }
}
