<?php

use Carbon\CarbonImmutable;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionConformer;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionJobSnapshot;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionPayload;
use Modules\PredictionWorkers\DTO\RemotePrediction\RemotePredictionStepSnapshot;
use Modules\PredictionWorkers\Enums\RemotePredictionStatus;
use Modules\PredictionWorkers\Enums\RemotePredictionStep;

/*
 * The remote service's job snapshots parsed into typed DTOs and serialized
 * back for storage and API responses.
 */

/**
 * @return array<string, mixed>
 */
function fullRemoteJobSnapshot(): array
{
    return [
        'id' => 'mol-1',
        'canonical_smiles' => 'CCO',
        'status' => 'running',
        'current_step' => 'optimization-orca',
        'work_dir' => '/work/mol-1',
        'conformer_count' => 2,
        'steps' => [
            [
                'step' => 'rdkit',
                'status' => 'completed',
                'attempts' => 1,
                'message' => 'Done.',
                'started_at' => '2026-11-21T08:00:00+00:00',
                'finished_at' => '2026-11-21T08:01:00+00:00',
                'heartbeat_at' => '2026-11-21T08:00:30+00:00',
                'heartbeat_age_seconds' => 5,
                'lease_expires_at' => '2026-11-21T08:10:00+00:00',
                'worker_id' => 'worker-1',
            ],
            'not a step',
        ],
        'conformers' => [
            [
                'id' => 'conf-0',
                'conformer_index' => 0,
                'relative_energy_kcal_mol' => '0.75',
                'source_xyz' => 'conformers/c000.xyz',
                'status' => 'running',
                'current_step' => 'optimization-orca',
                'work_dir' => '/work/mol-1/c000',
                'steps' => [
                    ['step' => 'optimization-orca', 'status' => 'running', 'lease_owner' => 'gpu-node-3'],
                    ['step' => 'xtb', 'status' => 'sleeping'],
                ],
                'created_at' => '2026-11-21T08:02:00+00:00',
            ],
            ['id' => 'conf-1', 'conformer_index' => 1, 'status' => 'pending', 'current_step' => 'unknown-step'],
        ],
        'calculations' => [
            ['id' => 'calc-25', 'membrane_key' => 'dopc', 'temperature_c' => 25.00001, 'status' => 'pending'],
            ['id' => 'calc-37', 'membrane_key' => 'dopc', 'temperature_c' => 37.0, 'status' => 'pending'],
        ],
        'events' => [
            ['type' => 'STEP', 'step' => 'rdkit', 'status' => 'completed', 'created_at' => '2026-11-21T08:01:00+00:00'],
            'not an event',
        ],
        'requeue' => ['from_step' => 'conformers', 'requeued_at' => '2026-11-21T07:00:00+00:00'],
        'created_at' => '2026-11-21T07:59:00+00:00',
        'updated_at' => '2026-11-21T08:05:00+00:00',
    ];
}

test('a job snapshot is parsed into typed steps, conformers, calculations and events', function () {
    $snapshot = RemotePredictionJobSnapshot::fromArray(fullRemoteJobSnapshot());

    expect($snapshot->status)->toBe(RemotePredictionStatus::RUNNING)
        ->and($snapshot->currentStep)->toBe(RemotePredictionStep::OPTIMIZATION_ORCA)
        ->and($snapshot->conformerCount)->toBe(2)
        ->and($snapshot->steps)->toHaveCount(1)
        ->and($snapshot->steps->first()->heartbeatAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($snapshot->conformers)->toHaveCount(2)
        ->and($snapshot->conformers->first()->relativeEnergyKcalMol)->toBe(0.75)
        ->and($snapshot->conformers->first()->steps->first()->workerId)->toBe('gpu-node-3')
        ->and($snapshot->conformers->first()->steps->last()->step)->toBe('xtb')
        ->and($snapshot->conformers->first()->steps->last()->status)->toBe('sleeping')
        ->and($snapshot->conformers->last()->currentStep)->toBe('unknown-step')
        ->and($snapshot->events)->toHaveCount(1)
        ->and($snapshot->events->first()['step'])->toBe(RemotePredictionStep::RDKIT)
        ->and($snapshot->requeue['requeued_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($snapshot->calculationFor('dopc', 25.0)?->id)->toBe('calc-25')
        ->and($snapshot->calculationFor('dopc', 30.0))->toBeNull()
        ->and($snapshot->calculationFor('popc', 37.0))->toBeNull()
        ->and($snapshot->calculationById('calc-37')?->temperatureC)->toBe(37.0)
        ->and($snapshot->calculationById('calc-99'))->toBeNull();
});

test('a job snapshot serializes back to the service format', function () {
    $array = RemotePredictionJobSnapshot::fromArray(fullRemoteJobSnapshot())->jsonSerialize();

    expect($array['current_step'])->toBe('optimization-orca')
        ->and($array['steps'])->toBe([[
            'step' => 'rdkit',
            'status' => 'completed',
            'attempts' => 1,
            'message' => 'Done.',
            'started_at' => '2026-11-21T08:00:00.000000Z',
            'finished_at' => '2026-11-21T08:01:00.000000Z',
            'heartbeat_at' => '2026-11-21T08:00:30.000000Z',
            'heartbeat_age_seconds' => 5,
            'lease_expires_at' => '2026-11-21T08:10:00.000000Z',
            'worker_id' => 'worker-1',
        ]])
        ->and($array['conformers'][0])->toBe([
            'id' => 'conf-0',
            'conformer_index' => 0,
            'relative_energy_kcal_mol' => 0.75,
            'source_xyz' => 'conformers/c000.xyz',
            'status' => 'running',
            'current_step' => 'optimization-orca',
            'work_dir' => '/work/mol-1/c000',
            'steps' => [
                [
                    'step' => 'optimization-orca',
                    'status' => 'running',
                    'attempts' => 0,
                    'message' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'heartbeat_at' => null,
                    'heartbeat_age_seconds' => null,
                    'lease_expires_at' => null,
                    'worker_id' => 'gpu-node-3',
                ],
                [
                    'step' => 'xtb',
                    'status' => 'sleeping',
                    'attempts' => 0,
                    'message' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'heartbeat_at' => null,
                    'heartbeat_age_seconds' => null,
                    'lease_expires_at' => null,
                    'worker_id' => null,
                ],
            ],
            'created_at' => '2026-11-21T08:02:00.000000Z',
            'updated_at' => null,
        ])
        ->and($array['conformers'][1]['current_step'])->toBe('unknown-step')
        ->and($array['conformers'][1]['relative_energy_kcal_mol'])->toBeNull()
        ->and($array['calculations'][0]['status'])->toBe('pending')
        ->and($array['events'])->toBe([['type' => 'STEP', 'step' => 'rdkit', 'status' => 'completed', 'created_at' => '2026-11-21T08:01:00.000000Z']])
        ->and($array['requeue'])->toBe(['from_step' => 'conformers', 'requeued_at' => '2026-11-21T07:00:00.000000Z'])
        ->and($array['created_at'])->toBe('2026-11-21T07:59:00.000000Z');
});

test('an empty answer parses into empty DTOs', function () {
    $snapshot = RemotePredictionJobSnapshot::fromArray([]);
    $conformer = RemotePredictionConformer::fromArray([]);
    $step = RemotePredictionStepSnapshot::fromArray([]);

    expect($snapshot->status)->toBe('')
        ->and($snapshot->currentStep)->toBeNull()
        ->and($snapshot->requeue)->toBeNull()
        ->and($snapshot->toArray()['requeue'])->toBeNull()
        ->and($conformer->currentStep)->toBeNull()
        ->and($conformer->steps)->toBeEmpty()
        ->and($conformer->toArray()['current_step'])->toBeNull()
        ->and($step->step)->toBe('')
        ->and($step->workerId)->toBeNull();
});

test('payload values are typed by their key and serialized back', function () {
    $parsed = RemotePredictionPayload::parse([
        'status' => 'failed',
        'step' => 'cosmo',
        'current_step' => 'not-a-step',
        'started_at' => '2026-11-21T08:00:00+00:00',
        'deleted_at' => '',
        'nested' => ['created_at' => '2026-11-20T08:00:00+00:00', 'list' => ['queued', 3, null, true]],
        'message' => 'status',
    ]);

    expect($parsed['status'])->toBe(RemotePredictionStatus::FAILED)
        ->and($parsed['step'])->toBe(RemotePredictionStep::COSMO)
        ->and($parsed['current_step'])->toBe('not-a-step')
        ->and($parsed['started_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($parsed['deleted_at'])->toBeNull()
        ->and($parsed['nested']['created_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($parsed['nested']['list'])->toBe(['queued', 3, null, true])
        ->and($parsed['message'])->toBe('status')
        ->and(RemotePredictionPayload::serialize($parsed))->toBe([
            'status' => 'failed',
            'step' => 'cosmo',
            'current_step' => 'not-a-step',
            'started_at' => '2026-11-21T08:00:00.000000Z',
            'deleted_at' => null,
            'nested' => ['created_at' => '2026-11-20T08:00:00.000000Z', 'list' => ['queued', 3, null, true]],
            'message' => 'status',
        ]);
});
