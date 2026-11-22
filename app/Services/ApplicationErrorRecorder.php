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
 */
class ApplicationErrorRecorder
{
    /**
     * Occurrences of one error in a day after which the system monitors are
     * told at once instead of with the next daily report.
     */
    public const ALERT_AFTER_OCCURRENCES = 25;

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

            // In a transaction of its own: when the caller has one open, a
            // failure here is rolled back to a savepoint and leaves it usable.
            $occurrences = DB::transaction(function () use ($exception, $message, $fingerprint, $day): int {
                $row = DB::table('application_errors')->where('day', $day)->where('fingerprint', $fingerprint);

                if ((clone $row)->update(['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now()]) === 0) {
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
                        (clone $row)->update(['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => now()]);
                    }
                }

                return (int) (clone $row)->value('occurrences');
            });

            if ($occurrences === self::ALERT_AFTER_OCCURRENCES) {
                $this->alert($exception, $message, $fingerprint, $day);
            }
        } catch (Throwable) {
            // The error itself is still written to the log by the framework.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * The message without what makes it long or private: a failed query
     * carries its whole SQL with the values, of which only the answer of the
     * database is kept.
     */
    public static function summarize(Throwable $exception): string
    {
        $message = $exception instanceof QueryException && $exception->getPrevious()
            ? $exception->getPrevious()->getMessage()
            : $exception->getMessage();

        $message = Str::before($message, ' (Connection: ');

        return Str::limit(Str::squish($message), 500, '…');
    }

    /**
     * The same error with another number in it (an id, a block, a line of a
     * file) is the same kind of error.
     */
    public static function fingerprint(Throwable $exception, string $message): string
    {
        return sha1($exception::class.'|'.preg_replace('/\d+/', '#', Str::limit($message, 200, '')));
    }

    private static function location(Throwable $exception): string
    {
        $file = Str::after($exception->getFile(), base_path().DIRECTORY_SEPARATOR);

        return Str::limit($file.':'.$exception->getLine(), 250, '');
    }

    /**
     * Where it happened: the route (its pattern, not the address with its
     * values) or the console command.
     */
    private static function context(): ?string
    {
        if (app()->runningInConsole()) {
            return Str::limit('console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)), 250, '');
        }

        $request = request();
        $route = $request->route();
        $uri = is_object($route) && method_exists($route, 'uri') ? $route->uri() : null;

        return $uri === null ? $request->method() : Str::limit($request->method().' '.$uri, 250, '');
    }

    private function alert(Throwable $exception, string $message, string $fingerprint, string $day): void
    {
        $label = 'Application error repeated '.self::ALERT_AFTER_OCCURRENCES.'× today';
        $error = class_basename($exception).': '.$message.' ('.self::location($exception).')';

        app(SystemActivityLogger::class)->log(
            event: 'application_error_repeated',
            description: $label.': '.$error,
            properties: ['exception' => $exception::class, 'fingerprint' => $fingerprint],
            severity: SystemActivityLogger::SEVERITY_ERROR,
        );

        app(NotificationService::class)->sendToPermission(
            PermissionEnums::SYSTEM_MONITOR->value,
            NotificationTemplate::KEY_SYSTEM_ADMIN_JOB_FAILED,
            ['label' => $label, 'error' => $error],
        );

        DB::table('application_errors')
            ->where('day', $day)
            ->where('fingerprint', $fingerprint)
            ->update(['notified_at' => now()]);
    }
}
