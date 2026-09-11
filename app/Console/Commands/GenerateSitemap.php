<?php

namespace App\Console\Commands;

use App\Models\Structure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Tags\Url;

/**
 * Regenerates public/sitemap.xml — the only place FAIR-relevant per-entity
 * landing pages (/mol/{identifier}) plus the static browse pages are listed
 * for search engines. Written straight into public/, the same way
 * public/robots.txt is served, so no extra route/controller is needed.
 *
 * MolMeDB has ~500k structures, well past the sitemap protocol's 50,000
 * URLs-per-file limit, so structures are split across numbered
 * sitemap-structures-N.xml files referenced from a top-level sitemap.xml
 * index — building one giant in-memory Sitemap for all of them (the naive
 * approach) also exhausted the default 128M CLI memory limit in testing.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Regenerates the sitemap.xml listing structure landing pages and static browse pages.';

    private const STATIC_PAGES = [
        '/', '/browse/membranes', '/browse/methods', '/browse/proteins',
    ];

    private const MAX_URLS_PER_FILE = 50000;

    public function handle(): int
    {
        // ~500k structures: Postgres' PDO driver buffers the full result set
        // client-side regardless of Eloquent's cursor(), so this still
        // exhausts the default 128M CLI memory_limit even though PHP-side
        // model hydration is lazy. Bulk cron commands here run isolated
        // (see RunDailyCommands), so raising it just for this process is safe.
        ini_set('memory_limit', '512M');

        $frontendUrl = rtrim(config('fair.frontend_url'), '/');

        $this->clearPreviousFiles();

        $index = SitemapIndex::create()->add($this->writePagesSitemap($frontendUrl));

        $structureCount = 0;
        $fileNumber = 1;
        $sitemap = Sitemap::create();

        foreach (Structure::query()
            ->whereNotNull('identifier')
            ->select(['identifier', 'updated_at'])
            ->orderBy('id')
            ->cursor() as $structure) {
            $sitemap->add(
                Url::create("{$frontendUrl}/mol/{$structure->identifier}")
                    ->setLastModificationDate($structure->updated_at ?? now())
                    ->setPriority(0.8)
            );
            $structureCount++;

            if (count($sitemap->getTags()) >= self::MAX_URLS_PER_FILE) {
                $index->add($this->writeStructureSitemap($sitemap, $fileNumber++));
                $sitemap = Sitemap::create();
            }
        }

        if (count($sitemap->getTags()) > 0) {
            $index->add($this->writeStructureSitemap($sitemap, $fileNumber));
        }

        $index->writeToFile(public_path('sitemap.xml'));

        $this->info("Sitemap index written to public/sitemap.xml ({$structureCount} structures).");

        return self::SUCCESS;
    }

    private function writePagesSitemap(string $frontendUrl): string
    {
        $sitemap = Sitemap::create();

        foreach (self::STATIC_PAGES as $path) {
            $sitemap->add(Url::create($frontendUrl.$path)->setPriority($path === '/' ? 1.0 : 0.5));
        }

        $sitemap->writeToFile(public_path('sitemap-pages.xml'));

        return url('/sitemap-pages.xml');
    }

    private function writeStructureSitemap(Sitemap $sitemap, int $fileNumber): string
    {
        $filename = "sitemap-structures-{$fileNumber}.xml";
        $sitemap->writeToFile(public_path($filename));

        return url("/{$filename}");
    }

    private function clearPreviousFiles(): void
    {
        foreach (glob(public_path('sitemap-structures-*.xml')) ?: [] as $path) {
            File::delete($path);
        }
    }
}
