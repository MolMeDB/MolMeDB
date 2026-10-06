<?php

require_once __DIR__.'/api_test_helpers.php';

use App\Models\Category;
use App\Models\Protein;
use App\Models\ProteinIdentifier;
use App\Rules\UploadFile\ActiveInteractions\ColumnInteractionType;
use App\Rules\UploadFile\ActiveInteractions\ColumnProteinName;
use App\Services\UploadQueueInteractionPayloadBuilder;

function activeTypeCategory(string $title): Category
{
    return Category::query()
        ->where('type', Category::TYPE_ACTIVE_INTERACTION)
        ->where('title', $title)
        ->firstOrFail();
}

function interactionTypeErrors(string $value): array
{
    $errors = [];
    (new ColumnInteractionType)->validate_fast('interaction_type', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

function callBuilder(string $method, mixed ...$arguments): mixed
{
    $builder = app(UploadQueueInteractionPayloadBuilder::class);
    $reflection = new ReflectionMethod($builder, $method);

    return $reflection->invoke($builder, ...$arguments);
}

test('interaction type column accepts known types regardless of case and punctuation', function (string $value) {
    expect(interactionTypeErrors($value))->toBe([]);
})->with(['Non-inhibitor', 'non inhibitor', ' NON-INHIBITOR ', 'noninhibitor']);

test('interaction type column rejects unknown types', function () {
    expect(interactionTypeErrors('blocker'))->toHaveCount(1);
});

test('interaction type column resolves the category id', function () {
    $category = activeTypeCategory('Substrate + inhibitor');

    expect((new ColumnInteractionType)->categoryId('substrate+inhibitor'))->toBe($category->id);
});

test('active category is taken from the row and falls back to unassigned', function () {
    $inhibitor = activeTypeCategory('Inhibitor');

    expect(callBuilder('resolveActiveCategoryId', ['interaction_type' => 'inhibitor']))->toBe($inhibitor->id);

    $fallback = callBuilder('resolveActiveCategoryId', []);
    expect(Category::query()->find($fallback)->title)->toBe('Unassigned');
});

test('protein name column enforces the maximum length', function () {
    $errors = [];
    (new ColumnProteinName)->validate_fast('protein_name', str_repeat('a', 256), function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    expect($errors)->toHaveCount(1);
});

test('protein name is stored as identifier and only supplemented for existing proteins', function () {
    $row = ['active_target' => 'P00533', 'protein_name' => 'EGFR'];

    $proteinId = callBuilder('resolveProteinId', $row, true);
    expect(ProteinIdentifier::query()->where('protein_id', $proteinId)->pluck('value')->all())->toBe(['EGFR']);

    $builder = fn () => app()->make(UploadQueueInteractionPayloadBuilder::class);
    $reflection = new ReflectionMethod(UploadQueueInteractionPayloadBuilder::class, 'resolveProteinId');

    $reflection->invoke($builder(), ['active_target' => 'P00533', 'protein_name' => 'egfr'], true);
    $reflection->invoke($builder(), ['active_target' => 'P00533', 'protein_name' => 'Epidermal growth factor receptor'], true);

    expect(Protein::query()->count())->toBe(1)
        ->and(ProteinIdentifier::query()->where('protein_id', $proteinId)->pluck('value')->all())
        ->toBe(['EGFR', 'Epidermal growth factor receptor']);
});
