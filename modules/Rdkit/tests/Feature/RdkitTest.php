<?php

use App\Support\ServiceUrl;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Modules\Rdkit\Rdkit;
use Modules\Rdkit\Response\ResponseInfo;

beforeEach(function () {
    resetRdkitState();
});

afterEach(function () {
    resetRdkitState();
});

function resetRdkitState(): void
{
    Closure::bind(function (): void {
        self::$STATUS = false;
        self::$url_parameters = ['host' => ''];
    }, null, Rdkit::class)();
}

test('rdkit returns fingerprint from remote service response', function () {
    config()->set('services.rdkit.url', 'https://rdkit.test');

    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/fingerprint*' => Http::response([
            'data' => [
                'fingerprint' => str_repeat('1010', 16),
            ],
        ], 200),
    ]);

    $client = new Rdkit;

    expect($client->get_fingerprint('CCO'))->toBe(str_repeat('1010', 16));
});

test('rdkit returns general info value object from remote service response', function () {
    config()->set('services.rdkit.url', 'https://rdkit.test');

    Http::fake([
        'https://rdkit.test/test' => Http::response([], 200),
        'https://rdkit.test/structure/info*' => Http::response([
            'data' => [
                'canonized_smiles' => 'CCO',
                'inchi' => 'InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3',
                'inchikey' => 'LFQSCWFLJHTTHZ-UHFFFAOYSA-N',
                'MW' => 46.07,
                'LogP' => -0.31,
            ],
        ], 200),
    ]);

    $client = new Rdkit;
    $info = $client->get_general_info('CCO');

    expect($info)->toBeInstanceOf(ResponseInfo::class)
        ->and($info->canonized_smiles)->toBe('CCO')
        ->and($info->inchi)->toBe('InChI=1S/C2H6O/c1-2-3/h3H,2H2,1H3')
        ->and($info->inchikey)->toBe('LFQSCWFLJHTTHZ-UHFFFAOYSA-N')
        ->and($info->mw)->toBe(46.07)
        ->and($info->logp)->toBe(-0.31);
});

test('rdkit returns null when service is not connected', function () {
    config()->set('services.rdkit.url', 'https://rdkit.test');

    Http::fake([
        'https://rdkit.test/test' => Http::response([], 500),
    ]);

    $client = new Rdkit;

    expect($client->get_fingerprint('CCO'))->toBeNull()
        ->and($client->get_general_info('CCO'))->toBeNull();
});

test('rdkit set by its bare service name is reached over plain http', function () {
    config()->set('services.rdkit.url', ServiceUrl::fromHost('rdkit'));

    Http::fake([
        'http://rdkit/test' => Http::response([], 200),
        'http://rdkit/structure/canonize*' => Http::response(['data' => 'CCO'], 200),
    ]);

    expect((new Rdkit)->canonize_smiles('OCC'))->toBe('CCO');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'http://rdkit/structure/canonize'));
});

test('the configured rdkit address always carries a scheme', function () {
    $repository = Env::getRepository();
    $before = $repository->get('RDKIT_HOST');
    $repository->clear('RDKIT_HOST');
    $repository->set('RDKIT_HOST', 'rdkit');

    try {
        expect((require config_path('services.php'))['rdkit']['url'])->toBe('http://rdkit');
    } finally {
        $repository->clear('RDKIT_HOST');

        if ($before !== null) {
            $repository->set('RDKIT_HOST', $before);
        }
    }
});
