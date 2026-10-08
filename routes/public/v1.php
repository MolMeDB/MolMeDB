<?php

use App\Http\Controllers\Api\Public\V1\AboutController;
use App\Http\Controllers\Api\Public\V1\InteractionController;
use App\Http\Controllers\Api\Public\V1\MembraneController;
use App\Http\Controllers\Api\Public\V1\MethodController;
use App\Http\Controllers\Api\Public\V1\ProteinController;
use App\Http\Controllers\Api\Public\V1\PublicationController;
use App\Http\Controllers\Api\Public\V1\StructureController;
use Illuminate\Support\Facades\Route;

// Global HandleCors is skipped for this whole prefix (see AppServiceProvider),
// so this catch-all is needed for CORS preflight (OPTIONS) requests to actually
// reach our own PublicApiCors middleware instead of Laravel's implicit
// "Allow: GET,HEAD" 405-avoidance response, which carries no CORS headers.
Route::options('{any}', fn () => response()->noContent())->where('any', '.*');

Route::get('/about', [AboutController::class, 'index']);

Route::prefix('membranes')
    ->controller(MembraneController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/categories', 'categories');
        Route::get('/{membrane}', 'show');
        Route::get('/{membrane}/stats', 'stats');
        Route::get('/{membrane}/interactions', 'interactions');
        Route::get('/{membrane}/interactions/export', 'export');
    });

Route::prefix('methods')
    ->controller(MethodController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/categories', 'categories');
        Route::get('/{method}', 'show');
        Route::get('/{method}/stats', 'stats');
        Route::get('/{method}/interactions', 'interactions');
        Route::get('/{method}/interactions/export', 'export');
    });

Route::prefix('interactions')
    ->controller(InteractionController::class)
    ->group(function () {
        Route::get('/passive', 'indexPassive');
        Route::get('/passive/{interaction}', 'showPassive')->whereNumber('interaction');
        Route::get('/active', 'indexActive');
        Route::get('/active/{interaction}', 'showActive')->whereNumber('interaction');
    });

Route::prefix('structures')
    ->controller(StructureController::class)
    ->group(function () {
        Route::get('/{identifier}/stats', 'stats');
        Route::get('/{identifier}/molfile', 'molfile');
        Route::get('/{identifier}/interactions/passive', 'interactionsPassive');
        Route::get('/{identifier}/interactions/active', 'interactionsActive');
        Route::get('/{identifier}', 'show');
        Route::get('/', 'index')->middleware('throttle:public-api-substructure');
    });

Route::prefix('publications')
    ->controller(PublicationController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/{publication}', 'show');
        Route::get('/{publication}/stats', 'stats');
        Route::get('/{publication}/interactions/passive', 'interactionsPassive');
        Route::get('/{publication}/interactions/active', 'interactionsActive');
        Route::get('/{publication}/interactions/passive/export', 'exportPassive');
        Route::get('/{publication}/interactions/active/export', 'exportActive');
    });

Route::prefix('proteins')
    ->controller(ProteinController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/categories', 'categories');
        Route::get('/{protein}', 'show');
        Route::get('/{protein}/stats', 'stats');
        Route::get('/{protein}/interactions', 'interactions');
    });
