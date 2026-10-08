<?php

use App\Models\DocumentArticle;

/*
 * docs:sync publishes the articles of a documentation directory: here the
 * fixtures in tests/Fixtures/docs ("guide" and its child "first-steps").
 */

const DOCS_FIXTURES = 'tests/Fixtures/docs';

function syncFixtureDocumentation(): void
{
    test()->artisan('docs:sync', ['--directory' => base_path(DOCS_FIXTURES)])->assertSuccessful();
}

test('articles are rendered from Blade and Markdown and published', function () {
    syncFixtureDocumentation();

    $guide = DocumentArticle::where('slug', 'guide')->whereNull('parent_id')->firstOrFail();
    $child = DocumentArticle::where('slug', 'first-steps')->firstOrFail();

    expect($guide->title)->toBe('Guide')
        ->and($guide->source)->toBe('guide.md.blade.php')
        ->and($guide->is_published)->toBeTrue()
        ->and($guide->content)
        ->toContain('<a href="https://molmedb.upol.cz/api/v1/about">')
        ->toContain('<pre class="docs-code-block language-bash" data-language="bash"><code>curl https://molmedb.upol.cz/api/v1/about</code></pre>')
        ->toContain('<div class="docs-infobox docs-infobox--warning">')
        ->toContain('<em>From a partial</em>')
        ->and($child->parent_id)->toBe($guide->id)
        ->and($child->position)->toBe(3)
        ->and($child->content)->toContain('<td>logperm</td>')->toContain('<td>log10(cm/s)</td>');
});

test('the documentation page serves a generated article as it was rendered', function () {
    syncFixtureDocumentation();

    $this->getJson('/api/docs/article/guide/first-steps')
        ->assertOk()
        ->assertJsonPath('data.title', 'First steps')
        ->assertJsonPath('data.content', DocumentArticle::where('slug', 'first-steps')->value('content'));
});

test('a second sync changes nothing', function () {
    syncFixtureDocumentation();
    $updatedAt = DocumentArticle::where('slug', 'guide')->value('updated_at');

    $this->travel(1)->hours();

    $this->artisan('docs:sync', ['--directory' => base_path(DOCS_FIXTURES)])
        ->expectsOutputToContain('unchanged')
        ->assertSuccessful();

    expect(DocumentArticle::where('slug', 'guide')->value('updated_at'))->toEqual($updatedAt);
});

test('an article written in the administration is taken over and keeps its position', function () {
    $article = DocumentArticle::create(['slug' => 'guide', 'title' => 'Old guide', 'content' => '<p>Old</p>', 'position' => 7]);

    $this->artisan('docs:sync', ['--directory' => base_path(DOCS_FIXTURES)])
        ->expectsOutputToContain('taken over from the administration')
        ->assertSuccessful();

    expect($article->refresh())
        ->title->toBe('Guide')
        ->position->toBe(7)
        ->isManaged()->toBeTrue();
});

test('an article whose source was removed is unpublished', function () {
    $stale = DocumentArticle::create(['slug' => 'removed', 'title' => 'Removed', 'content' => '<p>Gone</p>', 'source' => 'removed.md.blade.php', 'synced_from_source' => true]);
    $written = DocumentArticle::create(['slug' => 'about', 'title' => 'About', 'content' => '<p>Kept</p>']);

    syncFixtureDocumentation();

    expect($stale->refresh()->is_published)->toBeFalse()
        ->and($written->refresh()->is_published)->toBeTrue();
});

test('an article switched to manual editing in the administration is left alone', function () {
    syncFixtureDocumentation();
    $guide = DocumentArticle::where('slug', 'guide')->firstOrFail();
    $guide->update(['synced_from_source' => false, 'content' => '<p>Edited by hand</p>']);

    $this->artisan('docs:sync', ['--directory' => base_path(DOCS_FIXTURES)])
        ->expectsOutputToContain('skipped: edited in the administration')
        ->assertSuccessful();

    expect($guide->refresh())
        ->content->toBe('<p>Edited by hand</p>')
        ->source->toBe('guide.md.blade.php')
        ->isManaged()->toBeFalse();
});

test('a source linked to an article elsewhere updates that article', function () {
    $article = DocumentArticle::create(['slug' => 'handbook', 'title' => 'Handbook', 'content' => '<p>Old</p>', 'source' => 'guide.md.blade.php', 'synced_from_source' => true]);

    syncFixtureDocumentation();

    expect($article->refresh())->title->toBe('Guide')->slug->toBe('handbook')
        ->and(DocumentArticle::where('slug', 'guide')->exists())->toBeFalse();
});

test('a dry run saves nothing', function () {
    $this->artisan('docs:sync', ['--directory' => base_path(DOCS_FIXTURES), '--dry-run' => true])
        ->expectsOutputToContain('created')
        ->assertSuccessful();

    expect(DocumentArticle::count())->toBe(0);
});
