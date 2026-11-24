<?php

use App\Filament\RichContentCustomBlocks\CaptionBlock;
use App\Filament\RichContentCustomBlocks\CodeSnippetBlock;
use App\Filament\RichContentCustomBlocks\DocumentVersionBlock;
use App\Filament\RichContentCustomBlocks\ErrorInfoboxBlock;
use App\Filament\RichContentCustomBlocks\InfoInfoboxBlock;
use App\Filament\RichContentCustomBlocks\SuccessInfoboxBlock;
use App\Filament\RichContentCustomBlocks\WarningInfoboxBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/*
 * Custom blocks of the documentation editor: what the editor asks for and the
 * HTML they render on the public documentation pages.
 */

/**
 * @return array<string, Field>
 */
function richBlockFields(string $block): array
{
    $action = $block::configureEditorAction(Action::make('configure'));

    return collect($action->getSchemaComponents ?? [])->all();
}

/**
 * @return array<string, Field>
 */
function richBlockSchemaFields(string $block): array
{
    $action = $block::configureEditorAction(Action::make('configure'));
    $reflection = new ReflectionProperty($action, 'schema');
    $components = $reflection->getValue($action);

    return collect(is_array($components) ? $components : [])
        ->mapWithKeys(fn (Field $field): array => [$field->getName() => $field])
        ->all();
}

test('every block has a stable id and label', function (string $block, string $id, string $label) {
    expect($block::getId())->toBe($id)
        ->and($block::getLabel())->toBe($label);
})->with([
    [CaptionBlock::class, 'caption', 'Caption'],
    [CodeSnippetBlock::class, 'code_snippet', 'Code snippet'],
    [DocumentVersionBlock::class, 'document_version', 'Document version'],
    [InfoInfoboxBlock::class, 'infobox_info', 'Info infobox'],
    [WarningInfoboxBlock::class, 'infobox_warning', 'Warning infobox'],
    [ErrorInfoboxBlock::class, 'infobox_error', 'Error infobox'],
    [SuccessInfoboxBlock::class, 'infobox_success', 'Success infobox'],
]);

test('the editor asks each block for its settings', function (string $block, array $fields) {
    expect(array_keys(richBlockSchemaFields($block)))->toBe($fields);
})->with([
    [CaptionBlock::class, ['type', 'number', 'caption']],
    [CodeSnippetBlock::class, ['language', 'code']],
    [DocumentVersionBlock::class, ['published_at', 'updated_at', 'author_name', 'author_url']],
    [InfoInfoboxBlock::class, ['body']],
]);

test('a caption is a figure or a table with a number of at least one', function () {
    $fields = richBlockSchemaFields(CaptionBlock::class);

    expect($fields['type'])->toBeInstanceOf(Select::class)
        ->and($fields['type']->getOptions())->toBe(['figure' => 'Figure', 'table' => 'Table'])
        ->and($fields['type']->isRequired())->toBeTrue()
        ->and($fields['number'])->toBeInstanceOf(TextInput::class)
        ->and($fields['number']->isNumeric())->toBeTrue()
        ->and($fields['number']->getMinValue())->toBe(1)
        ->and($fields['caption']->isRequired())->toBeTrue();
});

test('a code snippet offers SPARQL, SQL and JSON', function () {
    $fields = richBlockSchemaFields(CodeSnippetBlock::class);

    expect($fields['language']->getOptions())->toBe(['sparql' => 'SPARQL', 'sql' => 'SQL', 'json' => 'JSON'])
        ->and($fields['language']->getDefaultState())->toBe('sparql')
        ->and($fields['code']->isRequired())->toBeTrue();
});

test('the document version needs an author and allows no future dates', function () {
    $fields = richBlockSchemaFields(DocumentVersionBlock::class);

    expect($fields['published_at'])->toBeInstanceOf(DatePicker::class)
        ->and($fields['published_at']->getMaxDate())->toStartWith(today()->toDateString())
        ->and($fields['author_name']->isRequired())->toBeTrue()
        ->and($fields['author_url']->isRequired())->toBeFalse();
});

test('a caption renders its label, number and escaped plain text', function () {
    $html = CaptionBlock::toHtml(['type' => 'table', 'number' => 3, 'caption' => 'Values for A & B > "C"'], []);

    expect($html)->toBe('<p class="docs-caption docs-caption--table"><span class="docs-caption__label">Table 3:</span> <span class="docs-caption__content">Values for A &amp; B &gt; &quot;C&quot;</span></p>');
});

test('a caption of an unknown type falls back to a figure and keeps rich text inline', function () {
    $html = CaptionBlock::toHtml(['type' => '"><script>', 'number' => '2', 'caption' => '<p>First <strong>bold</strong></p><p>second</p>'], []);

    expect($html)->toContain('docs-caption--figure')
        ->toContain('Figure 2:')
        ->toContain('First <strong>bold</strong> second')
        ->not->toContain('<script>')
        ->not->toContain('<p>First');
});

test('a caption preview is styled inline and an empty caption renders no content', function () {
    $html = CaptionBlock::toPreviewHtml([]);

    expect($html)->toContain('style="color: #71717a;')
        ->toContain('<span class="docs-caption__label">Figure:</span> <span class="docs-caption__content"></span>');
});

test('a code snippet escapes the code and falls back to SPARQL for unknown languages', function () {
    expect(CodeSnippetBlock::toHtml(['language' => 'SQL', 'code' => 'SELECT * FROM t WHERE a < 1'], []))
        ->toBe('<pre class="docs-code-block language-sql" data-language="sql"><code>SELECT * FROM t WHERE a &lt; 1</code></pre>')
        ->and(CodeSnippetBlock::toPreviewHtml(['language' => 'php" onload="x', 'code' => '<?php']))
        ->toBe('<pre class="docs-code-block language-sparql" data-language="sparql"><code>&lt;?php</code></pre>');
});

test('the document version shows its dates and links its author', function () {
    $html = DocumentVersionBlock::toHtml([
        'published_at' => '2026-01-05',
        'updated_at' => '2026-03-09',
        'author_name' => 'Jane <Doe>',
        'author_url' => 'https://example.org/jane?a=1&b=2',
    ], []);

    expect($html)->toContain('Published:</span> <span class="docs-version-meta__value" style="display: inline;">January 5, 2026</span>')
        ->toContain('March 9, 2026')
        ->toContain('<a href="https://example.org/jane?a=1&amp;b=2" class="docs-version-meta__author-link" target="_blank" rel="noopener noreferrer"')
        ->toContain('>Jane &lt;Doe&gt;</a>');
});

test('the document version falls back to the article dates and shows an unlinked author', function () {
    $html = DocumentVersionBlock::toHtml(
        ['author_name' => 'MolMeDB team'],
        ['published_at' => '2025-12-24 10:00:00', 'updated_at' => 'not a date <x>'],
    );

    expect($html)->toContain('December 24, 2025')
        ->toContain('not a date &lt;x&gt;')
        ->toContain('<span class="docs-version-meta__value" style="display: inline;">MolMeDB team</span>')
        ->not->toContain('<a ');
});

test('the document version preview leaves missing values empty', function () {
    $html = DocumentVersionBlock::toPreviewHtml([]);

    expect(substr_count($html, 'style="display: inline;"></span>'))->toBe(3);
});

test('an infobox renders its variant around the rich content', function (string $block, string $variant, string $border) {
    $html = $block::toHtml(['body' => '<p>Read <strong>this</strong></p>'], []);
    $preview = $block::toPreviewHtml(['body' => '<p>Read</p>']);

    expect($html)->toContain("<div class=\"docs-infobox docs-infobox--{$variant}\">")
        ->toContain('<strong>this</strong>')
        ->not->toContain('style=')
        ->and($preview)->toContain("docs-infobox--{$variant}\" style=\"border-left: 5px solid {$border};");
})->with([
    [InfoInfoboxBlock::class, 'info', '#2563eb'],
    [WarningInfoboxBlock::class, 'warning', '#d97706'],
    [ErrorInfoboxBlock::class, 'error', '#be123c'],
    [SuccessInfoboxBlock::class, 'success', '#15803d'],
]);

test('an empty infobox asks for content', function () {
    expect(WarningInfoboxBlock::toHtml([], []))->toContain('<p>Add infobox content.</p>');
});
