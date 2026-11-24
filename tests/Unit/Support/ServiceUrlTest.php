<?php

use App\Support\ServiceUrl;

test('a service set by its bare name is called over plain http', function (?string $host, string $url) {
    expect(ServiceUrl::fromHost($host))->toBe($url);
})->with([
    'bare name' => ['rdkit', 'http://rdkit'],
    'name with a port' => ['rdkit:9696', 'http://rdkit:9696'],
    'name with a trailing slash' => ['rdkit/', 'http://rdkit'],
    'network-path reference' => ['//rdkit', 'http://rdkit'],
    'surrounding spaces' => [' rdkit ', 'http://rdkit'],
    'http address' => ['http://rdkit/', 'http://rdkit'],
    'https address' => ['https://rdkit.example.org', 'https://rdkit.example.org'],
    'scheme in capitals' => ['HTTP://rdkit', 'HTTP://rdkit'],
    'address with a path' => ['http://host/rdkit/', 'http://host/rdkit'],
    'not set' => [null, ''],
    'empty' => ['', ''],
]);
