<?php

namespace App\Services\Documentation;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Renders a documentation source to the HTML the documentation page shows:
 * the Blade template (with the data from DocumentationCatalog) first, then
 * GitHub-flavored Markdown. Code blocks and alerts get the markup of the
 * administration's code snippet and infobox blocks, so both kinds of
 * articles look the same.
 */
class DocumentationRenderer
{
    /**
     * GitHub alert types ("> [!NOTE]") and the infobox variants they become.
     *
     * @var array<string, string>
     */
    private const ALERTS = [
        'NOTE' => 'info',
        'TIP' => 'success',
        'IMPORTANT' => 'info',
        'WARNING' => 'warning',
        'CAUTION' => 'error',
    ];

    public function __construct(private readonly DocumentationCatalog $catalog) {}

    public function render(DocumentationSource $source): string
    {
        // Partials shared by the articles: @include('docs::_partials.endpoint').
        View::replaceNamespace('docs', $source->directory);

        $markdown = Blade::render($source->body, ['docs' => $this->catalog], deleteCachedView: true);

        return $this->toHtml($markdown);
    }

    public function toHtml(string $markdown): string
    {
        $html = Str::markdown($markdown);

        return $this->alerts($this->codeBlocks($html));
    }

    private function codeBlocks(string $html): string
    {
        return preg_replace_callback(
            '#<pre><code(?: class="language-([\w+-]+)")?>(.*?)</code></pre>#s',
            function (array $match): string {
                $language = strtolower($match[1] ?: 'text');

                return sprintf(
                    '<pre class="docs-code-block language-%1$s" data-language="%1$s"><code>%2$s</code></pre>',
                    $language,
                    rtrim($match[2], "\n"),
                );
            },
            $html,
        );
    }

    private function alerts(string $html): string
    {
        return preg_replace_callback(
            '#<blockquote>\s*<p>\[!('.implode('|', array_keys(self::ALERTS)).')\]\s*(.*?)</blockquote>#s',
            function (array $match): string {
                $variant = self::ALERTS[$match[1]];
                $content = '<p>'.ltrim(preg_replace('#^<br\s*/?>#', '', ltrim($match[2])));

                return "<div class=\"docs-infobox docs-infobox--{$variant}\">\n<div class=\"docs-infobox__content\">{$content}</div>\n</div>";
            },
            $html,
        );
    }
}
