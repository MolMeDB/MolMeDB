<?php

use App\Enums\RoleEnums;
use App\Filament\Resources\DocumentArticles\Pages\CreateDocumentArticle;
use App\Filament\Resources\DocumentArticles\Pages\EditDocumentArticle;
use App\Models\DocumentArticle;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * An article can be linked to its Markdown file in resources/docs (here
 * the fixtures in tests/Fixtures/docs) and published from it, or switched
 * back to manual editing.
 */

beforeEach(function () {
    config()->set('documentation.directory', base_path('tests/Fixtures/docs'));

    $admin = User::factory()->create();
    $admin->syncRoles([Role::query()->firstOrCreate(['name' => RoleEnums::ADMIN->value, 'guard_name' => 'web'])]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin->refresh());
});

test('an article switched to its source file is published from it on save', function () {
    $article = DocumentArticle::create(['slug' => 'guide', 'title' => 'Draft', 'content' => '<p>Draft</p>']);

    Livewire::test(EditDocumentArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['synced_from_source' => true, 'source' => 'https://github.com/MolMeDB/MolMeDB/blob/main/resources/docs/guide.md.blade.php'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->refresh())
        ->source->toBe('guide.md.blade.php')
        ->isManaged()->toBeTrue()
        ->title->toBe('Guide')
        ->content->toContain('docs-infobox--warning')
        ->and($article->sourceUrl())->toBe('https://github.com/MolMeDB/MolMeDB/blob/main/resources/docs/guide.md.blade.php');
});

test('an article switched off can be edited by hand', function () {
    $article = DocumentArticle::create(['slug' => 'guide', 'title' => 'Guide', 'content' => '<p>Generated</p>', 'source' => 'guide.md.blade.php', 'synced_from_source' => true]);

    Livewire::test(EditDocumentArticle::class, ['record' => $article->getRouteKey()])
        ->assertFormFieldIsDisabled('content')
        ->fillForm(['synced_from_source' => false])
        ->assertFormFieldIsEnabled('content')
        ->fillForm(['title' => 'Guide edited', 'content' => '<p>Edited by hand</p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->refresh())
        ->isManaged()->toBeFalse()
        ->source->toBe('guide.md.blade.php')
        ->title->toBe('Guide edited')
        ->content->toContain('Edited by hand');
});

test('the source must be a file in resources/docs', function () {
    $article = DocumentArticle::create(['slug' => 'guide', 'title' => 'Guide', 'content' => '<p>Text</p>']);

    Livewire::test(EditDocumentArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['synced_from_source' => true, 'source' => 'missing.md.blade.php'])
        ->call('save')
        ->assertHasFormErrors(['source']);
});

test('a linked article is published again from the page header', function () {
    $article = DocumentArticle::create(['slug' => 'guide', 'title' => 'Guide', 'content' => '<p>Stale</p>', 'source' => 'guide.md.blade.php', 'synced_from_source' => true]);

    Livewire::test(EditDocumentArticle::class, ['record' => $article->getRouteKey()])
        ->callAction('publishFromRepository');

    expect($article->refresh()->content)->toContain('<em>From a partial</em>');
});

test('a new article can be created from its source file', function () {
    Livewire::test(CreateDocumentArticle::class)
        ->fillForm(['title' => 'Anything', 'slug' => 'guide', 'position' => 1, 'synced_from_source' => true, 'source' => 'guide.md.blade.php'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DocumentArticle::where('slug', 'guide')->firstOrFail())
        ->title->toBe('Guide')
        ->isManaged()->toBeTrue();
});
