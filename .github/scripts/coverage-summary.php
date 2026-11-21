<?php

/**
 * Turns a Clover coverage report into a Markdown summary: the overall line
 * coverage and the coverage of every source directory, least covered first.
 *
 * Usage: php coverage-summary.php <clover.xml> [directory depth, default 2]
 */
$cloverPath = $argv[1] ?? '';
$depth = max(1, (int) ($argv[2] ?? 2));

if (! is_file($cloverPath)) {
    fwrite(STDERR, "Coverage report not found: {$cloverPath}\n");
    exit(1);
}

$clover = simplexml_load_file($cloverPath);

if ($clover === false) {
    fwrite(STDERR, "Coverage report is not valid XML: {$cloverPath}\n");
    exit(1);
}

$root = rtrim((string) getcwd(), '/').'/';
$groups = [];
$total = ['statements' => 0, 'covered' => 0, 'files' => 0];

foreach ($clover->xpath('//file') ?: [] as $file) {
    $path = (string) $file['name'];
    $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : ltrim($path, '/');
    $group = implode('/', array_slice(explode('/', dirname($relative)), 0, $depth));

    $statements = (int) ($file->metrics['statements'] ?? 0);
    $covered = (int) ($file->metrics['coveredstatements'] ?? 0);

    $groups[$group] ??= ['statements' => 0, 'covered' => 0, 'files' => 0];
    $groups[$group]['statements'] += $statements;
    $groups[$group]['covered'] += $covered;
    $groups[$group]['files']++;

    $total['statements'] += $statements;
    $total['covered'] += $covered;
    $total['files']++;
}

$percent = fn (array $row): string => $row['statements'] > 0
    ? number_format($row['covered'] / $row['statements'] * 100, 1).' %'
    : 'n/a';

// Directories without executable lines say nothing; the rest least covered first.
$groups = array_filter($groups, fn (array $row): bool => $row['statements'] > 0);
uasort($groups, fn (array $a, array $b): int => $a['covered'] / $a['statements'] <=> $b['covered'] / $b['statements']);

echo "## Test coverage\n\n";
echo "**{$percent($total)}** of lines ({$total['covered']} of {$total['statements']}) in {$total['files']} files.\n\n";
echo "| Directory | Lines covered | Lines | Files |\n";
echo "| --- | ---: | ---: | ---: |\n";

foreach ($groups as $group => $row) {
    echo "| `{$group}` | {$percent($row)} | {$row['covered']} / {$row['statements']} | {$row['files']} |\n";
}
