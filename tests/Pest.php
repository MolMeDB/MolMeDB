<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ApiContract;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// Hooks must be chained here (not declared via separate top-level
// beforeEach()/afterEach() calls below) to actually apply to the Feature/
// module tests targeted by ->in(...) — a bare beforeEach()/afterEach() at
// this file's top level does not compose with an explicit ->in(...) target
// set; it silently never fires for the files matched here.
// $response->assertApiContract('GET api/v1/structures/{identifier}') — see Tests\Support\ApiContract.
TestResponse::macro('assertApiContract', function (string $route): TestResponse {
    /** @var TestResponse $this */
    ApiContract::assert($this, $route);

    return $this;
});

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        if (! gc_enabled()) {
            gc_enable();
        }

        // The PredictionWorkers module's migrations aren't in the default
        // database/migrations path, so RefreshDatabase's migrate:fresh
        // never creates their schema — normally it's only applied via
        // RunPredictionsMigrationsAfterDefaultMigrate, which fires on the
        // plain `migrate` command, not `migrate:fresh`. The in-memory
        // sqlite connection used for it in tests is rebuilt fresh (and
        // rolled back) per test, so this has to run every time.
        if (! Schema::connection('predictions')->hasTable('structures')) {
            Artisan::call('migrate', [
                '--database' => 'predictions',
                '--path' => 'modules/PredictionWorkers/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);
        }
    })
    ->afterEach(function () {
        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        gc_collect_cycles();

        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    })
    ->in(
        'Feature',
        '../modules/*/tests/Feature'
    );

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
