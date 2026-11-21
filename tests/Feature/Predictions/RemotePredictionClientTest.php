<?php

use App\Models\Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\PredictionWorkers\Exceptions\RemotePredictionDisabledException;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;

beforeEach(function () {
    // Administrators switch the service and its URL in the settings, stored
    // in configs, which take precedence over config/prediction-workers.php.
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '1');
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test');
    config()->set('prediction-workers.remote.manager_secret', 'manager-secret');
    config()->set('prediction-workers.remote.retry_delays', [0, 0]);
    config()->set('prediction-workers.remote.send_method_parameter', false);
});

function storeRemotePredictionToken(string $token, string $expiresAt, ?int $id = null): void
{
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, $token);
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, $expiresAt);

    if ($id !== null) {
        Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_ID, (string) $id);
    }
}

test('a disabled remote service refuses every request', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_ENABLED, '0');
    storeRemotePredictionToken('valid-token', now()->addDays(10)->toIso8601String());
    Http::fake();

    app(RemotePredictionClient::class)->createJob('CCO', 'dopc', 25.0);
})->throws(RemotePredictionDisabledException::class);

test('the remote service must be reached over HTTPS', function (string $url) {
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, $url);

    app(RemotePredictionClient::class)->baseUrl();
})->with([
    'plain HTTP' => 'http://predictor.test',
    'not a URL' => 'predictor',
])->throws(RemotePredictionException::class, 'must be a valid HTTPS URL');

test('a missing token is created with the manager secret and stored', function () {
    Http::fake([
        'https://predictor.test/auth/tokens' => Http::response([
            'id' => 12,
            'name' => 'molmedb-worker',
            'token' => 'fresh-token',
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]),
    ]);

    app(RemotePredictionClient::class)->ensureValidToken();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/auth/tokens'
        && $request->hasHeader('X-API-Manager-Secret', 'manager-secret')
        && $request['name'] === 'molmedb-worker');

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('fresh-token')
        ->and(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN_ID))->toBe('12');
});

test('a token expiring within a day is replaced and the old one revoked', function () {
    storeRemotePredictionToken('old-token', now()->addHours(12)->toIso8601String(), 7);
    Http::fake([
        'https://predictor.test/auth/tokens' => Http::response(['id' => 8, 'token' => 'new-token', 'expires_at' => now()->addDays(30)->toIso8601String()]),
        'https://predictor.test/auth/tokens/7/revoke' => Http::response(['id' => 7]),
    ]);

    app(RemotePredictionClient::class)->ensureValidToken();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/auth/tokens/7/revoke');
    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('new-token');
});

test('a valid token is kept', function () {
    storeRemotePredictionToken('valid-token', now()->addDays(10)->toIso8601String());
    Http::fake();

    app(RemotePredictionClient::class)->ensureValidToken();

    Http::assertNothingSent();
});

test('a job is submitted with the bearer token and its answer parsed', function () {
    storeRemotePredictionToken('valid-token', now()->addDays(10)->toIso8601String());
    Http::fake([
        'https://predictor.test/jobs' => Http::response([
            'molecule_id' => 'mol-1',
            'calculation_id' => 'calc-1',
            'canonical_smiles' => 'CCO',
            'molecule_status' => 'queued',
            'calculation_status' => 'queued',
        ]),
    ]);

    $submission = app(RemotePredictionClient::class)->createJob('CCO', 'dopc', 37.0, 'cosmoperm');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs'
        && $request->hasHeader('Authorization', 'Bearer valid-token')
        && $request->data() === ['smiles' => 'CCO', 'membrane_key' => 'dopc', 'temperature_c' => 37.0]);

    expect($submission->calculationId)->toBe('calc-1')
        ->and($submission->moleculeId)->toBe('mol-1');
});

test('a request without a stored token fails before reaching the service', function () {
    Http::fake();

    app(RemotePredictionClient::class)->createJob('CCO', 'dopc', 25.0);
})->throws(RemotePredictionException::class, 'Bearer token is not configured');

test('error answers become exceptions with the service message and code', function () {
    storeRemotePredictionToken('valid-token', now()->addDays(10)->toIso8601String());
    Http::fake([
        'https://predictor.test/jobs' => Http::response(['detail' => ['code' => 'invalid_smiles', 'message' => 'SMILES could not be parsed.']], 422),
    ]);

    try {
        app(RemotePredictionClient::class)->createJob('C(', 'dopc', 25.0);
        $this->fail('The client should have thrown.');
    } catch (RemotePredictionException $exception) {
        expect($exception->getMessage())->toBe('SMILES could not be parsed.')
            ->and($exception->statusCode)->toBe(422)
            ->and($exception->errorCode)->toBe('invalid_smiles');
    }
});

test('server errors are retried before giving up', function () {
    storeRemotePredictionToken('valid-token', now()->addDays(10)->toIso8601String());
    Http::fakeSequence('https://predictor.test/jobs')
        ->push(['detail' => 'Temporarily unavailable'], 503)
        ->push(['calculation_id' => 'calc-2', 'molecule_id' => 'mol-2'], 200);

    $submission = app(RemotePredictionClient::class)->createJob('CCO', 'dopc', 25.0);

    expect($submission->calculationId)->toBe('calc-2');
    Http::assertSentCount(2);
});
