<?php

namespace App\Console\Commands;

use App\Models\DocumentArticle;
use App\Services\Documentation\DocumentationRenderer;
use App\Services\Documentation\DocumentationSource;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes the documentation articles kept in resources/docs (those that
 * describe the code: the API, the MCP server, data upload, predictions) to
 * the documentation pages. Runs on every deployment; articles written in the
 * administration are left alone.
 */
class SyncDocumentation extends Command
{
    /**
     * Top-level articles a dry run would create, so their children are
     * reported as created too.
     *
     * @var array<int, string>
     */
    private array $plannedTopLevel = [];

    protected $signature = 'docs:sync
        {--dry-run : Only render the articles and report what would change}
        {--directory= : Directory with the articles (default: resources/docs)}';

    protected $description = 'Publishes the documentation articles from resources/docs.';

    public function handle(DocumentationRenderer $renderer): int
    {
        try {
            $sources = DocumentationSource::all($this->option('directory') ?: null);
            $rendered = collect($sources)->mapWithKeys(fn (DocumentationSource $source): array => [$source->path => $renderer->render($source)]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($sources as $source) {
            $rows[] = [$source->fullSlug(), $source->path, $this->sync($source, $rendered[$source->path])];
        }

        foreach ($this->staleArticles($sources) as $article) {
            if (! $this->option('dry-run')) {
                $article->update(['is_published' => false]);
            }

            $rows[] = [$article->fullSlug(), $article->source, 'unpublished (source removed)'];
        }

        $this->table(['Article', 'Source', 'Result'], $rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was saved.');
        }

        return self::SUCCESS;
    }

    private function sync(DocumentationSource $source, string $content): string
    {
        $parentId = null;

        if ($source->parentSlug !== null) {
            $parent = DocumentArticle::query()->whereNull('parent_id')->where('slug', $source->parentSlug)->first();

            if (! $parent) {
                return in_array($source->parentSlug, $this->plannedTopLevel, true)
                    ? 'created'
                    : "skipped: no top-level article \"{$source->parentSlug}\"";
            }

            $parentId = $parent->id;
        }

        $article = DocumentArticle::query()
            ->where('parent_id', $parentId)
            ->where('slug', $source->slug)
            ->first();

        $attributes = [
            'title' => $source->title,
            'content' => $content,
            'is_published' => $source->published,
            'source' => $source->path,
        ];

        if ($source->position !== null) {
            $attributes['position'] = $source->position;
        }

        if (! $article) {
            if ($parentId === null) {
                $this->plannedTopLevel[] = $source->slug;
            }

            if (! $this->option('dry-run')) {
                DocumentArticle::create([
                    'parent_id' => $parentId,
                    'slug' => $source->slug,
                    'position' => $source->position ?? (int) DocumentArticle::query()->where('parent_id', $parentId)->max('position') + 1,
                    ...$attributes,
                ]);
            }

            return 'created';
        }

        $result = $article->isManaged() ? 'updated' : 'taken over from the administration';
        $article->fill($attributes);

        if (! $article->isDirty()) {
            return 'unchanged';
        }

        if (! $this->option('dry-run')) {
            $article->save();
        }

        return $result;
    }

    /**
     * Published articles generated from a file that no longer exists.
     *
     * @param  array<int, DocumentationSource>  $sources
     * @return iterable<int, DocumentArticle>
     */
    private function staleArticles(array $sources): iterable
    {
        return DocumentArticle::query()
            ->whereNotNull('source')
            ->whereNotIn('source', collect($sources)->pluck('path'))
            ->where('is_published', true)
            ->get();
    }
}
