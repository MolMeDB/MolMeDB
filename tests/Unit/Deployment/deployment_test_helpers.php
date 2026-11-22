<?php

use Symfony\Component\Filesystem\Filesystem;

/**
 * A temporary directory in which the deployment scripts meet a docker and a
 * curl that only write down what they were asked to do
 * (tests/Fixtures/deployment).
 *
 * @return array{base: string, log: string, env: array<string, string>}
 */
function deploymentFakes(): array
{
    $base = sys_get_temp_dir().'/molmedb-deployment-'.bin2hex(random_bytes(6));
    mkdir($base.'/bin', 0777, true);
    mkdir($base.'/state');
    $GLOBALS['deploymentFakes'][] = $base;

    $fixtures = dirname(__DIR__, 2).'/Fixtures/deployment';
    foreach (['docker', 'curl'] as $command) {
        copy($fixtures.'/'.$command, $base.'/bin/'.$command);
        chmod($base.'/bin/'.$command, 0755);
    }

    $log = $base.'/calls.log';
    touch($log);

    return [
        'base' => $base,
        'log' => $log,
        'env' => [
            'PATH' => $base.'/bin:'.getenv('PATH'),
            'FAKE_LOG' => $log,
            'FAKE_STATE_DIR' => $base.'/state',
            'FAKE_DEPLOY_SCRIPT' => deploymentScript('deploy.sh'),
            'DEPLOY_WATCH_INTERVAL' => '0',
            'MOVE_WAIT_INTERVAL' => '0',
            'MOVE_ALLOW_NON_ROOT' => '1',
        ],
    ];
}

function deploymentScript(string $name): string
{
    return dirname(__DIR__, 3).'/docker/production/'.$name;
}

function removeDeploymentFakes(): void
{
    (new Filesystem)->remove($GLOBALS['deploymentFakes'] ?? []);
    $GLOBALS['deploymentFakes'] = [];
}
