<?php

use Illuminate\Support\Facades\Http;
use Modules\References\CrossRef\CrossRef;
use Modules\References\Models\Record;

beforeEach(function () {
    config()->set('services.crossref.endpoint', 'https://crossref.test');
});

test('crossref work returns mapped record for valid response', function () {
    Http::fake([
        'https://crossref.test/works/*' => Http::response([
            'status' => 'ok',
            'message' => [
                'DOI' => '10.1000/test',
                'title' => ['Example article'],
                'container-title' => ['Journal of Testing'],
                'issue' => '2',
                'volume' => '10',
                'published' => [
                    'date-parts' => [[2024, 4, 11]],
                ],
                'ISSN' => ['1234-5678'],
                'author' => [
                    [
                        'given' => 'Jane',
                        'family' => 'Doe',
                        'affiliation' => [
                            ['name' => 'Test Lab'],
                        ],
                    ],
                ],
                'abstract' => 'Abstract body',
                'is-referenced-by-count' => 7,
                'reference-count' => 2,
            ],
        ], 200),
    ]);

    $record = app(CrossRef::class)->work('10.1000/test');

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->doi)->toBe('10.1000/test')
        ->and($record->title)->toBe('Example article')
        ->and($record->journal?->title)->toBe('Journal of Testing')
        ->and($record->journal?->yearOfPublication)->toBe('2024')
        ->and($record->authors)->toHaveCount(1)
        ->and($record->authors[0]->fullName)->toBe('Jane Doe')
        ->and($record->hasReferences)->toBeTrue();
});

test('crossref work returns null for invalid client response', function () {
    Http::fake([
        'https://crossref.test/works/*' => Http::response([], 404),
    ]);

    expect(app(CrossRef::class)->work('10.1000/missing'))->toBeNull();
});
