<?php

namespace App\Console\Commands;

use App\Models\Membrane;
use App\Models\Method;
use App\Models\Protein;
use App\Models\Publication;
use App\Models\Structure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Tags\Url;

/**
 * Regenerates the sitemap of the public site: static pages and the landing
 * pages of molecules (/mol/{identifier}), membranes, methods, proteins and
 * publications. All URLs, including the sitemap files listed in the index,
 * are on the public site (fair.frontend_url).
 *
 * Files are written to fair.sitemap_path (storage/app/sitemaps, mounted into
 * the nginx container, which serves /sitemap*.xml from there); the public
 * site forwards /sitemap*.xml to the backend.
 *
 * MolMeDB has ~500k structures, well past the sitemap protocol's 50,000
 * URLs-per-file limit, so each kind of page is split into numbered files
 * referenced from the sitemap.xml index. The files hold 10,000 URLs each:
 * spatie/laravel-sitemap renders a whole file in memory, and a full 50,000
 * URL file needs more than 256 MB.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Regenerates the sitemap of the public site (static pages and entity landing pages).';

    private const STATIC_PAGES = [
        '/', '/browse/membranes', '/browse/methods', '/browse/proteins', '/browse/datasets', '/stats', '/docs',
    ];

    private const MAX_URLS_PER_FILE = 10000;

    private const CHUNK_SIZE = 5000;

    private string $directory;

    private string $siteUrl;

    /**
     * @var array<int, string>
     */
    private array $writtenFiles = [];

    public function handle(): int
    {
        $this->directory = rtrim((string) config('fair.sitemap_path'), '/');
        $this->siteUrl = (string) config('fair.frontend_url');

        File::ensureDirectoryExists($this->directory);

        $index = SitemapIndex::create();

        $pages = Sitemap::create();
        foreach (self::STATIC_PAGES as $path) {
            $pages->add(Url::create($this->siteUrl.$path)->setPriority($path === '/' ? 1.0 : 0.5));
        }
        $index->add($this->write($pages, 'sitemap-pages.xml'));

        $counts = [
            'structures' => $this->writeEntitySitemaps($index, 'structures', Structure::query()->whereNotNull('identifier'), fn (Structure $structure) => "/mol/{$structure->identifier}", ['id', 'identifier', 'updated_at'], 0.8),
            'membranes' => $this->writeEntitySitemaps($index, 'membranes', Membrane::query(), fn (Membrane $membrane) => "/membrane/{$membrane->id}", ['id', 'updated_at'], 0.6),
            'methods' => $this->writeEntitySitemaps($index, 'methods', Method::query(), fn (Method $method) => "/method/{$method->id}", ['id', 'updated_at'], 0.6),
            'proteins' => $this->writeEntitySitemaps($index, 'proteins', Protein::query(), fn (Protein $protein) => "/protein/{$protein->id}", ['id', 'updated_at'], 0.6),
            'publications' => $this->writeEntitySitemaps($index, 'publications', Publication::query(), fn (Publication $publication) => "/publication/{$publication->id}", ['id', 'updated_at'], 0.5),
        ];

        $index->writeToFile("{$this->directory}/sitemap.xml");
        $this->writtenFiles[] = 'sitemap.xml';

        $this->removeStaleFiles();

        $this->info('Sitemap written to '.$this->directory.'/sitemap.xml ('.collect($counts)->map(fn (int $count, string $kind) => "{$count} {$kind}")->implode(', ').').');

        return self::SUCCESS;
    }

    /**
     * @param  callable(mixed): string  $path
     * @param  array<int, string>  $columns
     */
    private function writeEntitySitemaps(SitemapIndex $index, string $kind, Builder $query, callable $path, array $columns, float $priority): int
    {
        $count = 0;
        $fileNumber = 1;
        $sitemap = Sitemap::create();

        // lazyById() runs one query per chunk; cursor() would make the pgsql
        // driver buffer all ~500k rows in memory at once.
        foreach ($query->select($columns)->lazyById(self::CHUNK_SIZE) as $model) {
            $sitemap->add(
                Url::create($this->siteUrl.$path($model))
                    ->setLastModificationDate($model->updated_at ?? now())
                    ->setPriority($priority)
            );
            $count++;

            if (count($sitemap->getTags()) >= self::MAX_URLS_PER_FILE) {
                $index->add($this->write($sitemap, "sitemap-{$kind}-{$fileNumber}.xml"));
                $fileNumber++;
                $sitemap = Sitemap::create();
            }
        }

        if (count($sitemap->getTags()) > 0) {
            $index->add($this->write($sitemap, "sitemap-{$kind}-{$fileNumber}.xml"));
        }

        return $count;
    }

    private function write(Sitemap $sitemap, string $filename): string
    {
        $sitemap->writeToFile("{$this->directory}/{$filename}");
        $this->writtenFiles[] = $filename;

        return "{$this->siteUrl}/{$filename}";
    }

    /**
     * Files of an earlier run that this run did not write again (e.g. when the
     * number of structure files went down).
     */
    private function removeStaleFiles(): void
    {
        foreach (glob("{$this->directory}/sitemap*.xml") ?: [] as $file) {
            if (! in_array(basename($file), $this->writtenFiles, true)) {
                File::delete($file);
            }
        }
    }
}
