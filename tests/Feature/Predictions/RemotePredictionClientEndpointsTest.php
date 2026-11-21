<?php

require_once __DIR__.'/remote_prediction_test_helpers.php';

use App\Models\Config;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionCalculation;
use Modules\PredictionWorkers\Enums\RemotePredictionArtifact;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;
use Modules\PredictionWorkers\Exceptions\RemotePredictionException;
use Modules\PredictionWorkers\Services\RemotePrediction\RemotePredictionClient;

/*
 * The remote prediction client's endpoints and their parsed answers, with
 * the service faked over HTTP.
 */

beforeEach(function () {
    enableRemotePredictionService();
    config()->set('prediction-workers.remote.send_method_parameter', false);
});

function remoteClient(): RemotePredictionClient
{
    return app(RemotePredictionClient::class);
}

test('health and the OpenAPI document are read without credentials', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, null);
    Config::set(Config::KEY_REMOTE_PREDICTION_URL, 'https://predictor.test/');
    fakePredictionHttpRoutes([
        'https://predictor.test/health' => Http::response(['status' => 'ok']),
        'https://predictor.test/openapi.json' => Http::response(['openapi' => '3.1.0', 'info' => ['title' => 'Predictor']]),
    ]);

    $health = remoteClient()->health();

    expect($health->isHealthy())->toBeTrue()
        ->and($health->toArray())->toBe(['status' => 'ok'])
        ->and(remoteClient()->openApi()->data['info']['title'])->toBe('Predictor');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization') || $request->hasHeader('X-API-Manager-Secret'));
});

test('a health answer without a status is not healthy', function () {
    fakePredictionHttpRoutes(['https://predictor.test/health' => Http::response(['uptime' => 10])]);

    $health = remoteClient()->health();

    expect($health->status)->toBe('unknown')
        ->and($health->isHealthy())->toBeFalse();
});

test('manager endpoints need the manager secret', function () {
    config()->set('prediction-workers.remote.manager_secret', '  ');
    Http::fake();

    expect(fn () => remoteClient()->accessStatistics())->toThrow(RemotePredictionException::class, 'manager secret is not configured');
    Http::assertNothingSent();
});

test('access statistics are read with the manager secret', function () {
    fakePredictionHttpRoutes(['https://predictor.test/auth/stats' => Http::response(['tokens' => 2, 'last_used_at' => '2026-11-20T12:00:00+00:00'])]);

    $statistics = remoteClient()->accessStatistics();

    expect($statistics->data['tokens'])->toBe(2)
        ->and($statistics->data['last_used_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($statistics->toArray()['last_used_at'])->toBe('2026-11-20T12:00:00.000000Z');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Manager-Secret', 'manager-secret')
        && ! $request->hasHeader('Authorization'));
});

test('a failed revocation of the replaced token does not undo the refresh', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT, now()->subMinute()->toIso8601String());
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_ID, '7');
    $expiresAt = now()->addDays(30)->startOfSecond();
    fakePredictionHttpRoutes([
        'https://predictor.test/auth/tokens/7/revoke' => Http::response(['detail' => 'Token already revoked.'], 404),
        'https://predictor.test/auth/tokens' => Http::response(['id' => 8, 'token' => 'new-token', 'token_prefix' => 'new', 'expires_at' => $expiresAt->toIso8601String()]),
    ]);

    remoteClient()->ensureValidToken();

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('new-token')
        ->and(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN_ID))->toBe('8')
        ->and(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN_EXPIRES_AT))->toBe($expiresAt->toIso8601String());
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/auth/tokens'
        && $request['expires_in_days'] === 30);
});

test('a refreshed token with the same id is not revoked', function () {
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN, '');
    Config::set(Config::KEY_REMOTE_PREDICTION_TOKEN_ID, '8');
    fakePredictionHttpRoutes(['https://predictor.test/auth/tokens' => Http::response(['id' => 8, 'token' => 'same-id-token', 'expires_at' => now()->addDays(30)->toIso8601String()])]);

    remoteClient()->ensureValidToken();

    expect(Config::get(Config::KEY_REMOTE_PREDICTION_TOKEN))->toBe('same-id-token');
    Http::assertSentCount(1);
});

test('the token DTO keeps every field it is given', function () {
    fakePredictionHttpRoutes(['https://predictor.test/auth/tokens/3/revoke' => Http::response([
        'id' => 3,
        'name' => 'molmedb-worker',
        'token_prefix' => 'mmdb_',
        'expires_at' => '2026-12-01T00:00:00+00:00',
        'created_at' => '2026-11-01T00:00:00+00:00',
        'revoked_at' => '2026-11-21T10:00:00+00:00',
    ])]);

    $token = remoteClient()->revokeToken(3);

    expect($token->jsonSerialize())->toBe([
        'id' => 3,
        'name' => 'molmedb-worker',
        'token_prefix' => 'mmdb_',
        'token' => null,
        'expires_at' => '2026-12-01T00:00:00.000000Z',
        'created_at' => '2026-11-01T00:00:00.000000Z',
        'revoked_at' => '2026-11-21T10:00:00.000000Z',
    ]);
});

test('membranes are registered and listed, and found by the md5 of their contents', function () {
    fakePredictionHttpRoutes([
        'https://predictor.test/membranes' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['key' => 'dopc', 'md5' => md5('definition'), 'file_valid' => true])
            : Http::response(['membranes' => [
                ['key' => 'dopc', 'md5' => md5('definition'), 'file_valid' => true, 'updated_at' => '2026-11-01T00:00:00+00:00'],
                'not a membrane',
                ['key' => 'popc', 'md5' => md5('other'), 'file_valid' => false],
            ]]),
    ]);

    $registered = remoteClient()->registerMembrane('dopc', 'definition');
    $membranes = remoteClient()->membranes();

    expect($registered->key)->toBe('dopc')
        ->and($membranes->membranes)->toHaveCount(2)
        ->and($membranes->findByMd5(md5('other'))->fileValid)->toBeFalse()
        ->and($membranes->findByMd5(md5('missing')))->toBeNull()
        ->and($membranes->toArray()['membranes'][0])->toBe([
            'key' => 'dopc',
            'md5' => md5('definition'),
            'file_valid' => true,
            'created_at' => null,
            'updated_at' => '2026-11-01T00:00:00.000000Z',
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['key' => 'dopc', 'content' => 'definition']);
});

test('the method is sent with a job only when enabled', function () {
    config()->set('prediction-workers.remote.send_method_parameter', true);
    fakePredictionHttpRoutes(['https://predictor.test/jobs' => Http::response([
        'molecule_id' => 'mol-1',
        'calculation_id' => 'calc-1',
        'canonical_smiles' => 'CCO',
        'molecule_status' => 'queued',
        'calculation_status' => 'deferred',
        'message' => 'Queued behind 3 jobs.',
    ])]);

    $submission = remoteClient()->createJob('CCO', 'dopc', 25.0, 'cosmoperm');
    remoteClient()->createJob('CCO', 'dopc', 25.0, '');

    expect($submission->moleculeStatus)->toBe(RemotePredictionStatus::QUEUED)
        ->and($submission->calculationStatus)->toBe('deferred')
        ->and($submission->toArray())->toBe([
            'molecule_id' => 'mol-1',
            'calculation_id' => 'calc-1',
            'canonical_smiles' => 'CCO',
            'molecule_status' => 'queued',
            'calculation_status' => 'deferred',
            'message' => 'Queued behind 3 jobs.',
        ]);

    $methods = Http::recorded()->map(fn (array $pair): mixed => $pair[0]->data()['method'] ?? null)->values()->all();
    expect($methods)->toBe(['cosmoperm', null]);
});

test('calculation statuses are asked once per calculation and parsed', function () {
    fakePredictionHttpRoutes(['https://predictor.test/calculations/status' => Http::response(['calculations' => [
        [
            'id' => 'calc-1',
            'molecule_id' => 'mol-1',
            'membrane_key' => 'dopc',
            'temperature_c' => 25,
            'status' => 'completed',
            'work_dir' => '/work/calc-1',
            'result' => ['status' => 'completed', 'finished_at' => '2026-11-21T09:00:00+00:00', 'values' => [1.5]],
            'attempts' => 2,
            'heartbeat_age_seconds' => '12',
            'finished_at' => '2026-11-21T09:00:00+00:00',
        ],
        'garbage',
    ]])]);

    expect(remoteClient()->calculationStatuses([]))->toBeEmpty();
    Http::assertNothingSent();

    $calculations = remoteClient()->calculationStatuses(['calc-1', 'calc-1', 'calc-2']);
    $calculation = $calculations->sole();

    expect($calculation)->toBeInstanceOf(RemotePredictionCalculation::class)
        ->and($calculation->status)->toBe(RemotePredictionStatus::COMPLETED)
        ->and($calculation->temperatureC)->toBe(25.0)
        ->and($calculation->heartbeatAgeSeconds)->toBe(12)
        ->and($calculation->result['status'])->toBe(RemotePredictionStatus::COMPLETED)
        ->and($calculation->result['finished_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($calculation->toArray()['result'])->toBe(['status' => 'completed', 'finished_at' => '2026-11-21T09:00:00.000000Z', 'values' => [1.5]])
        ->and($calculation->toArray()['work_dir'])->toBe('/work/calc-1');
    Http::assertSent(fn (Request $request): bool => $request['calculation_ids'] === ['calc-1', 'calc-2']);
});

test('jobs are paused, requeued and force requeued with the expected payloads', function () {
    fakePredictionHttpRoutes([
        'https://predictor.test/jobs/pause' => Http::response(['results' => [['smiles' => 'CCO', 'status' => 'paused']]]),
        'https://predictor.test/jobs/requeue' => Http::response(['id' => 'mol-1', 'status' => 'queued', 'current_step' => 'cosmo']),
    ]);

    $paused = remoteClient()->pauseJobs(['CCO', 'CCO', 'CCN'], 'Deferred.', false);
    $requeued = remoteClient()->requeueJob('CCO', 'dopc', 25.0);
    remoteClient()->forceRequeueJob('CCO', 'dopc', 25.0, RemotePredictionStep::OPTIMIZATION_ORCA);

    expect($paused['results'][0]['status'])->toBe('paused')
        ->and($requeued->currentStep)->toBe(RemotePredictionStep::COSMO);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/pause'
        && $request->data() === ['smiles' => ['CCO', 'CCN'], 'cancel_running' => false, 'reason' => 'Deferred.']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/requeue'
        && $request->data() === ['smiles' => 'CCO', 'membrane_key' => 'dopc', 'temperature_c' => 25.0]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/jobs/requeue/optimization-orca'
        && $request->data() === ['smiles' => 'CCO', 'membrane_key' => 'dopc', 'temperature_c' => 25.0, 'force' => true]);
});

test('pipeline statistics keep statuses, steps and dates typed and serialize back', function () {
    fakePredictionHttpRoutes(['https://predictor.test/stats' => Http::response([
        'molecules' => [['status' => 'running', 'current_step' => 'conformers', 'step' => 'xtb', 'created_at' => '2026-11-21T08:00:00+00:00', 'expires_at' => '']],
        'counts' => ['status' => 'unknown-status', 'total' => 3],
    ])]);

    $statistics = remoteClient()->pipelineStatistics();
    $molecule = $statistics->data['molecules'][0];

    expect($molecule['status'])->toBe(RemotePredictionStatus::RUNNING)
        ->and($molecule['current_step'])->toBe(RemotePredictionStep::CONFORMERS)
        ->and($molecule['step'])->toBe('xtb')
        ->and($molecule['created_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($molecule['expires_at'])->toBeNull()
        ->and($statistics->data['counts'])->toBe(['status' => 'unknown-status', 'total' => 3])
        ->and($statistics->jsonSerialize()['molecules'][0])->toBe([
            'status' => 'running',
            'current_step' => 'conformers',
            'step' => 'xtb',
            'created_at' => '2026-11-21T08:00:00.000000Z',
            'expires_at' => null,
        ]);
});

test('downloads take the file name from the answer or fall back to the path', function () {
    fakePredictionHttpRoutes([
        'https://predictor.test/molecules/download' => Http::response('molecule', 200, ['Content-Type' => 'application/zip']),
        'https://predictor.test/calculations/calc%2F1/download/sdf' => Http::response('sdf', 200, [
            'Content-Type' => 'chemical/x-mdl-sdfile',
            'Content-Disposition' => 'attachment; filename=ethanol.sdf',
        ]),
    ]);

    $molecule = remoteClient()->downloadMolecule('CCO');
    $sdf = remoteClient()->downloadArtifact('calc/1', RemotePredictionArtifact::SDF);

    expect($molecule->filename)->toBe('download')
        ->and($molecule->mimeType)->toBe('application/zip')
        ->and($sdf->filename)->toBe('ethanol.sdf')
        ->and($sdf->contents)->toBe('sdf');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://predictor.test/molecules/download'
        && $request['smiles'] === 'CCO');

    $path = tempnam(sys_get_temp_dir(), 'remote-download-');
    expect($sdf->saveTo($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('sdf');
    unlink($path);
});

test('a failed download becomes an exception', function () {
    fakePredictionHttpRoutes(['https://predictor.test/calculations/calc-1/download' => Http::response('', 404)]);

    expect(fn () => remoteClient()->downloadCalculation('calc-1'))
        ->toThrow(RemotePredictionException::class, 'Remote prediction service request failed with HTTP 404.');
});

test('an answer that is not a JSON object is refused', function () {
    fakePredictionHttpRoutes(['https://predictor.test/stats' => Http::response('<html>maintenance</html>', 200)]);

    try {
        remoteClient()->pipelineStatistics();
        $this->fail('The client should have thrown.');
    } catch (RemotePredictionException $exception) {
        expect($exception->getMessage())->toBe('Remote prediction service returned an invalid JSON response.')
            ->and($exception->statusCode)->toBe(200);
    }
});

test('a plain text error answer is passed on as the message', function () {
    fakePredictionHttpRoutes(['https://predictor.test/stats' => Http::response('Bad gateway', 400)]);

    try {
        remoteClient()->pipelineStatistics();
        $this->fail('The client should have thrown.');
    } catch (RemotePredictionException $exception) {
        expect($exception->getMessage())->toBe('Bad gateway')
            ->and($exception->detail)->toBe('Bad gateway')
            ->and($exception->errorCode)->toBeNull();
    }
});

test('connection failures are retried before giving up', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::failedConnection();
    });

    expect(fn () => remoteClient()->pipelineStatistics())->toThrow(ConnectionException::class);
    expect($attempts)->toBe(2);
});
