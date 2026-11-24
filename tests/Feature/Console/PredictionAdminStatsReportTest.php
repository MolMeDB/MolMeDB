<?php

require_once __DIR__.'/../Predictions/prediction_helpers.php';

use App\Console\Commands\Cron\SendPredictionAdminStatsNotification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Carbon;
use Modules\PredictionWorkers\Models\Prediction;
use Modules\PredictionWorkers\Models\PredictionResult;
use Spatie\Activitylog\Models\Activity;

/*
 * `cron:predictions-admin-stats {period}` reports the previous calendar day, week,
 * month or year to the prediction managers.
 */

/**
 * A prediction created and last updated at the given time.
 *
 * @param  array<string, mixed>  $attributes
 */
function statsReportPrediction(string $at, array $attributes = []): Prediction
{
    Carbon::setTestNow($at);
    $prediction = createPredictionDataset(User::factory()->create(), $attributes)['prediction'];
    Carbon::setTestNow();

    return $prediction;
}

test('the monthly report counts the predictions of the previous calendar month', function () {
    $manager = createPredictionManager();
    $result = fn (): int => PredictionResult::query()->create(['data' => []])->id;

    // February 2026: added, optimized (COSMO step reached), COSMO done and failed.
    statsReportPrediction('2026-02-01 00:00:00', ['state' => Prediction::STATE_PREPARED, 'step' => Prediction::STEP_PENDING]);
    statsReportPrediction('2026-02-10 12:00:00', ['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO]);
    statsReportPrediction('2026-02-28 23:59:59', ['state' => Prediction::STATE_FINISHED, 'step' => Prediction::STEP_RESULT_DB_STORE, 'result_id' => $result()]);
    statsReportPrediction('2026-02-15 08:00:00', ['state' => Prediction::STATE_ERROR, 'step' => Prediction::STEP_OPTIMIZATION]);
    // Outside the period; the paused prediction is not counted as running.
    statsReportPrediction('2026-01-31 23:59:59', ['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO]);
    statsReportPrediction('2026-03-01 00:00:00', ['state' => Prediction::STATE_STOPPED, 'step' => Prediction::STEP_COSMO, 'result_id' => $result()]);
    statsReportPrediction('2026-03-02 00:00:00', ['state' => Prediction::STATE_RUNNING, 'step' => Prediction::STEP_COSMO, 'remote_paused_at' => '2026-03-02 00:00:00']);

    $this->travelTo('2026-03-15 10:00:00');

    $this->artisan(SendPredictionAdminStatsNotification::class, ['period' => 'month'])
        ->expectsOutputToContain('Sent admin stats report (February 2026): added=4 optimized=2 cosmo_done=1 failed=1.')
        ->assertSuccessful();

    $report = UserNotification::query()->where('user_id', $manager->id)->sole();

    expect($report->data)->toMatchArray([
        'period_label' => 'February 2026',
        'period_from' => '2026-02-01',
        'period_to' => '2026-02-28',
        'added' => 4,
        'optimized' => 2,
        'cosmo_done' => 1,
        'failed' => 1,
        'running' => 2,
        'total_all' => 7,
    ]);

    $activity = Activity::query()->where('event', 'prediction_admin_stats_sent')->sole();

    expect($activity->description)->toBe('Prediction admin statistics report sent for February 2026.')
        ->and($activity->properties->only(['period', 'added', 'running', 'total'])->all())->toBe([
            'period' => 'month',
            'added' => 4,
            'running' => 2,
            'total' => 7,
        ]);
});

test('the yearly report covers the whole previous calendar year', function () {
    $manager = createPredictionManager();
    statsReportPrediction('2025-01-01 00:00:00');
    statsReportPrediction('2025-12-31 23:59:59');
    statsReportPrediction('2024-12-31 23:59:59');
    statsReportPrediction('2026-01-01 00:00:00');

    $this->travelTo('2026-06-30 12:00:00');

    $this->artisan(SendPredictionAdminStatsNotification::class, ['period' => 'year'])
        ->expectsOutputToContain('Sent admin stats report (2025): added=2')
        ->assertSuccessful();

    expect(UserNotification::query()->where('user_id', $manager->id)->sole()->data)->toMatchArray([
        'period_label' => '2025',
        'period_from' => '2025-01-01',
        'period_to' => '2025-12-31',
        'added' => 2,
        'total_all' => 4,
    ]);
});

test('the monthly report does not overflow from the end of a long month', function () {
    $manager = createPredictionManager();
    $this->travelTo('2026-03-31 08:00:00');

    $this->artisan(SendPredictionAdminStatsNotification::class, ['period' => 'month'])->assertSuccessful();

    expect(UserNotification::query()->where('user_id', $manager->id)->sole()->data)->toMatchArray([
        'period_label' => 'February 2026',
        'period_from' => '2026-02-01',
        'period_to' => '2026-02-28',
    ]);
});
