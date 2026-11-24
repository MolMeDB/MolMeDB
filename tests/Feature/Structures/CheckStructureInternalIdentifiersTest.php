<?php

require_once __DIR__.'/../Api/api_test_helpers.php';

use App\Libraries\Identifiers;
use App\Models\Config;
use App\Models\Identifier;
use App\Models\Structure;

/*
 * structures:check-internal-identifiers walks the structures and gives each
 * one the MolMeDB identifier it should have. The daily run continues where
 * the previous one stopped and starts over once every structure was checked.
 */

const CHECK_IDENTIFIERS_PROGRESS_KEY = 'cron:daily:check_structure_identifier:start_id';

/**
 * @return array<int, Structure>
 */
function structuresWithoutIdentifier(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (): Structure => createApiStructure(['identifier' => null]))
        ->all();
}

test('a structure without identifier gets one derived from its id, recorded as its active MolMeDB identifier', function () {
    $structure = createApiStructure(['identifier' => null]);

    $this->artisan('structures:check-internal-identifiers')->assertSuccessful();

    $expected = Identifiers::get_identifier($structure->id);
    expect($structure->refresh()->identifier)->toBe($expected)
        ->and(Identifier::where('structure_id', $structure->id)
            ->where('type', Identifier::TYPE_MOLMEDB)
            ->where('value', $expected)
            ->value('state'))->toBe(Identifier::STATE_ACTIVE);
});

test('a structure with a valid identifier keeps it', function () {
    $structure = createApiStructure(['identifier' => 'MM00042']);

    $this->artisan('structures:check-internal-identifiers')
        ->expectsOutputToContain('--- OK')
        ->assertSuccessful();

    expect($structure->refresh()->identifier)->toBe('MM00042')
        ->and(Identifier::count())->toBe(0);
});

test('a charged form is renumbered under its parent and keeps the old identifier as obsolete', function () {
    $parent = createApiStructure(['identifier' => 'MM00030']);
    $charged = createApiStructure(['identifier' => 'MM00099', 'parent_id' => $parent->id]);

    $this->artisan('structures:check-internal-identifiers')->assertSuccessful();

    $new = Identifier::where('structure_id', $charged->id)->where('value', 'MM00030.1')->first();
    $old = Identifier::where('structure_id', $charged->id)->where('value', 'MM00099')->first();

    expect($charged->refresh()->identifier)->toBe('MM00030.1')
        ->and($new->state)->toBe(Identifier::STATE_ACTIVE)
        ->and($old->state)->toBe(Identifier::STATE_OBSOLETE)
        ->and($old->source_id)->toBe($new->id);
});

test('the parent of a charged form is numbered first, so the charged form can follow it', function () {
    $parent = createApiStructure(['identifier' => null]);
    $charged = createApiStructure(['identifier' => null, 'parent_id' => $parent->id]);

    $this->artisan('structures:check-internal-identifiers', ['--ids' => [$charged->id]])->assertSuccessful();

    $parentIdentifier = Identifiers::get_identifier($parent->id);
    expect($parent->refresh()->identifier)->toBe($parentIdentifier)
        ->and($charged->refresh()->identifier)->toBe($parentIdentifier.'.1');
});

test('a charged form of a charged form stops the run', function () {
    $root = createApiStructure(['identifier' => 'MM00030']);
    $charged = createApiStructure(['identifier' => 'MM00030.1', 'parent_id' => $root->id]);
    createApiStructure(['identifier' => null, 'parent_id' => $charged->id]);

    $this->artisan('structures:check-internal-identifiers');
})->throws(Exception::class, 'Parent structure has assigned subidentifier');

test('selected structures are checked without touching the others or the daily progress', function () {
    [$first, $second, $third] = structuresWithoutIdentifier(3);

    $this->artisan('structures:check-internal-identifiers', ['--ids' => ["{$first->id},{$third->id}", 'not-an-id', '0']])
        ->expectsOutputToContain('Total 2 structures will be processed.')
        ->assertSuccessful();

    expect($first->refresh()->identifier)->not->toBeNull()
        ->and($second->refresh()->identifier)->toBeNull()
        ->and($third->refresh()->identifier)->not->toBeNull()
        ->and(Config::get(CHECK_IDENTIFIERS_PROGRESS_KEY))->toBeNull();
});

test('a regular run continues from the stored progress', function () {
    [$first, $second, $third] = structuresWithoutIdentifier(3);
    Config::set(CHECK_IDENTIFIERS_PROGRESS_KEY, (string) $second->id);

    $this->artisan('structures:check-internal-identifiers')->assertSuccessful();

    expect($first->refresh()->identifier)->toBeNull()
        ->and($second->refresh()->identifier)->not->toBeNull()
        ->and($third->refresh()->identifier)->not->toBeNull();
});

test('a start id overrides the stored progress', function () {
    [$first, $second, $third] = structuresWithoutIdentifier(3);
    Config::set(CHECK_IDENTIFIERS_PROGRESS_KEY, (string) $first->id);

    $this->artisan('structures:check-internal-identifiers', ['--startId' => $third->id])->assertSuccessful();

    expect($first->refresh()->identifier)->toBeNull()
        ->and($second->refresh()->identifier)->toBeNull()
        ->and($third->refresh()->identifier)->not->toBeNull();
});

test('a forced run checks every structure regardless of the stored progress', function () {
    [$first, $second] = structuresWithoutIdentifier(2);
    Config::set(CHECK_IDENTIFIERS_PROGRESS_KEY, (string) ($second->id + 100));

    $this->artisan('structures:check-internal-identifiers', ['--force' => true])->assertSuccessful();

    expect($first->refresh()->identifier)->not->toBeNull()
        ->and($second->refresh()->identifier)->not->toBeNull();
});

test('a run past the last structure starts over from the first one', function () {
    [$first, $second] = structuresWithoutIdentifier(2);
    Config::set(CHECK_IDENTIFIERS_PROGRESS_KEY, (string) ($second->id + 1));

    $this->artisan('structures:check-internal-identifiers')
        ->expectsOutputToContain('REWIND')
        ->assertSuccessful();

    expect($first->refresh()->identifier)->not->toBeNull()
        ->and($second->refresh()->identifier)->not->toBeNull();
});

test('after a complete pass the next run starts over from the first structure', function () {
    [$first, $second] = structuresWithoutIdentifier(2);

    $this->artisan('structures:check-internal-identifiers')->assertSuccessful();

    // A structure broken after the pass is found by the next run.
    Structure::whereKey($first->id)->update(['identifier' => null]);

    $this->artisan('structures:check-internal-identifiers')
        ->expectsOutputToContain('REWIND')
        ->assertSuccessful();

    expect($first->refresh()->identifier)->toBe(Identifiers::get_identifier($first->id));
});
