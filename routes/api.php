<?php

use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\DownloaderController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InteractionActiveController;
use App\Http\Controllers\InteractionPassiveController;
use App\Http\Controllers\LabUploadController;
use App\Http\Controllers\MembraneController;
use App\Http\Controllers\MethodController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PredictionsController;
use App\Http\Controllers\ProteinController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RdfController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Resources\UserResource;
use App\Services\Rdf\RdfResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../modules/References/EuropePMC/Routes/api.php';

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return UserResource::make($request->user());
});

// Public, unauthenticated API — own rate limiter and CORS policy, not the
// internal throttle:300,1 default group below.
Route::prefix('v1')
    ->middleware(['public-cors', 'throttle:public-api', 'negotiate-public-api-format'])
    ->group(base_path('routes/public/v1.php'));

// Dereferencing of MolMeDB RDF IRIs (https://rdf.molmedb.upol.cz/* is proxied here).
Route::prefix('rdf')
    ->middleware(['public-cors', 'throttle:public-api'])
    ->group(function () {
        Route::get('vocabulary', [RdfController::class, 'vocabulary']);
        Route::get('vocabulary.owl', [RdfController::class, 'vocabulary']);
        Route::get('{path}', [RdfController::class, 'show'])->where('path', RdfResource::PATH_PATTERN);
    });

Route::group([], function () {
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

    Route::prefix('publication')
        ->controller(PublicationController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::get('/{publication}', 'show');
            Route::get('/{publication}/stats', 'stats');
        });

    Route::prefix('search')
        ->controller(SearchController::class)
        ->group(function () {
            Route::get('/structures', 'structure')->middleware('throttle:30,1');
            Route::get('/membranes', 'membrane');
            Route::get('/methods', 'method');
            Route::get('/proteins', 'protein');
            Route::get('/datasets', 'dataset');
        });

    Route::prefix('docs')
        ->controller(DocumentationController::class)
        ->group(function () {
            Route::get('/tree', 'tree');
            Route::get('/article', 'article');
            Route::get('/article/{parentSlug}', 'article');
            Route::get('/article/{parentSlug}/{childSlug}', 'article');
        });

    Route::prefix('predictions')
        ->controller(PredictionsController::class)
        ->group(function () {
            // Public — no auth required
            Route::get('/options', 'options');
            Route::get('/server-stats', 'serverStats');
            Route::post('/by-token', 'datasetByToken')->middleware('throttle:20,1');
            Route::post('/email-verification', 'requestEmailVerification')->middleware('throttle:5,1');
            Route::post('/email-verification/verify', 'verifyEmail')->middleware('throttle:10,1');
            Route::post('/validate-smiles', 'validateSmiles')->middleware('throttle:10,1');

            Route::post('/datasets', 'storeDataset')->middleware(['auth:sanctum', 'throttle:10,1']);
            Route::get('/datasets', 'index_datasets')->middleware('auth:sanctum');
            Route::get('/datasets/{record}', 'index');
            Route::patch('/datasets/{record}', 'updateDataset');
            Route::get('/datasets/{record}/records', 'records');
            Route::get('/datasets/{record}/structures', 'structures');
            Route::get('/byStructure/{record}', 'predictionsByStructure');
        });

    Route::prefix('stats')
        ->controller(StatsController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::get('/publications', 'publications');
        });

    Route::prefix('notifications')
        ->controller(UserNotificationController::class)
        ->middleware('auth:sanctum')
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/read', 'markAsRead')->middleware('throttle:60,1');
            Route::delete('/clear', 'clearAll')->middleware('throttle:30,1');
        });

    Route::prefix('notifications/push-subscriptions')
        ->controller(PushSubscriptionController::class)
        ->middleware(['auth:sanctum', 'throttle:20,1'])
        ->group(function () {
            Route::post('/', 'store');
            Route::delete('/', 'destroy');
        });

    Route::prefix('notifications/preferences')
        ->controller(NotificationPreferenceController::class)
        ->middleware(['auth:sanctum', 'throttle:30,1'])
        ->group(function () {
            Route::get('/', 'index');
            Route::put('/', 'update');
        });

    Route::prefix('feedback')
        ->controller(FeedbackController::class)
        ->group(function () {
            Route::post('/', 'storeAuthenticated')->middleware(['auth:sanctum', 'throttle:10,1']);
            Route::post('/authenticated', 'storeAuthenticated')->middleware(['auth:sanctum', 'throttle:10,1']);
            Route::post('/email-verification', 'requestEmailVerification')->middleware('throttle:5,1');
            Route::post('/email-verification/verify', 'verifyEmail')->middleware('throttle:10,1');
        });

    Route::prefix('downloader')
        ->controller(DownloaderController::class)
        ->middleware('throttle:30,1')
        ->group(function () {
            Route::post('/verify', 'verify');
            Route::post('/', 'store');
            Route::get('/{download:uuid}', 'show');
            Route::get('/{download:uuid}/file', 'download');
        });

    Route::prefix('lab/upload')
        ->controller(LabUploadController::class)
        ->group(function () {
            Route::get('/membranes', 'membranes');
            Route::get('/methods', 'methods');
            Route::get('/publications', 'publications');
            Route::get('/publications/lookup', 'lookupPublications');
            Route::get('/my-uploads', 'myUploads')->middleware('auth:sanctum');
            Route::get('/track/{token}', 'track');
            Route::post('/email-verification', 'requestEmailVerification')->middleware('throttle:5,1');
            Route::post('/email-verification/verify', 'verifyEmail')->middleware('throttle:10,1');
            Route::post('/', 'store')->middleware('auth:sanctum');
            Route::get('/{record}/configure/preview', 'configurePreview');
            Route::post('/{record}/configure/validate', 'validateConfiguration');
            Route::post('/{record}/enqueue', 'enqueue');
            Route::post('/{record}/revert', 'revert');
            Route::post('/{record}/cancel', 'cancel');
            Route::post('/{record}/reupload', 'reupload');
        });

    Route::prefix('structure')
        ->controller(StructureController::class)
        ->group(function () {
            Route::post('/predictions/validate', [PredictionsController::class, 'validateSmiles'])
                ->middleware('throttle:10,1');
            Route::get('/{identifier}', 'show');
            Route::get('/{identifier}/status', 'status');
            Route::get('mol/3d/{identifier}', 'mol3D');
            Route::get('mol/canonize_smiles/{smiles}', 'molCanonizeSmiles')
                ->middleware('throttle:35,1');
            Route::get('/{identifier}/form/select/membranes', 'formSelectMembranes');
            Route::get('/{identifier}/form/select/methods', 'formSelectMethods');
            Route::get('/{identifier}/similarities', 'similarities');
        });
})
    ->middleware('throttle:300,1');
