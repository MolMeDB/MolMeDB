<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/deployment_test_helpers.php';

/*
 * docker/production/deploy.sh runs on the production server, where a mistake
 * is found out the hard way. It is run here against a docker and a curl that
 * only write down what they were asked to do.
 */

/**
 * @return array{root: string, script: string, log: string, env: array<string, string>}
 */
function deploymentSandbox(bool $checkout = false): array
{
    $fakes = deploymentFakes();
    $root = $fakes['base'].'/app';
    mkdir($root);

    if ($checkout) {
        mkdir($root.'/docker/production', 0777, true);
        $script = $root.'/docker/production/deploy.sh';
        file_put_contents($root.'/docker-compose-production.yaml', "services: {} # checkout compose\n");
    } else {
        $script = $root.'/deploy.sh';
        file_put_contents($root.'/compose.yaml', "services: {} # previous compose\n");
    }

    copy(deploymentScript('deploy.sh'), $script);
    chmod($script, 0755);
    file_put_contents($root.'/.env', "APP_NAME=MolMeDB\nMOLMEDB_VERSION=1.3.0\nDB_PASSWORD=secret");

    return ['root' => $root, 'script' => $script, 'log' => $fakes['log'], 'env' => $fakes['env']];
}

/**
 * @param  array{root: string, script: string, log: string, env: array<string, string>}  $sandbox
 * @param  array<string, string>  $env
 */
function runDeployment(array $sandbox, string $version, array $env = []): Process
{
    $process = new Process([$sandbox['script'], $version], null, $env + $sandbox['env']);
    $process->run();

    return $process;
}

afterEach(fn () => removeDeploymentFakes());

test('a deployment directory gets the compose file of the release and the new version', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, 'v1.4.0');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($sandbox['root'].'/.env'))
        ->toBe("APP_NAME=MolMeDB\nDB_PASSWORD=secret\nMOLMEDB_VERSION=1.4.0\n")
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('release compose')
        ->and(glob($sandbox['root'].'/{,.}*deploy*', GLOB_BRACE))->toBe([$sandbox['root'].'/deploy.sh']);

    $calls = file_get_contents($sandbox['log']);

    expect($calls)->toContain('/releases/download/v1.4.0/compose.yaml')
        ->toContain('compose -f compose.yaml up -d')
        ->toMatch('/compose -f \.compose\.deploy\.\w+ pull web /');
});

test('the images are pulled with the new compose file before anything changes', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['FAKE_PULL_FAILS' => '1']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.3.0')
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(file_get_contents($sandbox['log']))->not->toContain('up -d')
        ->and(glob($sandbox['root'].'/.compose.deploy.*'))->toBe([]);
});

test('a release whose compose file cannot be downloaded changes nothing', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['FAKE_DOWNLOAD_FAILS' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('MOLMEDB_KEEP_COMPOSE=1')
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.3.0')
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(file_get_contents($sandbox['log']))->not->toContain(' pull ')
        ->and(glob($sandbox['root'].'/.compose.deploy.*'))->toBe([]);
});

test('a downloaded compose file that docker rejects changes nothing', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['FAKE_INVALID_COMPOSE' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(file_get_contents($sandbox['log']))->not->toContain(' pull ');
});

test('an application that does not stay up brings back the previous version and compose file', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['FAKE_APP_STATE' => 'restarting 3']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('points back to 1.3.0')
        ->and(file_get_contents($sandbox['root'].'/.env'))->toBe("APP_NAME=MolMeDB\nMOLMEDB_VERSION=1.3.0\nDB_PASSWORD=secret")
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(glob($sandbox['root'].'/{,.}*deploy*', GLOB_BRACE))->toBe([$sandbox['root'].'/deploy.sh']);
});

test('a failure while .env is written brings back the previous compose file', function () {
    $sandbox = deploymentSandbox();
    chmod($sandbox['root'].'/.env', 0444);

    $process = runDeployment($sandbox, '1.4.0');

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.3.0')
        ->and(file_get_contents($sandbox['log']))->not->toContain('up -d');
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root writes to read-only files');

test('what an interrupted deployment left behind is not put back by the next one', function () {
    $sandbox = deploymentSandbox();
    file_put_contents($sandbox['root'].'/.env.deploy-backup', "MOLMEDB_VERSION=0.9.0\n");

    $process = runDeployment($sandbox, '1.4.0');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('an earlier deployment did not finish')
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.3.0')
        ->and(file_get_contents($sandbox['log']))->toBe('');
});

test('a marker tells whether the containers were touched', function (array $env, bool $touched) {
    $sandbox = deploymentSandbox();
    $marker = $sandbox['root'].'/../marker';

    runDeployment($sandbox, '1.4.0', $env + ['DEPLOY_MARKER' => $marker]);

    expect(file_exists($marker))->toBe($touched);
})->with([
    'a deployment that went through' => [[], true],
    'an application that did not stay up' => [['FAKE_APP_STATE' => 'restarting 2'], true],
    'images that could not be pulled' => [['FAKE_PULL_FAILS' => '1'], false],
]);

test('the first deployment into a directory without a compose file creates it', function () {
    $sandbox = deploymentSandbox();
    unlink($sandbox['root'].'/compose.yaml');

    $process = runDeployment($sandbox, '1.4.0');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('release compose');
});

test('the compose file that is there can be kept', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['MOLMEDB_KEEP_COMPOSE' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($sandbox['root'].'/compose.yaml'))->toContain('previous compose')
        ->and(file_get_contents($sandbox['log']))->not->toContain('curl')
        ->toContain('compose -f compose.yaml pull web ');
});

test('a checkout uses its own compose file and downloads nothing', function () {
    $sandbox = deploymentSandbox(checkout: true);

    $process = runDeployment($sandbox, '1.4.0');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.4.0')
        ->and(file_get_contents($sandbox['root'].'/docker-compose-production.yaml'))->toContain('checkout compose')
        ->and(file_get_contents($sandbox['log']))->not->toContain('curl')
        ->toContain('compose -f docker-compose-production.yaml up -d');
});

test('a release with a missing image changes nothing', function () {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, '1.4.0', ['FAKE_MISSING_IMAGE' => 'molmedb-frontend']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('molmedb-frontend:1.4.0')
        ->and(file_get_contents($sandbox['root'].'/.env'))->toContain('MOLMEDB_VERSION=1.3.0')
        ->and(file_get_contents($sandbox['log']))->not->toContain('curl');
});

test('anything but a version number is refused', function (string $version) {
    $sandbox = deploymentSandbox();

    $process = runDeployment($sandbox, $version);

    expect($process->getExitCode())->toBe(2)
        ->and(file_get_contents($sandbox['log']))->toBe('');
})->with(['latest', '1.4', '1.4.0-rc1', '']);
