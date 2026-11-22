<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/deployment_test_helpers.php';

/*
 * docker/production/move-to-standalone.sh moves a running installation out
 * of its checkout. What it must never do is leave the site down or start the
 * new installation without proven backups, so that is what is tried here,
 * against a docker that only writes down what it was asked to do.
 */

/**
 * @return array{old: string, new: string, backups: string, log: string, state: string, env: array<string, string>}
 */
function installationToMove(): array
{
    $fakes = deploymentFakes();
    $old = $fakes['base'].'/var/www/MolMeDB-api';

    mkdir($old.'/frontend', 0777, true);
    mkdir($old.'/storage/app/public', 0777, true);
    mkdir($old.'/storage/logs');
    file_put_contents($old.'/storage/app/public/export.csv', "id\n1\n");
    file_put_contents($old.'/storage/logs/laravel.log', "started\n");
    file_put_contents($old.'/.env', "APP_NAME=MolMeDB\nDB_PASSWORD=secret\n");
    file_put_contents($old.'/frontend/.env', "JWT_SECRET=frontend-secret\n");
    file_put_contents($old.'/docker-compose.yaml', "services: {} # built on the server\n");

    return [
        'old' => $old,
        'new' => $fakes['base'].'/srv/app/MolMeDB',
        'backups' => $fakes['base'].'/backups',
        'log' => $fakes['log'],
        'state' => $fakes['base'].'/state',
        'env' => $fakes['env'] + [
            'BACKUP_DIR' => $fakes['base'].'/backups',
            'FAKE_WORKING_DIR' => realpath($old),
        ],
    ];
}

/**
 * @param  array{old: string, new: string, backups: string, log: string, state: string, env: array<string, string>}  $installation
 * @param  array<string, string>  $env
 */
function moveInstallation(array $installation, array $env = [], string $version = '1.4.0'): Process
{
    $process = new Process(
        [deploymentScript('move-to-standalone.sh'), $version, $installation['old'], $installation['new']],
        null,
        $env + $installation['env'],
    );
    $process->run();

    return $process;
}

/**
 * The docker calls that match, in the order they were made.
 *
 * @return list<string>
 */
function dockerCalls(string $log, string $containing): array
{
    return array_values(array_filter(
        explode("\n", file_get_contents($log)),
        fn (string $call): bool => str_contains($call, $containing),
    ));
}

function positionOfCall(string $log, string $containing): int
{
    foreach (explode("\n", file_get_contents($log)) as $position => $call) {
        if (str_contains($call, $containing)) {
            return $position;
        }
    }

    throw new RuntimeException("Never called: {$containing}");
}

afterEach(fn () => removeDeploymentFakes());

test('the installation is backed up, proven and then started from the new directory', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    $new = $installation['new'];
    expect(file_get_contents($new.'/.env'))->toBe("APP_NAME=MolMeDB\nDB_PASSWORD=secret\nMOLMEDB_VERSION=1.4.0\n")
        ->and(file_get_contents($new.'/frontend/.env'))->toBe("JWT_SECRET=frontend-secret\n")
        ->and(file_get_contents($new.'/compose.yaml'))->toContain('release compose')
        ->and(is_executable($new.'/deploy.sh'))->toBeTrue()
        ->and(file_get_contents($new.'/storage/app/public/export.csv'))->toBe("id\n1\n")
        ->and(file_get_contents($new.'/storage/logs/laravel.log'))->toBe("started\n");

    $backups = $installation['backups'];
    foreach ([
        'main-db.dump', 'main-db.globals.sql', 'main-db.volume.tar', 'main-db.counts',
        'predictions-db.dump', 'predictions-db.globals.sql', 'predictions-db.volume.tar',
        'redis.volume.tar', 'storage.tar', 'config/env', 'config/frontend.env',
        'config/docker-compose.yaml', 'SHA256SUMS', 'ROLLBACK.txt',
    ] as $file) {
        expect(filesize($backups.'/'.$file))->toBeGreaterThan(0, $file);
    }

    expect(file_get_contents($backups.'/config/env'))->toBe("APP_NAME=MolMeDB\nDB_PASSWORD=secret\n")
        ->and(file_get_contents($backups.'/SHA256SUMS'))->toContain('main-db.dump')->toContain('storage.tar')
        ->and(file_get_contents($backups.'/ROLLBACK.txt'))
        ->toContain('molmedb-api_molmedb-db:/data')
        ->toContain('docker rm -f molmedb-nginx molmedb-frontend')
        ->toContain('[ -z "$(docker ps -aq --filter volume=molmedb-api_molmedb-db)" ] && \\')
        ->toContain('tar -tf /backup/main-db.volume.tar > /dev/null && find /data -mindepth 1 -delete')
        ->toContain('predictions-db.volume.tar')
        ->toContain('molmedb-api_molmedb-redis:/data')
        ->toContain('sha256:0123abcd')
        ->toContain('cd '.realpath($installation['old']).' && docker compose -p molmedb-api -f '.realpath($installation['old']).'/docker-compose.yaml up -d --no-build');

    // The checksums cover everything, the way back included.
    expect(file_get_contents($backups.'/SHA256SUMS'))->toContain('ROLLBACK.txt')->toContain('main-db.restored.counts');

    // The old installation stays as it was: it is the way back.
    expect(file_get_contents($installation['old'].'/.env'))->toBe("APP_NAME=MolMeDB\nDB_PASSWORD=secret\n")
        ->and(file_exists($installation['old'].'/storage/app/public/export.csv'))->toBeTrue();

    // Nothing is written while the databases are read, and the new
    // installation starts only after the restore was compared.
    $log = $installation['log'];
    expect(positionOfCall($log, 'docker stop molmedb-nginx'))->toBeGreaterThan(positionOfCall($log, 'manifest inspect'))
        // Everything from the network and every check of the images comes while the site still runs.
        ->and(positionOfCall($log, 'docker stop molmedb-nginx'))->toBeGreaterThan(positionOfCall($log, '/compose.yaml pull'))
        ->and(positionOfCall($log, 'docker stop molmedb-nginx'))->toBeGreaterThan(positionOfCall($log, 'probe.tar'))
        ->and(positionOfCall($log, 'docker stop molmedb-nginx'))->toBeGreaterThan(positionOfCall($log, '--entrypoint ldd ghcr.io/molmedb/molmedb-db:15'))
        ->and(positionOfCall($log, 'pg_dump '))->toBeGreaterThan(positionOfCall($log, 'docker stop molmedb-backend'))
        ->and(positionOfCall($log, 'main-db.volume.tar'))->toBeGreaterThan(positionOfCall($log, 'docker stop -t 300 molmedb-dev-db molmedb-predictions-db'))
        ->and(dockerCalls($log, '--name molmedb-restore-test --network none'))->toHaveCount(2)
        ->and(positionOfCall($log, 'up -d'))->toBeGreaterThan(positionOfCall($log, '-d restore_test'))
        ->and(dockerCalls($log, 'compose -f compose.yaml up -d'))->toHaveCount(1)
        // deploy.sh used the compose file that was checked, not a second download.
        ->and(dockerCalls($log, '/v1.4.0/compose.yaml'))->toHaveCount(1);
});

test('volumes under another project name are kept by naming the project in the new .env', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_PROJECT' => 'molmedb']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(file_get_contents($installation['new'].'/.env'))
        ->toBe("APP_NAME=MolMeDB\nDB_PASSWORD=secret\n\nCOMPOSE_PROJECT_NAME=molmedb\nMOLMEDB_VERSION=1.4.0\n")
        ->and(file_get_contents($installation['backups'].'/ROLLBACK.txt'))->toContain('molmedb_molmedb-db:/data');
});

test('database volumes that the new compose file would not use stop the move before anything is touched', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_MAIN_VOLUME' => 'a1b2c3-anonymous']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Unexpected database volumes')
        ->and(dockerCalls($installation['log'], 'docker stop'))->toBe([])
        ->and(file_exists($installation['new'].'/storage'))->toBeFalse();
});

test('a release that is not published stops the move before anything is touched', function (array $env) {
    $installation = installationToMove();

    $process = moveInstallation($installation, $env);

    expect($process->getExitCode())->toBe(1)
        ->and(dockerCalls($installation['log'], 'docker stop'))->toBe([]);
})->with([
    'an image is missing' => [['FAKE_MISSING_IMAGE' => 'molmedb-nginx']],
    'the release files are missing' => [['FAKE_DOWNLOAD_FAILS' => '1']],
    'the compose file is not valid' => [['FAKE_INVALID_COMPOSE' => '1']],
]);

test('a failed backup starts the old installation again and moves nothing', function (array $env, string $message) {
    $installation = installationToMove();

    $process = moveInstallation($installation, $env);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain($message)->toContain('Nothing was moved')
        ->and(dockerCalls($installation['log'], 'up -d'))->toBe([])
        ->and(file_exists($installation['new'].'/storage'))->toBeFalse()
        ->and(file_exists($installation['new'].'/compose.yaml'))->toBeFalse();

    // Every container that was stopped runs again.
    expect(glob($installation['state'].'/*'))->toBe([])
        ->and(dockerCalls($installation['log'], 'docker start molmedb-backend'))->toHaveCount(1)
        ->and(dockerCalls($installation['log'], 'docker start molmedb-nginx'))->toHaveCount(1);
})->with([
    'pg_dump fails' => [['FAKE_DUMP_FAILS' => '1'], 'The move did not finish'],
    'pg_restore fails' => [['FAKE_RESTORE_FAILS' => '1'], 'pg_restore reported errors for main-db'],
    'a restored table has other rows' => [['FAKE_RESTORED_ROWS' => '4'], 'the restored database differs from the real one'],
]);

test('a failure while the databases are stopped starts them, Redis and the application again', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_ARCHIVE_FAILS' => '1']);

    $log = $installation['log'];
    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('Nothing was moved')
        ->and(glob($installation['state'].'/*'))->toBe([])
        ->and(positionOfCall($log, 'docker start molmedb-dev-db'))
        ->toBeGreaterThan(positionOfCall($log, 'docker stop -t 300 molmedb-dev-db molmedb-predictions-db'))
        ->and(dockerCalls($log, 'docker start molmedb-predictions-db'))->toHaveCount(1)
        ->and(dockerCalls($log, 'docker start molmedb-redis'))->toHaveCount(1)
        ->and(positionOfCall($log, 'docker start molmedb-backend'))->toBeGreaterThan(positionOfCall($log, 'docker start molmedb-dev-db'))
        ->and(dockerCalls($log, 'up -d'))->toBe([]);
});

test('the old installation is started again even when its output can no longer be written', function () {
    $installation = installationToMove();

    // The reader of the output goes away right after the application was
    // stopped, as a lost connection or a closed `| tee` would.
    $process = new Process(
        ['bash', '-c', '"$@" 2>&1 | sed "/stopped molmedb-backend/q" > /dev/null', 'move', deploymentScript('move-to-standalone.sh'), '1.4.0', $installation['old'], $installation['new']],
        null,
        ['FAKE_DUMP_SLEEP' => '1'] + $installation['env'],
    );
    $process->run();

    expect(dockerCalls($installation['log'], 'docker stop molmedb-backend'))->toHaveCount(1)
        ->and(dockerCalls($installation['log'], 'docker start molmedb-backend'))->toHaveCount(1)
        ->and(dockerCalls($installation['log'], 'docker start molmedb-nginx'))->toHaveCount(1)
        ->and(dockerCalls($installation['log'], 'up -d'))->toBe([])
        ->and(glob($installation['state'].'/*'))->toBe([]);
});

test('an interrupted move starts the old installation again', function () {
    $installation = installationToMove();

    $process = new Process(
        [deploymentScript('move-to-standalone.sh'), '1.4.0', $installation['old'], $installation['new']],
        null,
        ['FAKE_DUMP_SLEEP' => '2'] + $installation['env'],
    );
    $process->start();
    $process->waitUntil(fn (): bool => str_contains(file_get_contents($installation['log']), 'pg_dump '));
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(130)
        ->and($process->getErrorOutput())->toContain('Nothing was moved')
        ->and(glob($installation['state'].'/*'))->toBe([])
        ->and(dockerCalls($installation['log'], 'docker start molmedb-nginx'))->toHaveCount(1)
        ->and(dockerCalls($installation['log'], 'up -d'))->toBe([]);
});

test('a deployment that fails before it replaces a container starts the old installation again', function () {
    $installation = installationToMove();

    // The first pull is the one made while the site still runs; the second is deploy.sh's.
    $process = moveInstallation($installation, ['FAKE_PULL_FAILS_ON' => '2']);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())
        ->toContain('before any container was replaced')
        ->toContain('Nothing was moved')
        ->not->toContain('ROLLBACK.txt')
        ->and(dockerCalls($installation['log'], 'up -d'))->toBe([])
        ->and(glob($installation['state'].'/*'))->toBe([])
        ->and(dockerCalls($installation['log'], 'docker start molmedb-backend'))->toHaveCount(1)
        // The new directory is empty again, so the move can be repeated.
        ->and(array_diff(scandir($installation['new']), ['.', '..']))->toBe([])
        // Nothing was moved, so the backups do not describe a way back.
        ->and(file_exists($installation['backups'].'/ROLLBACK.txt'))->toBeFalse()
        ->and(file_get_contents($installation['backups'].'/SHA256SUMS'))->toContain('main-db.dump')->not->toContain('ROLLBACK');

    $second = moveInstallation(['backups' => $installation['backups'].'-2'] + $installation, ['BACKUP_DIR' => $installation['backups'].'-2']);

    expect($second->getExitCode())->toBe(0, $second->getOutput().$second->getErrorOutput());
});

test('a move cut short after the containers were replaced does not start the old ones or empty the new directory', function () {
    $installation = installationToMove();

    // deploy.sh watches the new application for a few seconds; the signal arrives meanwhile.
    $process = new Process(
        [deploymentScript('move-to-standalone.sh'), '1.4.0', $installation['old'], $installation['new']],
        null,
        ['DEPLOY_WATCH_INTERVAL' => '0.2'] + $installation['env'],
    );
    $process->start();
    $process->waitUntil(fn (): bool => str_contains(file_get_contents($installation['log']), 'up -d'));
    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(130)
        ->and($process->getErrorOutput())
        ->toContain('after the containers were replaced')
        ->toContain('ROLLBACK.txt')
        ->not->toContain('Nothing was moved')
        ->and(dockerCalls($installation['log'], 'docker start molmedb-nginx'))->toBe([])
        ->and(dockerCalls($installation['log'], 'docker start molmedb-backend'))->toBe([])
        ->and(file_exists($installation['new'].'/storage/app/public/export.csv'))->toBeTrue()
        ->and(file_exists($installation['new'].'/compose.yaml'))->toBeTrue()
        ->and(file_get_contents($installation['new'].'/.env'))->toContain('DB_PASSWORD=secret')
        ->and(file_exists($installation['backups'].'/ROLLBACK.txt'))->toBeTrue();
});

test('nothing is stopped when the move cannot be prepared', function (array $env, string $message) {
    $installation = installationToMove();

    $process = moveInstallation($installation, $env);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($message)
        ->and(dockerCalls($installation['log'], 'docker stop'))->toBe([]);
})->with([
    'the images cannot be pulled' => [['FAKE_PULL_FAILS' => '1'], ''],
    'the new compose file names other volumes' => [['FAKE_CONFIG_PROJECT' => 'molmedb'], 'would not use the volume molmedb-api_molmedb-db'],
    'the published database image has another C library' => [['FAKE_NEW_LIBC' => '2.41'], 'another C library'],
    'a container cannot write the backups' => [['FAKE_PROBE_FAILS' => '1'], 'cannot write into'],
    'the site runs from another directory' => [['FAKE_WORKING_DIR' => '/var/www/other'], "was started from '/var/www/other'"],
]);

test('another C library in the database image can be accepted', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_NEW_LIBC' => '2.41', 'MOVE_ALLOW_LIBC_CHANGE' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

test('a storage directory that is a link is refused', function () {
    $installation = installationToMove();
    rename($installation['old'].'/storage', $installation['old'].'/real-storage');
    symlink($installation['old'].'/real-storage', $installation['old'].'/storage');

    $process = moveInstallation($installation);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('must be a real directory')
        ->and(file_get_contents($installation['log']))->toBe('');
});

test('backups are not put into the directory that is moved', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['BACKUP_DIR' => $installation['old'].'/storage/backups']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('must not be inside the old directory');
});

test('the backups can be read by nobody else', function () {
    $installation = installationToMove();

    moveInstallation($installation, ['BACKUP_ONLY' => '1']);

    foreach (['', '/main-db.dump', '/main-db.globals.sql', '/config/env', '/storage.tar'] as $file) {
        expect(fileperms($installation['backups'].$file) & 0077)->toBe(0, $file);
    }
});

test('a rehearsal makes and proves the backups and leaves the old installation running', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['BACKUP_ONLY' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('nothing was moved')
        ->and(filesize($installation['backups'].'/main-db.dump'))->toBeGreaterThan(0)
        ->and(file_get_contents($installation['backups'].'/SHA256SUMS'))->toContain('main-db.volume.tar')
        // Nothing was moved, so there is no way back to describe.
        ->and(file_exists($installation['backups'].'/ROLLBACK.txt'))->toBeFalse()
        ->and(file_exists($installation['new']))->toBeFalse()
        ->and(dockerCalls($installation['log'], 'up -d'))->toBe([])
        ->and(glob($installation['state'].'/*'))->toBe([]);
});

test('the dumps are not restored when that is asked for', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['SKIP_RESTORE_TEST' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(dockerCalls($installation['log'], 'docker run -d --name molmedb-restore-test'))->toBe([])
        ->and(dockerCalls($installation['log'], 'pg_restore --list'))->toHaveCount(2);
});

test('a new installation that does not come up is not answered by starting the old containers', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_APP_STATE' => 'restarting 4']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('ROLLBACK.txt')->not->toContain('Nothing was moved')
        ->and(dockerCalls($installation['log'], 'docker start molmedb-nginx'))->toBe([]);
});

test('a site that does not answer after the move is reported', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['FAKE_SITE_DOWN' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('NO ANSWER: https://admin.molmedb.upol.cz/up')
        ->and($process->getOutput())->toContain('2 check(s) above failed');
});

test('a directory that already holds something is not used', function () {
    $installation = installationToMove();
    mkdir($installation['new'], 0777, true);
    file_put_contents($installation['new'].'/.env', "APP_NAME=Other\n");

    $process = moveInstallation($installation);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('is not empty')
        ->and(file_get_contents($installation['log']))->toBe('')
        ->and(file_get_contents($installation['new'].'/.env'))->toBe("APP_NAME=Other\n");
});

test('a directory that is not the running installation is refused', function () {
    $installation = installationToMove();
    unlink($installation['old'].'/frontend/.env');

    $process = moveInstallation($installation);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('frontend/.env')
        ->and(file_get_contents($installation['log']))->toBe('');
});

test('the move is not done without being root', function () {
    $installation = installationToMove();

    $process = moveInstallation($installation, ['MOVE_ALLOW_NON_ROOT' => '0']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Run this as root')
        ->and(file_get_contents($installation['log']))->toBe('');
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');
