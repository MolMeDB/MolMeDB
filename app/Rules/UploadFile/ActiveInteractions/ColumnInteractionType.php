<?php

namespace App\Rules\UploadFile\ActiveInteractions;

use App\Models\Category;
use App\Rules\UploadFile\ColumnTypeInterface;
use Closure;

class ColumnInteractionType implements ColumnTypeInterface
{
    public static string $key = 'interaction_type';

    public static string $label = 'Interaction type';

    /**
     * @var array<string, int>|null
     */
    private ?array $categoryIdsByNormalizedTitle = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * Case, whitespace and punctuation insensitive form of a type title.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9+\/]+/i', '', trim($value)) ?? '');
    }

    /**
     * Resolves the category id of an active interaction type, or null when the type is unknown.
     */
    public function categoryId(string $value): ?int
    {
        return $this->categoryIds()[self::normalize($value)] ?? null;
    }

    public function validate(string $attribute, $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if ($this->categoryId($value) === null) {
            $fail('Column '.self::$label." contains unknown interaction type: $value.");
        }
    }

    public function validate_fast(string $attribute, mixed $value, Closure $fail): void
    {
        $this->validate($attribute, $value, $fail);
    }

    /**
     * @return array<string, int>
     */
    private function categoryIds(): array
    {
        if ($this->categoryIdsByNormalizedTitle === null) {
            $this->categoryIdsByNormalizedTitle = [];

            foreach (Category::query()->where('type', Category::TYPE_ACTIVE_INTERACTION)->get(['id', 'title']) as $category) {
                $this->categoryIdsByNormalizedTitle[self::normalize((string) $category->title)] ??= (int) $category->id;
            }
        }

        return $this->categoryIdsByNormalizedTitle;
    }
}
