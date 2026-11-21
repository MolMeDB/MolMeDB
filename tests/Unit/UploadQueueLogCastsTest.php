<?php

use App\Casts\UploadQueueLogCasts;
use App\Enums\UploadQueueLogContextEnums;
use App\ValueObjects\UploadQueueLog;

/*
 * Upload logs are stored as JSON; a log's payload (for example the decision of
 * an admin review) must come back as it was stored.
 */

test('a stored log payload comes back after reloading', function () {
    $cast = new UploadQueueLogCasts;
    $stored = $cast->set(null, 'logs', [
        new UploadQueueLog('Approved by admin.', UploadQueueLogContextEnums::SUCCESS, now()->toISOString(), '7', null, 3, ['decision' => 'approved', 'rows' => ['valid' => 12]]),
        new UploadQueueLog('Started.', UploadQueueLogContextEnums::SUCCESS, now()->toISOString()),
    ], []);

    $logs = $cast->get(null, 'logs', $stored, []);

    expect($logs[0]->payload)->toBe(['decision' => 'approved', 'rows' => ['valid' => 12]])
        ->and($logs[0]->user_id)->toBe('7')
        ->and($logs[1]->payload)->toBeNull();
});
