<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sitemapDirectory = storage_path('framework/testing/sitemaps-'.uniqid());
    config(['fair.sitemap_path' => $this->sitemapDirectory]);
});

afterEach(function () {
    File::deleteDirectory($this->sitemapDirectory);
});

test('the sitemap lists static pages and every kind of landing page on the public site', function () {
    createApiStructure(['identifier' => 'MM00040']);
    createApiStructure(['identifier' => null]);
    $membrane = createApiMembrane();
    $method = createApiMethod();
    $protein = createApiProtein();
    $publication = createApiPublication();

    $this->artisan('sitemap:generate')->assertSuccessful();

    $index = File::get("{$this->sitemapDirectory}/sitemap.xml");

    expect($index)
        ->toContain('<loc>https://molmedb.upol.cz/sitemap-pages.xml</loc>')
        ->toContain('<loc>https://molmedb.upol.cz/sitemap-structures-1.xml</loc>')
        ->toContain('<loc>https://molmedb.upol.cz/sitemap-publications-1.xml</loc>')
        ->not->toContain('admin.');

    expect(File::get("{$this->sitemapDirectory}/sitemap-pages.xml"))->toContain('<loc>https://molmedb.upol.cz/</loc>');

    $structures = File::get("{$this->sitemapDirectory}/sitemap-structures-1.xml");
    expect($structures)->toContain('<loc>https://molmedb.upol.cz/mol/MM00040</loc>')
        ->and(substr_count($structures, '<url>'))->toBe(1);

    expect(File::get("{$this->sitemapDirectory}/sitemap-membranes-1.xml"))->toContain("https://molmedb.upol.cz/membrane/{$membrane->id}")
        ->and(File::get("{$this->sitemapDirectory}/sitemap-methods-1.xml"))->toContain("https://molmedb.upol.cz/method/{$method->id}")
        ->and(File::get("{$this->sitemapDirectory}/sitemap-proteins-1.xml"))->toContain("https://molmedb.upol.cz/protein/{$protein->id}")
        ->and(File::get("{$this->sitemapDirectory}/sitemap-publications-1.xml"))->toContain("https://molmedb.upol.cz/publication/{$publication->id}");
});

test('files of an earlier run that are no longer needed are removed', function () {
    File::ensureDirectoryExists($this->sitemapDirectory);
    File::put("{$this->sitemapDirectory}/sitemap-structures-9.xml", 'stale');

    $this->artisan('sitemap:generate')->assertSuccessful();

    expect(File::exists("{$this->sitemapDirectory}/sitemap-structures-9.xml"))->toBeFalse()
        ->and(File::exists("{$this->sitemapDirectory}/sitemap.xml"))->toBeTrue();
});
