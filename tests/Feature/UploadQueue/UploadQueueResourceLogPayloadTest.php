<?php

require_once __DIR__.'/upload_pipeline_helpers.php';

use App\Enums\UploadQueueLogContextEnums;
use App\Enums\UploadQueueLogTypeEnums;
use App\Http\Resources\UploadQueueResource;
use App\Jobs\ProcessUploadQueueRecord;
use App\Models\UploadQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

/*
 * The logs of an upload reach its owner (my-uploads) and anyone holding a
 * tracking link. A log's payload is an internal record, so the API publishes
 * only the parts listed in UploadQueueResource.
 */

beforeEach(function () {
    fakePublicStorageForTests();
    fakeUploadChemistryServices();
    Queue::fake();
});

/**
 * @param  array<string, mixed>|null  $payload
 * @return array<string, mixed>|null
 */
function publishedLogPayload(?array $payload): ?array
{
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen']);
    $record->addStructuredLog('Something happened.', UploadQueueLogContextEnums::INFO, UploadQueueLogTypeEnums::UPLOAD, UploadQueue::STATE_UPLOADED, $payload, $record->user_id);

    $logs = (new UploadQueueResource($record->refresh()))->toArray(request())['logs'];

    return collect($logs)->firstWhere('message', 'Something happened.')['payload'];
}

test('the upload request log shows what the uploader entered, without the ids of the chosen records', function () {
    $payload = publishedLogPayload([
        'dataset_type' => 'passive',
        'dataset_name' => 'My measurements',
        'method_id' => 7,
        'membrane_id' => 3,
        'publication_id' => 11,
        'publication_pmid' => '12345678',
        'comment' => 'First batch',
        'file_name' => 'data.csv',
        'file_size' => 2048,
        'file_mime' => 'text/csv',
    ]);

    expect($payload)->toBe([
        'dataset_type' => 'passive',
        'dataset_name' => 'My measurements',
        'publication_pmid' => '12345678',
        'comment' => 'First batch',
        'file_name' => 'data.csv',
        'file_size' => 2048,
        'file_mime' => 'text/csv',
    ]);
});

test('internal parts of a log payload are not published', function (array $payload) {
    expect(publishedLogPayload($payload))->toBeNull();
})->with([
    'the address a notification went to' => [['emailed_to' => 'uploader@example.test', 'log_count' => 3]],
    'file ids and the stored file name' => [['new_file_name' => 'upload_65f0.csv', 'new_file_id' => 12, 'old_file_id' => 9, 'file_id' => 12]],
    'record and dataset ids' => [['mode' => ['nested' => 'value'], 'record_id' => 5, 'dataset_id' => 8]],
    'imported sample rows' => [['sample_rows' => [['structure_id' => 1, 'publication_id' => 2, 'protein_id' => 3]], 'internal_identifier_rows' => [4]]],
    'duplicate details and row errors' => [['duplicate_errors' => ['ID 5: DB=1, upload=2'], 'duplicate_warnings' => ['x'], 'row_errors' => [['line' => 2]]]],
    'a key nobody listed' => [['debug' => 'SELECT * FROM users', 'file_path' => '/var/www/storage/app/upload.csv']],
    'a listed key holding a structure' => [['comment' => ['nested' => 'value'], 'reason' => (object) ['x' => 1]]],
    'empty lists and empty values' => [['errors' => [], 'warnings' => [], 'comment' => null, 'validated_rows' => null]],
    'a list that is not one' => [['errors' => 'everything failed', 'warnings' => 3]],
]);

test('progress counters and retry times are published', function () {
    $payload = [
        'validated_rows' => 120,
        'processed_rows' => 80,
        'prepared_rows' => 80,
        'created_rows' => 75,
        'skipped_rows' => 5,
        'next_line' => 82,
        'retry_delay_seconds' => 60,
        'retry_at' => '2026-11-21T10:00:00.000000Z',
        'service' => 'unichem',
        'file_deleted' => true,
        'irreversible_notice' => true,
        'admin_review_approved' => false,
        'reason' => 'The units are missing.',
    ];

    expect(publishedLogPayload($payload))->toBe([
        'file_deleted' => true,
        'validated_rows' => 120,
        'processed_rows' => 80,
        'prepared_rows' => 80,
        'created_rows' => 75,
        'skipped_rows' => 5,
        'next_line' => 82,
        'retry_delay_seconds' => 60,
        'retry_at' => '2026-11-21T10:00:00.000000Z',
        'service' => 'unichem',
        'irreversible_notice' => true,
        'admin_review_approved' => false,
        'reason' => 'The units are missing.',
    ]);
});

test('validation messages are published as text and at most a hundred of each', function () {
    $payload = publishedLogPayload([
        'errors' => [...array_map(fn (int $line): string => "Line {$line}: invalid value.", range(1, 150)), ['line' => 3, 'id' => 9]],
        'warnings' => ['Line 2: rounded.', 42, ['structure_id' => 1]],
    ]);

    expect($payload['errors'])->toHaveCount(100)
        ->and($payload['errors'][0])->toBe('Line 1: invalid value.')
        ->and($payload['errors'][99])->toBe('Line 100: invalid value.')
        ->and($payload['warnings'])->toBe(['Line 2: rounded.']);
});

test('a log without a payload stays without one', function () {
    expect(publishedLogPayload(null))->toBeNull();
});

test('allowed and internal parts of one payload are told apart', function () {
    $payload = publishedLogPayload([
        'record_id' => 5,
        'validated_rows' => 12,
        'emailed_to' => 'uploader@example.test',
        'errors' => ['Line 3: interaction already exists in database as ID 42 (g_pen: DB=1.2, upload=1.3).'],
        'warnings' => [],
        'sample_rows' => [['structure_id' => 1]],
    ]);

    expect($payload)->toBe([
        'validated_rows' => 12,
        'errors' => ['Line 3: interaction already exists in database as ID 42 (g_pen: DB=1.2, upload=1.3).'],
    ]);
});

test('the tracking link and the list of own uploads return the filtered payload', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['guest_token' => 'track-me-123']);
    $record->addStructuredLog('Notified.', UploadQueueLogContextEnums::INFO, UploadQueueLogTypeEnums::UPLOAD, UploadQueue::STATE_UPLOADED, [
        'emailed_to' => 'uploader@example.test',
        'record_id' => $record->id,
        'file_name' => 'data.csv',
    ], $record->user_id);

    $tracked = $this->getJson('/api/lab/upload/track/track-me-123')->assertOk();
    $mine = $this->actingAs($record->user)->getJson('/api/lab/upload/my-uploads')->assertOk();

    foreach ([$tracked->getContent(), $mine->getContent()] as $body) {
        expect($body)->toContain('data.csv')
            ->and($body)->not->toContain('emailed_to')
            ->and($body)->not->toContain('uploader@example.test')
            ->and($body)->not->toContain('record_id');
    }
});

test('a database error does not reach the uploader with its SQL', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['state' => UploadQueue::STATE_RUNNING]);
    $exception = new QueryException('pgsql', 'insert into "interactions" ("secret") values (?)', ['s3cret'], new Exception('SQLSTATE[08006] connection to server at "10.0.0.5" failed'));

    (new ProcessUploadQueueRecord($record->id))->failed($exception);

    $resource = (new UploadQueueResource($record->refresh()))->toArray(request());
    $logs = json_encode([$resource['logs'], $resource['last_message']]);

    expect($record->state)->toBe(UploadQueue::STATE_ERROR)
        ->and($logs)->toContain('database error')
        ->and($logs)->not->toContain('interactions')
        ->and($logs)->not->toContain('10.0.0.5')
        ->and($logs)->not->toContain('s3cret');
});

test('a failure that explains itself keeps its message for the uploader', function () {
    $record = createConfiguredUpload("SMILES,Gpen\nCCO,-1\n", ['smiles', 'g_pen'], attributes: ['state' => UploadQueue::STATE_RUNNING]);

    (new ProcessUploadQueueRecord($record->id))->failed(new RuntimeException('Line 4: the value is not a number.'));

    expect((new UploadQueueResource($record->refresh()))->toArray(request())['last_message'])->toBe('Line 4: the value is not a number.');
});
