<?php

use Symfony\Component\Process\Process;

/*
 * .github/scripts/changelog-notes.sh: the Release workflow takes the notes of
 * a version from CHANGELOG.md and refuses a version that has none.
 */

function changelogNotes(string $version, ?string $changelog = null): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process(['bash', $root.'/.github/scripts/changelog-notes.sh', $version, ...($changelog === null ? [] : [$changelog])], $root);
    $process->run();

    return $process;
}

function temporaryChangelog(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'changelog');
    file_put_contents($path, $content);

    return $path;
}

test('the notes of a version are its section without the heading', function () {
    $changelog = temporaryChangelog(<<<'MD'
        # Changelog

        ## [1.4.0] - 2026-11-27

        ### Added

        - Prediction server health.

        ## [1.3.0] - 2026-11-26

        - Kekulé SMILES.

        [1.4.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.3.0...v1.4.0
        [1.3.0]: https://github.com/MolMeDB/MolMeDB/compare/v1.2.0...v1.3.0
        MD);

    $process = changelogNotes('1.4.0', $changelog);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toBe("### Added\n\n- Prediction server health.\n");
    expect(changelogNotes('1.3.0', $changelog)->getOutput())->toBe("- Kekulé SMILES.\n");
});

test('a version without a section is refused', function (string $version) {
    $changelog = temporaryChangelog("# Changelog\n\n## [1.4.0] - 2026-11-27\n\n## [1.3.0] - 2026-11-26\n\n- Kekulé SMILES.\n");

    $process = changelogNotes($version, $changelog);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain("has no section for {$version}");
})->with([
    'missing' => ['2.0.0'],
    'empty' => ['1.4.0'],
    // 1.4 must not match the heading of 1.4.0.
    'prefix of another' => ['1.4'],
]);

test('a missing changelog is refused', function () {
    $process = changelogNotes('1.4.0', sys_get_temp_dir().'/no-such-changelog.md');

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('does not exist');
});

test('every released version has its section in the changelog', function () {
    $tags = ['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0'];

    foreach ($tags as $version) {
        expect(changelogNotes($version)->isSuccessful())->toBeTrue("CHANGELOG.md has no section for {$version}.");
    }
});
