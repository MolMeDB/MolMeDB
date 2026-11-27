<?php

namespace App\Services\SystemHealth;

use App\Models\ApplicationError;
use App\Models\BaseModel;
use App\Services\ApplicationErrorRecorder;
use App\Services\SystemActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
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
    public function __construct(private readonly PredictionServerHealth $predictionServerHealth) {}

    private const MAX_DETAILS = 10;

    /** Log files above this size are worth a look (bytes). */
    private const LOGS_WARNING_BYTES = 1024 ** 3;

    /** Free space below this share of the disk is a problem. */
    private const DISK_FREE_PROBLEM_RATIO = 0.10;

    private const BINGO_INDEX = 'structures_canonical_smiles_bingo_index';

    /**
     * Comparing the index with the structures reads every structure: 140 s
     * for half a million of them on production, and their number grows.
     */
    private const BINGO_CHECK_TIMEOUT_SECONDS = 900;

    /** Waiting predictions whose logs are read for the reasons of failed imports. */
    private const IMPORT_ERRORS_SAMPLE = 300;

    /**
     * @return list<HealthCheckResult>
     */
    public function run(CarbonInterface $day): array
    {
        $checks = [
            'Application errors' => fn (): HealthCheckResult => $this->applicationErrors($day),
            'Recorded failures' => fn (): HealthCheckResult => $this->recordedFailures($day),
            'Prediction import' => fn (): HealthCheckResult => $this->predictionImport($day),
            PredictionServerHealth::LABEL => fn (): HealthCheckResult => $this->predictionServerHealth->assess()->toHealthCheckResult(),
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
     * What the system activity log holds as failed: queue jobs, scheduled
     * tasks, backups, exports, the prediction worker. The log is throttled
     * per job or task, so the numbers say that something failed, not how
     * many times.
     */
    private function recordedFailures(CarbonInterface $day): HealthCheckResult
    {
        $label = 'Recorded failures';
        $events = Activity::query()
            ->where('log_name', BaseModel::ACTIVITY_LOG_SYSTEM)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->where('properties->severity', SystemActivityLogger::SEVERITY_ERROR)
            // Already in the report, under the application errors.
            ->where('event', '!=', 'application_error_repeated')
            ->get(['description'])
            // "3 predictions failed" and "5 predictions failed" are one line.
            ->countBy(fn (Activity $activity): string => preg_replace('/\d+/', '#', (string) $activity->description))
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
     * prediction and ends. So two things are looked for here: a queue that
     * stands (predictions that waited through the whole day while nothing
     * was imported), and predictions whose last attempt ended in an error,
     * with the reasons.
     */
    private function predictionImport(CarbonInterface $day): HealthCheckResult
    {
        $label = 'Prediction import';
        $waiting = fn () => Prediction::query()
            ->where('step', Prediction::STEP_RESULT_PARSE)
            ->where('state', Prediction::STATE_FINISHED);

        $waitingCount = $waiting()->count();
        // Untouched since before the day began: neither imported nor tried.
        $standing = $waiting()->where('updated_at', '<', $day->copy()->startOfDay())->count();
        $imported = Prediction::query()
            ->where('step', Prediction::STEP_RESULT_DB_STORE)
            ->whereBetween('updated_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->count();

        // A failed attempt touches the prediction, so the failing ones are
        // among those touched last.
        $reasons = $waiting()
            ->orderByDesc('updated_at')
            ->limit(self::IMPORT_ERRORS_SAMPLE)
            ->get(['id', 'logs'])
            ->map(function (Prediction $prediction): ?string {
                $logs = is_array($prediction->logs) ? $prediction->logs : [];
                $last = $logs === [] ? null : $logs[array_key_last($logs)];

                return is_array($last) && ($last['type'] ?? null) === 'RESULT IMPORT' && ($last['context'] ?? null) === 'error'
                    ? preg_replace(['/\[[^\]]*\]/', '/\d+/'], ['[…]', '#'], Str::limit((string) ($last['message'] ?? ''), 200))
                    : null;
            })
            ->filter()
            ->countBy()
            ->sortDesc();

        $summary = $waitingCount.' '.Str::plural('prediction', $waitingCount).' waiting for import, '.$imported.' imported on '.$day->toDateString().'.';
        $details = $reasons->take(5)
            ->map(fn (int $count, string $reason): string => $count.'× '.$reason)
            ->values()
            ->all();

        if ($standing > 0 && $imported === 0) {
            return HealthCheckResult::problem($label, $summary, [
                $standing.' of them waited through the whole day and nothing was imported: the import does not move.',
                ...$details,
            ]);
        }

        if ($reasons->isNotEmpty()) {
            return HealthCheckResult::warning($label, $summary.' The last import of '.$reasons->sum().' failed.', $details);
        }

        return HealthCheckResult::ok($label, $summary);
    }

    /**
     * Structures that the Bingo index of the structure search should hold
     * but does not. This does not see a damaged index: that one refuses
     * writes, which shows among the application errors. More entries than
     * structures are not counted against the index either, since it keeps
     * the entries of rows that were changed since it was built.
     */
    private function bingoIndex(): HealthCheckResult
    {
        $label = 'Structure search index';

        if (DB::getDriverName() !== 'pgsql') {
            return HealthCheckResult::ok($label, 'Not checked: the database is not PostgreSQL.');
        }

        try {
            $indexed = DB::transaction(function (): ?array {
                // The comparison reads every structure, so it gets a limit
                // of its own: a busy database must not keep it, and the read
                // lock it holds on the structures, for longer than that.
                DB::statement("SET LOCAL statement_timeout = '".self::BINGO_CHECK_TIMEOUT_SECONDS."s'");

                if (! DB::scalar("SELECT to_regclass('public.".self::BINGO_INDEX."') IS NOT NULL")) {
                    return null;
                }

                return [
                    'in_index' => (int) DB::scalar("SELECT bingo.getindexstructurescount('public.".self::BINGO_INDEX."'::regclass)"),
                    'expected' => (int) DB::scalar('SELECT count(*) FROM structures WHERE canonical_smiles IS NOT NULL AND bingo.checkmolecule(canonical_smiles::text) IS NULL'),
                ];
            });
        } catch (QueryException $exception) {
            $error = ApplicationErrorRecorder::summarize($exception);

            // Out of time says nothing about the index; anything else it
            // answers with an error is the index itself.
            return str_contains($error, 'statement timeout')
                ? HealthCheckResult::warning($label, 'Not checked: comparing the index with the structures took too long.')
                : HealthCheckResult::problem($label, 'The index could not be read: '.$error);
        }

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
