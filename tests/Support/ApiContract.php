<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Contract of an API endpoint: status, content type and the shape of the JSON
 * body (every key with the type of its value, lists as the merged shape of
 * their items). Contracts are stored in tests/Fixtures/api-contracts, one file
 * per route, so a change of any endpoint's response shows up as a failing
 * test and as a diff of its contract file in the pull request.
 *
 * Intentional changes are recorded by running the tests with
 * UPDATE_API_CONTRACTS=1, e.g. `UPDATE_API_CONTRACTS=1 tests/pgsql.sh --filter=Contract`.
 */
class ApiContract
{
    public const DIRECTORY = __DIR__.'/../Fixtures/api-contracts';

    public static function assert(TestResponse $response, string $route): void
    {
        $path = self::path($route);
        $actual = self::describe($response, $route);

        if (getenv('UPDATE_API_CONTRACTS')) {
            if (! is_dir(self::DIRECTORY)) {
                mkdir(self::DIRECTORY, 0777, true);
            }

            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            Assert::assertTrue(true);

            return;
        }

        Assert::assertFileExists($path, "No contract is recorded for [{$route}]. Record it with UPDATE_API_CONTRACTS=1.");

        Assert::assertSame(
            json_encode(json_decode((string) file_get_contents($path), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            "The response of [{$route}] no longer matches its contract ({$path}). If the change is intended, update the API consumers (frontend, docs, explorer) and re-record it with UPDATE_API_CONTRACTS=1.",
        );
    }

    /**
     * Contract file of a route, e.g. "GET api/v1/structures/{identifier}".
     */
    public static function path(string $route): string
    {
        return self::DIRECTORY.'/'.self::fileName($route);
    }

    public static function fileName(string $route): string
    {
        return str_replace(['/', ' '], ['.', ' '], $route).'.json';
    }

    /**
     * @return array{route: string, status: int, content_type: string, body: mixed}
     */
    private static function describe(TestResponse $response, string $route): array
    {
        $contentType = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
        $isJson = str_contains($contentType, 'json');

        return [
            'route' => $route,
            'status' => $response->getStatusCode(),
            'content_type' => $contentType,
            'body' => $isJson ? self::shape($response->json()) : null,
        ];
    }

    public static function shape(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            if (array_is_list($value)) {
                return [array_reduce(array_map(self::shape(...), $value), self::merge(...))];
            }

            $shape = array_map(self::shape(...), $value);
            ksort($shape);

            return $shape;
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            default => 'string',
        };
    }

    /**
     * Shape that fits both shapes (items of one list, null next to a value).
     */
    private static function merge(mixed $carry, mixed $shape): mixed
    {
        if ($carry === null || $carry === $shape) {
            return $shape;
        }

        // A value that is sometimes null keeps the shape it has when present.
        if ($carry === 'null' && is_array($shape)) {
            return $shape;
        }

        if ($shape === 'null' && is_array($carry)) {
            return $carry;
        }

        if (is_array($carry) && is_array($shape) && ! array_is_list($carry) && ! array_is_list($shape)) {
            foreach ($shape as $key => $value) {
                $carry[$key] = array_key_exists($key, $carry) ? self::merge($carry[$key], $value) : $value;
            }

            ksort($carry);

            return $carry;
        }

        if (is_array($carry) && is_array($shape) && array_is_list($carry) && array_is_list($shape)) {
            if ($carry === [] || $shape === []) {
                return $carry === [] ? $shape : $carry;
            }

            return [self::merge($carry[0], $shape[0])];
        }

        $types = array_unique([...explode('|', is_string($carry) ? $carry : 'object'), ...explode('|', is_string($shape) ? $shape : 'object')]);
        sort($types);

        return implode('|', $types);
    }
}
