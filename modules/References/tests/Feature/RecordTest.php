<?php

use Modules\References\EuropePMC\Enums\Sources;
use Modules\References\Models\Author;
use Modules\References\Models\Journal;
use Modules\References\Models\Record;

test('record get value resolves nested array paths', function () {
    $data = [
        'journalInfo' => [
            'journal' => [
                'title' => 'Journal of Testing',
            ],
        ],
    ];

    expect(Record::getValue($data, 'journalInfo.journal.title'))->toBe('Journal of Testing')
        ->and(Record::getValue($data, 'journalInfo.journal.missing', 'fallback'))->toBe('fallback');
});

test('record citation uses author fallback string built from authors', function () {
    $record = new Record(
        id: '1',
        source: Sources::MED,
        pmid: null,
        pmcid: null,
        journal: new Journal(
            title: 'Journal of Testing',
            issue: '4',
            volume: '12',
            dateOfPublication: '2025-04-11',
            monthOfPublication: '04',
            yearOfPublication: '2025',
            issn: null,
            essn: null,
        ),
        title: 'Example title',
        authorString: null,
        authors: [
            new Author('Jane Doe', 'Jane', 'Doe', 'J', ['Lab A']),
            new Author('John Smith', 'John', 'Smith', 'J', ['Lab B']),
        ],
        doi: '10.1000/test',
        isOpenAccess: true,
        inEPMC: true,
        hasPDF: true,
        hasBook: false,
        hasSuppl: false,
        abstractText: null,
        affiliation: null,
        citedByCount: 3,
        hasReferences: true,
        keywords: ['membrane'],
        fullTextUrls: ['https://example.test/fulltext'],
        pageInfo: '10-15',
    );

    expect($record->getAuthorString())->toBe('Doe J., Smith J.')
        ->and($record->citation())->toBe('Doe J., Smith J.: Example title. Journal of Testing, Volume 12 (4), 10-15, 2025');
});

test('the day of publication is read from the forms Europe PMC and CrossRef write it in', function (?string $written, ?string $day) {
    $record = (new ReflectionClass(Record::class))->newInstanceWithoutConstructor();
    $record->journal = new Journal(
        title: 'Journal of Testing',
        issue: null,
        volume: null,
        dateOfPublication: $written,
        monthOfPublication: null,
        yearOfPublication: '2020',
        issn: null,
        essn: null,
    );

    expect($record->publicationDate())->toBe($day);
})->with([
    'a full date' => ['2020-02-25', '2020-02-25'],
    'a day with the name of the month' => ['2020 Feb 25', '2020-02-25'],
    'a date joined from the parts CrossRef gives' => ['2024-4-11', '2024-04-11'],
    'a day without a leading zero' => ['2020 Feb 5', '2020-02-05'],
    'a month' => ['2020 Feb', '2020-02-01'],
    'a month in digits' => ['2020-02', '2020-02-01'],
    'a year alone is no date' => ['2020', null],
    'a day that does not exist' => ['2020 Feb 31', null],
    'a season' => ['2020 Spring', null],
    'nothing' => [null, null],
    'an empty text' => ['  ', null],
]);
