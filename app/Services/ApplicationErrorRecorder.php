<?php

namespace App\Services;

use App\Enums\PermissionEnums;
use App\Models\NotificationTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Counts the errors the application reports, per day and kind of error, so
 * that an error nobody is told about (it happens in a request, not in a job
 * or a scheduled task) still shows in the daily system health report, and
 * tells the system monitors when one keeps repeating.
 *
 * Only what reaches the exception handler is counted: an error that the code
 * catches and writes to the log itself is not.
 */
class ApplicationErrorRecorder
{
    /**
     * Occurrences of one error in a day after which the system monitors are
     * told at once instead of with the next daily report.
     */
    public const ALERT_AFTER_OCCURRENCES = 25;

    /**
     * Kinds of errors kept for one day. Messages that differ every time
     * (they carry a name or a value) would otherwise add a row each.
     */
    public const MAX_KINDS_PER_DAY = 500;

    private static bool $recording = false;

    /**
     * Never throws: reporting an error must not raise another one.
     */
    public function record(Throwable $exception): void
    {
        if (self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $message = self::summarize($exception);
            $fingerprint = self::fingerprint($exception, $message);
            $day = now()->toDateString();

            // In a transaction of its own, so that the count and the check
            // for the alert see one state of the row.
            $error = DB::transaction(function () use ($exception, $message, $fingerprint, $day): ?object {
                $row = DB::table('application_errors')->where('day', $day)->where('fingerprint', $fingerprint);
                $seenAgain = ['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now()];

                if ((clone $row)->update($seenAgain) === 0) {
                    if (DB::table('application_errors')->where('day', $day)->count() >= self::MAX_KINDS_PER_DAY) {
                        return null;
                    }

                    $inserted = DB::table('application_errors')->insertOrIgnore([
                        'day' => $day,
                        'fingerprint' => $fingerprint,
                        'exception' => Str::limit($exception::class, 250, ''),
                        'message' => $message,
                        'location' => self::location($exception),
                        'context' => self::context(),
                        'occurrences' => 1,
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ]);

                    // Another process inserted the row in between.
                    if ($inserted === 0) {
                        (clone $row)->update($seenAgain);
                    }
                }

                return (clone $row)->first(['occurrences', 'notified_at', 'location', 'context']);
            });

            // notified_at, not "exactly the 25th": an alert that could not be
            // sent is tried again with the next occurrence.
            if ($error && $error->occurrences >= self::ALERT_AFTER_OCCURRENCES && $error->notified_at === null) {
                $this->alert($exception, $message, $fingerprint, $day, $error);
            }
        } catch (Throwable) {
            // The error itself is still written to the log by the framework.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * The message without what makes it long or private, as far as that can
     * be told: of a failed query only the first line of the database's
     * answer is kept (not the SQL with its values, and not the DETAIL line
     * that quotes the row), a value the database refused is left out, and
     * e-mail addresses are masked.
     */
    public static function summarize(Throwable $exception): string
    {
        $message = $exception instanceof QueryException && $exception->getPrevious()
            ? $exception->getPrevious()->getMessage()
            : $exception->getMessage();

        $message = Str::before($message, ' (Connection: ');

        if ($exception instanceof QueryException) {
            $message = preg_split('/\R\s*(DETAIL|CONTEXT|HINT|QUERY|LINE \d+):/', $message)[0];
            $message = preg_replace('/(invalid input [^:"]*): ".*"/s', '$1: "…"', $message) ?? $message;
        }

        $message = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/', '[e-mail]', $message) ?? $message;

        return Str::limit(Str::squish($message), 300, '…');
    }

    /**
     * The same error with another number or identifier in it (an id, a
     * block, a hash) is the same kind of error.
     */
    public static function fingerprint(Throwable $exception, string $message): string
    {
        $kind = preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\b[0-9a-f]{12,}\b/i', '/\d+/'],
            '#',
            Str::limit($message, 200, ''),
        );

        return sha1($exception::class.'|'.$kind);
    }

    /**
     * The place in the application's own code the error passed through. An
     * exception is created where it is thrown, which for a failed query is
     * inside the framework and says nothing.
     */
    private static function location(Throwable $exception): string
    {
        $frames = [
            ['file' => $exception->getFile(), 'line' => $exception->getLine()],
            ...$exception->getTrace(),
        ];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && (str_starts_with($file, app_path()) || str_starts_with($file, base_path('modules')))) {
                return self::relativePath($file, (int) ($frame['line'] ?? 0));
            }
        }

        return self::relativePath($exception->getFile(), $exception->getLine());
    }

    private static function relativePath(string $file, int $line): string
    {
        return Str::limit(Str::after($file, base_path().DIRECTORY_SEPARATOR).':'.$line, 250, '');
    }

    /**
     * What was running: the route (its pattern, not the address with its
     * values) or the console command.
     */
    private static function context(): ?string
    {
        $request = request();
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return Str::limit($request->method().' '.$route->uri(), 250, '');
        }

        if (app()->runningInConsole()) {
            return Str::limit('console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)), 250, '');
        }

        return $request->method();
    }

    private function alert(Throwable $exception, string $message, string $fingerprint, string $day, object $error): void
    {
        $description = class_basename($exception).': '.$message;

        app(NotificationService::class)->sendToPermission(
            PermissionEnums::SYSTEM_MONITOR->value,
            NotificationTemplate::KEY_SYSTEM_ADMIN_ERROR_REPEATED,
            [
                'occurrences' => $error->occurrences,
                'error' => $description,
                'location' => $error->location ?? 'unknown',
                'context' => $error->context ?? 'unknown',
            ],
        );

        DB::table('application_errors')
            ->where('day', $day)
            ->where('fingerprint', $fingerprint)
            ->update(['notified_at' => now()]);

        app(SystemActivityLogger::class)->log(
            event: 'application_error_repeated',
            description: 'Application error repeated '.$error->occurrences.'× today: '.$description,
            properties: ['exception' => $exception::class, 'fingerprint' => $fingerprint, 'location' => $error->location],
            severity: SystemActivityLogger::SEVERITY_ERROR,
        );
    }
}
