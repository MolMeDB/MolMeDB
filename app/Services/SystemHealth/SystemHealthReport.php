<?php

namespace App\Services\SystemHealth;

use App\Models\ApplicationError;
use App\Models\BaseModel;
use App\Services\ApplicationErrorRecorder;
use App\Services\SystemActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\PredictionWorkers\Models\Prediction;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The checks of the daily system health report: what went wrong during one
 * day, and what state the parts that fail silently are in.
 */
class SystemHealthReport
{
    private const MAX_DETAILS = 10;

    /** Log files above this size are worth a look (bytes). */
    private const LOGS_WARNING_BYTES = 1024 ** 3;

    /** Free space below this share of the disk is a problem. */
    private const DISK_FREE_PROBLEM_RATIO = 0.10;

    private const BINGO_INDEX = 'structures_canonical_smiles_bingo_index';

    /**
     * @return list<HealthCheckResult>
     */
    public function run(CarbonInterface $day): array
    {
        $checks = [
            'Application errors' => fn (): HealthCheckResult => $this->applicationErrors($day),
            'Failed jobs and scheduled tasks' => fn (): HealthCheckResult => $this->systemEvents($day),
            'Prediction import' => fn (): HealthCheckResult => $this->predictionImport($day),
            'Structure search index' => fn (): HealthCheckResult => $this->bingoIndex(),
            'Logs and disk space' => fn (): HealthCheckResult => $this->storage(),
        ];

        $results = [];

        foreach ($checks as $label => $check) {
            try {
                $results[] = $check();
            } catch (Throwable $exception) {
                // A check that cannot run is itself something to look at.
                $results[] = HealthCheckResult::warning(
                    $label,
                    'Could not be checked: '.ApplicationErrorRecorder::summarize($exception),
                );
            }
        }

        return $results;
    }

    /**
     * @param  list<HealthCheckResult>  $results
     */
    public function status(array $results): string
    {
        $problems = count(array_filter($results, fn (HealthCheckResult $result): bool => $result->status === HealthCheckResult::STATUS_PROBLEM));
        $warnings = count(array_filter($results, fn (HealthCheckResult $result): bool => $result->status === HealthCheckResult::STATUS_WARNING));

        if ($problems === 0 && $warnings === 0) {
            return 'all checks passed';
        }

        return implode(', ', array_filter([
            $problems > 0 ? $problems.' '.Str::plural('problem', $problems) : null,
            $warnings > 0 ? $warnings.' '.Str::plural('warning', $warnings) : null,
        ]));
    }

    /**
     * One sentence for the notification inside the application.
     *
     * @param  list<HealthCheckResult>  $results
     */
    public function summary(array $results): string
    {
        $notOk = array_filter($results, fn (HealthCheckResult $result): bool => $result->status !== HealthCheckResult::STATUS_OK);

        if ($notOk === []) {
            return 'Nothing to look at.';
        }

        return implode(' ', array_map(
            fn (HealthCheckResult $result): string => $result->label.': '.Str::finish($result->summary, '.'),
            $notOk,
        ));
    }

    /**
     * The table for the e-mail. Everything a check found is text from the
     * database or from an exception, so all of it is escaped.
     *
     * @param  list<HealthCheckResult>  $results
     */
    public function toHtml(array $results): HtmlString
    {
        $colors = [
            HealthCheckResult::STATUS_OK => '#15803d',
            HealthCheckResult::STATUS_WARNING => '#b45309',
            HealthCheckResult::STATUS_PROBLEM => '#b91c1c',
        ];

        $rows = array_map(function (HealthCheckResult $result) use ($colors): string {
            $details = $result->details === []
                ? ''
                : '<ul style="margin:6px 0 0;padding-left:18px">'
                    .implode('', array_map(fn (string $line): string => '<li>'.e($line).'</li>', $result->details))
                    .'</ul>';

            return '<tr>'
                .'<td style="padding:8px 12px 8px 0;vertical-align:top;white-space:nowrap;color:'.$colors[$result->status].'"><strong>'.e(strtoupper($result->status)).'</strong></td>'
                .'<td style="padding:8px 0;vertical-align:top"><strong>'.e($result->label).'</strong><br>'.e($result->summary).$details.'</td>'
                .'</tr>';
        }, $results);

        return new HtmlString('<table style="border-collapse:collapse">'.implode('', $rows).'</table>');
    }

    /**
     * Errors the application reported (ApplicationErrorRecorder).
     */
    private function applicationErrors(CarbonInterface $day): HealthCheckResult
    {
        $label = 'Application errors';
        $errors = ApplicationError::query()
            ->whereDate('day', $day->toDateString())
            ->orderByDesc('occurrences')
            ->orderBy('id')
            ->get();

        if ($errors->isEmpty()) {
            return HealthCheckResult::ok($label, 'No error was reported.');
        }

        $total = (int) $errors->sum('occurrences');
        $summary = $total.' '.Str::plural('error', $total).' of '.$errors->count().' '.Str::plural('kind', $errors->count()).' reported.';

        $details = $errors->take(self::MAX_DETAILS)->map(fn (ApplicationError $error): string => sprintf(
            '%d× %s: %s — %s%s',
            $error->occurrences,
            class_basename($error->exception),
            $error->message,
            $error->location ?? 'unknown place',
            $error->context ? ' ['.$error->context.']' : '',
        ))->values()->all();

        if ($errors->count() > self::MAX_DETAILS) {
            $details[] = '… and '.($errors->count() - self::MAX_DETAILS).' more kinds.';
        }

        // The same error over and over is something broken, not a visitor's bad request.
        return $errors->max('occurrences') >= ApplicationErrorRecorder::ALERT_AFTER_OCCURRENCES
            ? HealthCheckResult::problem($label, $summary, $details)
            : HealthCheckResult::warning($label, $summary, $details);
    }

    /**
     * Failed queue jobs, scheduled tasks, backups and notifications, as the
     * system activity log holds them. The log is throttled per job or task,
     * so the numbers say that something failed, not how many times.
     */
    private function systemEvents(CarbonInterface $day): HealthCheckResult
    {
        $label = 'Failed jobs and scheduled tasks';
        $events = Activity::query()
            ->where('log_name', BaseModel::ACTIVITY_LOG_SYSTEM)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->where('properties->severity', SystemActivityLogger::SEVERITY_ERROR)
            ->get(['description'])
            ->countBy('description')
            ->sortDesc();

        if ($events->isEmpty()) {
            return HealthCheckResult::ok($label, 'Nothing failed.');
        }

        $details = $events->take(self::MAX_DETAILS)
            ->map(fn (int $count, string $description): string => $count.'× '.$description)
            ->values()
            ->all();

        if ($events->count() > self::MAX_DETAILS) {
            $details[] = '… and '.($events->count() - self::MAX_DETAILS).' more.';
        }

        return HealthCheckResult::problem(
            $label,
            $events->sum().' '.Str::plural('failure', $events->sum()).' recorded.',
            $details,
        );
    }

    /**
     * The import job never fails as a whole: it notes an error on each
     * prediction and ends. So a queue that only grows is looked for here.
     */
    private function predictionImport(CarbonInterface $day): HealthCheckResult
    {
        $label = 'Prediction import';

        $waiting = Prediction::query()
            ->where('step', Prediction::STEP_RESULT_PARSE)
            ->where('state', Prediction::STATE_FINISHED)
            ->count();

        $imported = Prediction::query()
            ->where('step', Prediction::STEP_RESULT_DB_STORE)
            ->whereBetween('updated_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->count();

        $summary = $waiting.' '.Str::plural('prediction', $waiting).' waiting for import, '.$imported.' imported on '.$day->toDateString().'.';

        if ($waiting > 0 && $imported === 0) {
            return HealthCheckResult::problem($label, $summary, [
                'Nothing was imported although predictions are waiting: the reason is in their logs (RESULT IMPORT).',
            ]);
        }

        return HealthCheckResult::ok($label, $summary);
    }

    /**
     * Structures that the Bingo index of the structure search should hold
     * but does not. More entries than structures are not counted against it:
     * the index also keeps entries of rows that were changed since.
     */
    private function bingoIndex(): HealthCheckResult
    {
        $label = 'Structure search index';

        if (DB::getDriverName() !== 'pgsql') {
            return HealthCheckResult::ok($label, 'Not checked: the database is not PostgreSQL.');
        }

        $indexed = DB::transaction(function (): ?array {
            // The comparison reads every structure; it must not hold a
            // connection for long when the database is busy.
            DB::statement("SET LOCAL statement_timeout = '120s'");

            $exists = DB::scalar("SELECT to_regclass('public.".self::BINGO_INDEX."') IS NOT NULL");

            if (! $exists) {
                return null;
            }

            return [
                'in_index' => (int) DB::scalar("SELECT bingo.getindexstructurescount('public.".self::BINGO_INDEX."'::regclass)"),
                'expected' => (int) DB::scalar('SELECT count(*) FROM structures WHERE canonical_smiles IS NOT NULL AND bingo.checkmolecule(canonical_smiles::text) IS NULL'),
            ];
        });

        if ($indexed === null) {
            return HealthCheckResult::problem($label, 'The index '.self::BINGO_INDEX.' does not exist.');
        }

        $summary = $indexed['in_index'].' entries in the index, '.$indexed['expected'].' structures that belong in it.';

        return $indexed['in_index'] < $indexed['expected']
            ? HealthCheckResult::problem($label, $summary, ['Structures are missing from the index: rebuild it with REINDEX INDEX '.self::BINGO_INDEX.'.'])
            : HealthCheckResult::ok($label, $summary);
    }

    private function storage(): HealthCheckResult
    {
        $label = 'Logs and disk space';
        $logsPath = storage_path('logs');
        $logsBytes = 0;
        $largest = null;

        if (is_dir($logsPath)) {
            foreach (Finder::create()->files()->in($logsPath) as $file) {
                $logsBytes += $file->getSize();

                if ($largest === null || $file->getSize() > $largest->getSize()) {
                    $largest = $file;
                }
            }
        }

        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        $details = [];
        $status = HealthCheckResult::STATUS_OK;

        $summary = 'Logs take '.Number::fileSize($logsBytes, 1);

        if ($logsBytes > self::LOGS_WARNING_BYTES && $largest !== null) {
            $status = HealthCheckResult::STATUS_WARNING;
            $details[] = 'The largest log is '.$largest->getRelativePathname().' ('.Number::fileSize($largest->getSize(), 1).').';
        }

        if ($total && $free !== false) {
            $summary .= ', '.Number::fileSize($free, 1).' of '.Number::fileSize($total, 1).' free on the disk';

            if ($free / $total < self::DISK_FREE_PROBLEM_RATIO) {
                $status = HealthCheckResult::STATUS_PROBLEM;
                $details[] = 'Less than '.(int) (self::DISK_FREE_PROBLEM_RATIO * 100).' % of the disk is free.';
            }
        }

        return new HealthCheckResult($label, $status, $summary.'.', $details);
    }
}
