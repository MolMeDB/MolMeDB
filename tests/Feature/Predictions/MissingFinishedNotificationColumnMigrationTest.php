<?php

use Illuminate\Support\Facades\Schema;

/*
 * A predictions database created before the column existed, or restored from
 * such a dump, lacks datasets.finished_notification_sent_at although the
 * migration that added it is recorded as run.
 */

function missingFinishedNotificationColumnMigration(): object
{
    return require base_path('modules/PredictionWorkers/database/migrations/2026_10_08_000001_add_missing_finished_notification_sent_at_to_datasets.php');
}

test('the column is added to a predictions database that lacks it', function () {
    Schema::connection('predictions')->table('datasets', fn ($table) => $table->dropColumn('finished_notification_sent_at'));
    expect(Schema::connection('predictions')->hasColumn('datasets', 'finished_notification_sent_at'))->toBeFalse();

    missingFinishedNotificationColumnMigration()->up();

    expect(Schema::connection('predictions')->hasColumn('datasets', 'finished_notification_sent_at'))->toBeTrue();
});

test('a predictions database that has the column is left as it is', function () {
    missingFinishedNotificationColumnMigration()->up();

    expect(Schema::connection('predictions')->hasColumn('datasets', 'finished_notification_sent_at'))->toBeTrue();
});
