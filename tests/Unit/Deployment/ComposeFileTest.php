<?php

use Symfony\Component\Yaml\Yaml;

/*
 * What the production compose file must keep, because the data depends on
 * it: the project name the database volumes are named after, and time for
 * the databases to shut down.
 */

/**
 * @return array<string, mixed>
 */
function productionCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/docker-compose-production.yaml');
}

test('the project name does not depend on the directory', function () {
    expect(productionCompose()['name'])->toBe('molmedb-api');
});

test('the databases are given time to shut down before docker kills them', function (string $service) {
    expect(productionCompose()['services'][$service]['stop_grace_period'] ?? null)->toBe('5m');
})->with(['db', 'db-predictions']);

test('the databases keep their data in named volumes', function (string $service, string $volume) {
    $compose = productionCompose();

    expect($compose['services'][$service]['volumes'])->toBe([$volume.':/var/lib/postgresql/data'])
        ->and(array_key_exists($volume, $compose['volumes']))->toBeTrue();
})->with([
    ['db', 'molmedb-db'],
    ['db-predictions', 'molmedb-predictions-db'],
]);
