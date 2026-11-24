<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Libraries\Identifiers;
use App\Models\Identifier;

/*
 * MolMeDB identifiers (MM00042, charged forms MM00042.1) are persistent: a
 * generated identifier must never collide with one already handed out, even
 * by a removed structure or a merged (obsolete) identifier record.
 */

function createMolmedbIdentifierRecord(int $structureId, string $value, int $state = Identifier::STATE_OBSOLETE): Identifier
{
    return Identifier::create([
        'structure_id' => $structureId,
        'type' => Identifier::TYPE_MOLMEDB,
        'value' => $value,
        'state' => $state,
    ]);
}

test('identifiers are zero padded to seven characters without truncating longer numbers', function (int $number, string $expected) {
    expect(Identifiers::get_identifier($number))->toBe($expected);
})->with([
    [1, 'MM00001'],
    [42, 'MM00042'],
    [99999, 'MM99999'],
    [1234567, 'MM1234567'],
]);

test('identifier validity follows the MolMeDB format', function (?string $identifier, bool $isValid, bool $isSubIdentifier) {
    expect((bool) Identifiers::is_valid($identifier))->toBe($isValid)
        ->and(Identifiers::isSubIdentifier($identifier))->toBe($isSubIdentifier);
})->with([
    'top-level' => ['MM00042', true, false],
    'lower case' => ['mm00042', true, false],
    'charged form' => ['MM00042.3', true, true],
    'long number' => ['MM1234567', true, false],
    'too short' => ['MM0042', false, false],
    'wrong prefix' => ['MX00042', false, false],
    'trailing dot' => ['MM00042.', false, false],
    'letters in number' => ['MM000A2', false, false],
    'blank' => ['       ', false, false],
    'empty' => ['', false, false],
    'null' => [null, false, false],
]);

test('a new identifier continues after the highest number in use, ignoring charged form suffixes', function () {
    createApiStructure(['identifier' => 'MM00041']);
    createApiStructure(['identifier' => 'MM00007']);
    createApiStructure(['identifier' => 'MM00041.2']);

    expect(Identifiers::generate())->toBe('MM00042');
});

test('the first identifier of an empty database is MM00001', function () {
    expect(Identifiers::generate())->toBe('MM00001');
});

test('a new identifier skips numbers kept by merged or deleted identifier records', function () {
    $structure = createApiStructure(['identifier' => 'MM00041']);
    createMolmedbIdentifierRecord($structure->id, 'MM00042');
    createMolmedbIdentifierRecord($structure->id, 'MM00043')->delete();

    expect(Identifiers::generate())->toBe('MM00044');
});

test('a new identifier never reuses the identifier of a removed structure', function () {
    createApiStructure(['identifier' => 'MM00041']);
    createApiStructure(['identifier' => 'MM00042'])->delete();

    expect(Identifiers::generate())->toBe('MM00043');
});

test('a structure keeps its existing top-level identifier', function () {
    $structure = createApiStructure(['identifier' => 'MM00500']);

    expect(Identifiers::generate($structure))->toBe('MM00500');
});

test('a structure without identifier gets the one derived from its id when it is free', function () {
    $structure = createApiStructure(['identifier' => null]);

    expect(Identifiers::generate($structure))->toBe(Identifiers::get_identifier($structure->id));
});

test('a structure whose id-derived identifier is taken gets the next free number', function () {
    $structure = createApiStructure(['identifier' => null]);
    createApiStructure(['identifier' => Identifiers::get_identifier($structure->id)]);
    createApiStructure(['identifier' => Identifiers::get_identifier($structure->id + 10)]);

    expect(Identifiers::generate($structure))->toBe(Identifiers::get_identifier($structure->id + 11));
});

test('a structure whose id-derived identifier is kept by a merged record gets the next free number', function () {
    $structure = createApiStructure(['identifier' => null]);
    $survivor = createApiStructure(['identifier' => Identifiers::get_identifier($structure->id + 10)]);
    createMolmedbIdentifierRecord($survivor->id, Identifiers::get_identifier($structure->id));

    expect(Identifiers::generate($structure))->toBe(Identifiers::get_identifier($structure->id + 11));
});

test('a charged form of a parent without identifier cannot be numbered', function () {
    $parent = createApiStructure(['identifier' => null]);
    $child = createApiStructure(['identifier' => null, 'parent_id' => $parent->id]);

    Identifiers::generate($child);
})->throws(Exception::class, 'Parent structure has no identifier.');

test('a charged form of a parent with a charged form identifier cannot be numbered', function () {
    $parent = createApiStructure(['identifier' => 'MM00010.1']);
    $child = createApiStructure(['identifier' => null, 'parent_id' => $parent->id]);

    Identifiers::generate($child);
})->throws(Exception::class, 'Parent structure has assigned subidentifier - MM00010.1');

test('a charged form already numbered under its parent keeps its identifier', function () {
    $parent = createApiStructure(['identifier' => 'MM00010']);
    $child = createApiStructure(['identifier' => 'MM00010.3', 'parent_id' => $parent->id]);

    expect(Identifiers::generate($child))->toBe('MM00010.3');
});

test('a charged form gets the next suffix under its parent, skipping suffixes kept elsewhere', function () {
    $parent = createApiStructure(['identifier' => 'MM00010']);
    createApiStructure(['identifier' => 'MM00010.1', 'parent_id' => $parent->id]);
    createApiStructure(['identifier' => 'MM00010.4', 'parent_id' => $parent->id]);
    createApiStructure(['identifier' => 'MM00011.9']);
    createMolmedbIdentifierRecord($parent->id, 'MM00010.5');
    $moved = createApiStructure(['identifier' => 'MM00020.1', 'parent_id' => $parent->id]);

    expect(Identifiers::generate($moved))->toBe('MM00010.6');
});

test('a charged form never reuses the identifier of a removed charged form', function () {
    $parent = createApiStructure(['identifier' => 'MM00010']);
    createApiStructure(['identifier' => 'MM00010.1', 'parent_id' => $parent->id])->delete();
    $child = createApiStructure(['identifier' => null, 'parent_id' => $parent->id]);

    expect(Identifiers::generate($child))->toBe('MM00010.2');
});
