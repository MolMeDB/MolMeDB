<?php

namespace App\Services\Documentation;

use App\Models\DocumentArticle;
use InvalidArgumentException;

/**
 * Publishes documentation sources (resources/docs) to the articles of the
 * documentation pages, for `php artisan docs:sync` and the administration.
 *
 * An article belongs to a source by its "source" path. Articles published
 * from their source ("synced_from_source") get the rendered file; turning
 * that off in the administration keeps the article as it is edited there.
 * A source without an article takes over the article at its place (same
 * parent and slug) if that one has no source yet, or creates a new one.
 */
class DocumentationPublisher
{
    public function __construct(private readonly DocumentationRenderer $renderer) {}

    /**
     * @return array<int, array{article: string, source: string, result: string}>
     */
    public function publishAll(?string $directory = null, bool $dryRun = false): array
    {
        $sources = DocumentationSource::all($directory);
        $rendered = array_map(fn (DocumentationSource $source): string => $this->renderer->render($source), $sources);

        $plannedTopLevel = [];
        $rows = [];

        foreach ($sources as $index => $source) {
            $result = $this->publish($source, $rendered[$index], $dryRun, $plannedTopLevel);
            $rows[] = ['article' => $source->fullSlug(), 'source' => $source->path, 'result' => $result];
        }

        $paths = array_map(fn (DocumentationSource $source): string => $source->path, $sources);

        foreach ($this->staleArticles($paths) as $article) {
            if (! $dryRun) {
                $article->update(['is_published' => false]);
            }

            $rows[] = ['article' => $article->fullSlug(), 'source' => $article->source, 'result' => 'unpublished (source removed)'];
        }

        return $rows;
    }

    /**
     * Publishes the source of one article now (an action of the administration).
     */
    public function publishArticle(DocumentArticle $article): void
    {
        $source = $this->find((string) $article->source)
            ?? throw new InvalidArgumentException("There is no documentation source {$article->source}.");

        $article->forceFill($this->attributes($source, $this->renderer->render($source)))->save();
    }

    /**
     * The source with the path, or null.
     */
    public function find(string $path, ?string $directory = null): ?DocumentationSource
    {
        return collect(DocumentationSource::all($directory))->first(fn (DocumentationSource $source): bool => $source->path === $path);
    }

    /**
     * Paths of all sources, e.g. for choosing one in the administration.
     *
     * @return array<int, string>
     */
    public function paths(?string $directory = null): array
    {
        return array_map(fn (DocumentationSource $source): string => $source->path, DocumentationSource::all($directory));
    }

    /**
     * @param  array<int, string>  $plannedTopLevel  top-level articles a dry run would create
     */
    private function publish(DocumentationSource $source, string $content, bool $dryRun, array &$plannedTopLevel): string
    {
        $article = DocumentArticle::query()->where('source', $source->path)->first();

        if ($article && ! $article->synced_from_source) {
            return 'skipped: edited in the administration';
        }

        if ($article) {
            return $this->save($article, $this->attributes($source, $content), 'updated', $dryRun);
        }

        $parentId = null;

        if ($source->parentSlug !== null) {
            $parent = DocumentArticle::query()->whereNull('parent_id')->where('slug', $source->parentSlug)->first();

            if (! $parent) {
                return in_array($source->parentSlug, $plannedTopLevel, true)
                    ? 'created'
                    : "skipped: no top-level article \"{$source->parentSlug}\"";
            }

            $parentId = $parent->id;
        }

        $article = DocumentArticle::query()->where('parent_id', $parentId)->where('slug', $source->slug)->first();

        if ($article && $article->source !== null) {
            return "skipped: \"{$source->fullSlug()}\" belongs to {$article->source}";
        }

        if ($article) {
            return $this->save($article, $this->attributes($source, $content), 'taken over from the administration', $dryRun);
        }

        if ($parentId === null) {
            $plannedTopLevel[] = $source->slug;
        }

        if (! $dryRun) {
            DocumentArticle::create([
                'parent_id' => $parentId,
                'slug' => $source->slug,
                'position' => $source->position ?? (int) DocumentArticle::query()->where('parent_id', $parentId)->max('position') + 1,
                ...$this->attributes($source, $content),
            ]);
        }

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(DocumentArticle $article, array $attributes, string $result, bool $dryRun): string
    {
        $article->fill($attributes);

        if (! $article->isDirty()) {
            return 'unchanged';
        }

        if (! $dryRun) {
            $article->save();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(DocumentationSource $source, string $content): array
    {
        return [
            'title' => $source->title,
            'content' => $content,
            'is_published' => $source->published,
            'source' => $source->path,
            'synced_from_source' => true,
            ...($source->position !== null ? ['position' => $source->position] : []),
        ];
    }

    /**
     * Published articles of a source file that no longer exists.
     *
     * @param  array<int, string>  $paths
     * @return iterable<int, DocumentArticle>
     */
    private function staleArticles(array $paths): iterable
    {
        return DocumentArticle::query()
            ->where('synced_from_source', true)
            ->whereNotNull('source')
            ->whereNotIn('source', $paths)
            ->where('is_published', true)
            ->get();
    }
}
