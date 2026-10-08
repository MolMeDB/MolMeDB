<?php

namespace App\Services\Documentation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Finder\SplFileInfo;

/**
 * One documentation article kept in resources/docs: a Markdown file with a
 * Blade template body and a front matter (title, position, published).
 *
 * resources/docs/rest.md.blade.php is the top-level article "rest",
 * resources/docs/rest/mcp.md.blade.php its child "mcp".
 */
class DocumentationSource
{
    public const EXTENSION = '.md.blade.php';

    private function __construct(
        public readonly string $directory,
        public readonly string $path,
        public readonly ?string $parentSlug,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?int $position,
        public readonly bool $published,
        public readonly string $body,
    ) {}

    /**
     * Every article in the directory, top-level ones first. Files and
     * directories starting with "_" are partials, not articles.
     *
     * @return array<int, self>
     */
    public static function all(?string $directory = null): array
    {
        $directory ??= resource_path('docs');

        $paths = collect(File::allFiles($directory))
            ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
            ->filter(fn (string $path): bool => str_ends_with($path, self::EXTENSION))
            ->reject(fn (string $path): bool => collect(explode('/', $path))->contains(fn (string $segment): bool => str_starts_with($segment, '_')))
            ->sortBy(fn (string $path): string => substr_count($path, '/').$path)
            ->values();

        return $paths->map(fn (string $path): self => self::fromFile($directory, $path))->all();
    }

    /**
     * @param  string  $path  path relative to the documentation directory, e.g. "rest/mcp.md.blade.php"
     */
    public static function fromFile(string $directory, string $path): self
    {
        $segments = explode('/', Str::beforeLast($path, self::EXTENSION));

        if (count($segments) > 2) {
            throw new InvalidArgumentException("Documentation supports one nested level only: {$path}.");
        }

        [$frontMatter, $body] = self::splitFrontMatter((string) file_get_contents("{$directory}/{$path}"), $path);

        if (blank($frontMatter['title'] ?? null)) {
            throw new InvalidArgumentException("The front matter of {$path} has no title.");
        }

        return new self(
            directory: $directory,
            path: $path,
            parentSlug: count($segments) === 2 ? $segments[0] : null,
            slug: end($segments),
            title: $frontMatter['title'],
            position: isset($frontMatter['position']) ? (int) $frontMatter['position'] : null,
            published: ($frontMatter['published'] ?? 'true') !== 'false',
            body: $body,
        );
    }

    public function fullSlug(): string
    {
        return $this->parentSlug === null ? $this->slug : "{$this->parentSlug}/{$this->slug}";
    }

    /**
     * Front matter is a block of "key: value" lines between "---" lines at
     * the start of the file.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function splitFrontMatter(string $contents, string $path): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $contents, $matches) !== 1) {
            throw new InvalidArgumentException("{$path} has no front matter.");
        }

        $frontMatter = [];

        foreach (preg_split('/\R/', $matches[1]) as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $frontMatter[trim($key)] = trim(trim($value), '"\'');
        }

        return [$frontMatter, $matches[2]];
    }
}
