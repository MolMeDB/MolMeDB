<?php

namespace App\Rules;

use App\Services\NotificationService;
use Closure;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Every {{ and }} in a notification template belongs to a variable written
 * as {{ name }}. A brace too few ("{{ total_all }") is not a variable: the
 * text would be sent as it is, without the value.
 */
class NotificationTemplateVariables implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The rich editor validates its content as a TipTap document.
        if (is_array($value)) {
            $value = RichContentRenderer::make($value)->toUnsafeHtml();
        }

        if (! is_string($value)) {
            return;
        }

        $rest = preg_replace(NotificationService::TEMPLATE_VARIABLE_PATTERN, '', $value) ?? $value;

        // A brace too many around a variable ("{{{ name }}}") stays in the text.
        if (preg_match('/{{{|}}}/', $value, $matches) === 1
            || preg_match('/{{[^{}<]{0,40}|[^{}>]{0,40}}}/u', $rest, $matches) === 1) {
            $fail('Variables are written as {{ name }}, with nothing else between the braces (no formatting, no special spaces); check "'.Str::squish($matches[0]).'".');
        }
    }
}
