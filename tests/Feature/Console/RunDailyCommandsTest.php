<?php

use App\Console\Commands\CheckStructureInternalIdentifiers;
use App\Console\Commands\CleanupExpiredDownloadFiles;
use App\Console\Commands\Cron\RunDailyCommands;
use App\Console\Commands\Database\BackupDb;
use App\Console\Commands\Database\BackupDbIdsm;
use App\Console\Commands\Database\BackupDbPredictions;
use App\Console\Commands\GenerateSitemap;
use App\Console\Commands\UpdateExportFiles;
use App\Console\Commands\UpdateStatistics;
use App\Enums\PermissionEnums;
use App\Models\Config;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;

/*
 * cron:daily runs every maintenance command, keeps going when one fails,
 * e-mails a summary and alerts system monitors about failed backups. The
 * real commands are replaced by stand-ins, so only the orchestration runs.
 */

final class DailyCommandStandIns
{
    /** @var array<int, string> */
    public static array $ran = [];

    /** @var array<string, int|Throwable> */
    public static array $outcomes = [];
}

/**
 * Registers a stand-in under the command's name, which is how Artisan finds
 * the command cron:daily calls: it records that it ran, then exits with or
 * throws the configured outcome.
 *
 * @param  class-string<Command>  $commandClass
 */
function standInForDailyCommand(string $commandClass): void
{
    $name = app()->make($commandClass)->getName();

    Artisan::registerCommand(new class($commandClass, $name) extends Command
    {
        public function __construct(private readonly string $commandClass, string $name)
        {
            $this->signature = $name.' {--startId=}';
            parent::__construct();
        }

        public function handle(): int
        {
            DailyCommandStandIns::$ran[] = $this->commandClass;
            $outcome = DailyCommandStandIns::$outcomes[$this->commandClass] ?? self::SUCCESS;

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }
    });
}

beforeEach(function () {
    $this->storageDirectory = sys_get_temp_dir().'/molmedb-daily-'.uniqid();
    File::ensureDirectoryExists($this->storageDirectory.'/logs');
    app()->useStoragePath($this->storageDirectory);

    DailyCommandStandIns::$ran = [];
    DailyCommandStandIns::$outcomes = [];

    foreach ([
        CleanupExpiredDownloadFiles::class,
        UpdateStatistics::class,
        UpdateExportFiles::class,
        GenerateSitemap::class,
        CheckStructureInternalIdentifiers::class,
        BackupDb::class,
        BackupDbPredictions::class,
        BackupDbIdsm::class,
    ] as $commandClass) {
        standInForDailyCommand($commandClass);
    }

    Config::set('email:cron:failure', 'ops@example.org; admin@example.org');
    Notification::fake();

    Permission::query()->firstOrCreate(
        ['name' => PermissionEnums::SYSTEM_MONITOR->value, 'guard_name' => 'web'],
        ['description' => PermissionEnums::SYSTEM_MONITOR->description()],
    );
    NotificationTemplate::query()->updateOrCreate(['key' => NotificationTemplate::KEY_SYSTEM_ADMIN_BACKUP_FAILED], [
        'name' => 'Backup failed',
        'notification_title' => 'Backup {{ label }} failed',
        'notification_body' => '{{ error }}',
        'email_subject' => 'Backup failed',
        'email_message' => '<p>{{ error }}</p>',
        'is_active' => true,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->storageDirectory);
});

/**
 * @return array<int, Email>
 */
function sentDailySummaries(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($message) => $message->getOriginalMessage())
        ->all();
}

function createSystemMonitor(): User
{
    $role = Role::query()->create(['name' => 'Monitors', 'guard_name' => 'web']);
    $role->givePermissionTo(PermissionEnums::SYSTEM_MONITOR->value);
    $monitor = User::factory()->create();
    $monitor->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $monitor->refresh();
}

test('every daily command runs and a summary is e-mailed and logged', function () {
    $this->artisan(RunDailyCommands::class)->assertSuccessful();

    expect(DailyCommandStandIns::$ran)->toHaveCount(8)
        ->and(File::exists(storage_path('logs/cron/daily/'.now()->toDateString().'/summary.log')))->toBeTrue();

    $summaries = sentDailySummaries();

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->getSubject())->toContain('Daily cron completed')
        ->and(collect($summaries[0]->getTo())->map->getAddress()->all())->toBe(['ops@example.org', 'admin@example.org']);
});

test('a failing backup does not stop the other commands and alerts system monitors', function () {
    $monitor = createSystemMonitor();
    DailyCommandStandIns::$outcomes[BackupDb::class] = Command::FAILURE;
    DailyCommandStandIns::$outcomes[UpdateStatistics::class] = new RuntimeException('Statistics table is locked.');

    $this->artisan(RunDailyCommands::class)->assertFailed();

    expect(DailyCommandStandIns::$ran)->toHaveCount(8)
        ->and(sentDailySummaries()[0]->getSubject())->toContain('Daily cron failed');

    // Only the backup failure alerts; a failing non-backup command is in the summary.
    Notification::assertSentToTimes($monitor, TemplatedNotification::class, 1);
    Notification::assertSentTo($monitor, TemplatedNotification::class, fn (TemplatedNotification $notification): bool => $notification->notificationTitle === 'Backup Backup database (full) failed');
});

test('no summary e-mail is sent without configured recipients', function () {
    Config::set('email:cron:failure', '');

    $this->artisan(RunDailyCommands::class)->assertSuccessful();

    expect(sentDailySummaries())->toBe([]);
});

test('daily log directories older than ten days are deleted', function () {
    $old = storage_path('logs/cron/daily/'.now()->subDays(11)->toDateString());
    $recent = storage_path('logs/cron/daily/'.now()->subDays(3)->toDateString());
    File::ensureDirectoryExists($old);
    File::ensureDirectoryExists($recent);

    $this->artisan(RunDailyCommands::class)->assertSuccessful();

    expect(File::isDirectory($old))->toBeFalse()
        ->and(File::isDirectory($recent))->toBeTrue();
});
