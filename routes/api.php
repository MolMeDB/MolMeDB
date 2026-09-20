<?php

use App\Http\Controllers\InteractionActiveController;
use App\Http\Controllers\InteractionPassiveController;
use App\Http\Controllers\MembraneController;
use App\Http\Controllers\MethodController;
use App\Http\Controllers\ProteinController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/../modules/References/EuropePMC/Routes/api.php';

Route::middleware('auth:sanctum')
    ->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);

        Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);
    });

Route::get('test', function () {
    return response()->json(['message' => 'OK'], 200);
});

Route::prefix('interactions')
    ->group(function () {
        Route::prefix('/passive')
            ->controller(InteractionPassiveController::class)
            ->group(function () {
                Route::get('/structure/{identifier}', 'byStructure');
            });
        Route::prefix('/active')
            ->controller(InteractionActiveController::class)
            ->group(function () {
                Route::get('/structure/{identifier}', 'byStructure');
            });
    });

Route::prefix('membrane')
    ->controller(MembraneController::class)
    ->group(function () {
        Route::get('/categories', 'categories');
        Route::get('/{membrane}', 'show');
        Route::get('/{membrane}/stats', 'stats');
    });

Route::prefix('method')
    ->controller(MethodController::class)
    ->group(function () {
        Route::get('/categories', 'categories');
        Route::get('/{method}', 'show');
        Route::get('/{method}/stats', 'stats');
    });

Route::prefix('protein')
    ->controller(ProteinController::class)
    ->group(function () {
        Route::get('/categories', 'categories');
        Route::get('/{protein}', 'show');
        Route::get('/{protein}/download/interactions', 'downloadInteractions');
        Route::get('/{protein}/stats', 'stats');
    });

Route::prefix("publication")
    ->controller(PublicationController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/{publication}', 'show');
        Route::get('/{publication}/stats', 'stats');
    });

Route::prefix('search')
    ->controller(SearchController::class)
    ->group(function () {
        Route::get('/structures', 'structure');
        Route::get('/membranes', 'membrane');
        Route::get('/methods', 'method');
        Route::get('/proteins', 'protein');
        Route::get('/datasets', 'dataset');
    });

Route::prefix('stats')
    ->controller(StatsController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('/publications', 'publications');
    });

Route::prefix('structure')
    ->controller(StructureController::class)
    ->group(function () {
        Route::get('/{identifier}', 'show');
        Route::get('mol/3d/{identifier}', 'mol3D');
        Route::get('/{identifier}/form/select/membranes', 'formSelectMembranes');
        Route::get('/{identifier}/form/select/methods', 'formSelectMethods');
        Route::get('/{identifier}/similarities', 'similarities');
    });
