<?php

namespace App\Services\Documentation;

use App\Http\Controllers\Api\Public\V1\AboutController;
use App\Http\Controllers\RdfController;
use App\Http\Requests\Api\Public\V1\SearchInteractionsRequest;
use App\Http\Requests\Api\Public\V1\SearchStructureRequest;
use App\Http\Requests\Api\Public\V1\SimilarStructuresRequest;
use App\Http\Requests\Lab\StoreLabUploadRequest;
use App\Http\Requests\StorePredictionDatasetRequest;
use App\Mcp\Servers\MolMeDBServer;
use App\Models\Category;
use App\Models\UploadQueue;
use App\Services\UploadQueueColumnRegistry;
use App\Support\PublicApiUrl;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * The facts documentation templates (resources/docs) take from the code
 * instead of repeating them, available there as $docs.
 */
class DocumentationCatalog
{
    /**
     * Absolute URL of the public REST API, e.g. apiUrl('structures').
     */
    public function apiUrl(string $path = ''): string
    {
        return PublicApiUrl::to($path);
    }

    /**
     * Public API endpoints described in config/api_explorer.php, in the
     * order given; a key ending with "*" stands for every route under it.
     *
     * Each one comes with its path parameters and the shortened response
     * of its example request (resources/docs/_examples), when there is one.
     *
     * @return array<int, array{uri: string, path: string, description: string, path_parameters: array<string, array{label: string, example: string}>, query: array<string, array{required: bool, example: string, description: string}>, example: string, url: string, is_download: bool, response: ?string}>
     */
    public function endpoints(string ...$uris): array
    {
        $routes = config('api_explorer.routes');
        $selected = [];

        foreach ($uris as $uri) {
            $matching = str_ends_with($uri, '*')
                ? array_filter(array_keys($routes), fn (string $key): bool => str_starts_with($key, rtrim($uri, '*')))
                : [$uri];

            foreach ($matching as $key) {
                $route = $routes[$key];
                $selected[$key] = [
                    'uri' => $key,
                    'path' => "/api/v1/{$key}",
                    'description' => $route['description'],
                    'path_parameters' => $this->pathParameters($key),
                    'query' => $route['query'],
                    'example' => $route['example'],
                    'url' => $this->apiUrl(ltrim($route['example'], '/')),
                    'is_download' => $route['is_download'] ?? false,
                    'response' => $this->exampleResponse(str_replace(['{', '}', '/'], ['', '', '.'], $key)),
                ];
            }
        }

        return array_values($selected);
    }

    /**
     * Shortened real response of an example request, stored in
     * resources/docs/_examples/{name}.json (or .mol for a molfile).
     */
    public function exampleResponse(string $name): ?string
    {
        foreach (['json', 'mol'] as $extension) {
            $path = resource_path("docs/_examples/{$name}.{$extension}");

            if (is_file($path)) {
                return rtrim((string) file_get_contents($path));
            }
        }

        return null;
    }

    /**
     * Units and meaning of the measured values ("interactions_passive",
     * "interactions_active"), the same as GET /about returns.
     *
     * @return array<string, array<string, array{unit: string, description: string}>>
     */
    public function units(): array
    {
        return AboutController::UNITS;
    }

    /**
     * Request limits of the public API per client IP address.
     *
     * @return array<string, array{per_minute: int, per_hour?: int, per_day?: int}>
     */
    public function rateLimits(): array
    {
        return config('public_api.rate_limits');
    }

    /**
     * @return array{interactions: int, similar_structures: int, identifiers: int, similarity_threshold: array{default: float, min: float}}
     */
    public function pageLimits(): array
    {
        return [
            'interactions' => SearchInteractionsRequest::MAX_PER_PAGE,
            'similar_structures' => SimilarStructuresRequest::MAX_PER_PAGE,
            'identifiers' => SearchStructureRequest::MAX_IDENTIFIERS,
            'similarity_threshold' => [
                'default' => SimilarStructuresRequest::DEFAULT_THRESHOLD,
                'min' => SimilarStructuresRequest::MIN_THRESHOLD,
            ],
        ];
    }

    /**
     * Tools of the MCP server: name, description and parameters.
     *
     * @return array<int, array{name: string, description: string, parameters: array<string, array{type: string, required: bool, description: string}>}>
     */
    public function mcpTools(): array
    {
        $tools = (new ReflectionClass(MolMeDBServer::class))->getProperty('tools')->getDefaultValue();

        return array_map(function (string $class): array {
            /** @var Tool $tool */
            $tool = app($class);
            $definition = $tool->toArray();
            $schema = $definition['inputSchema'] ?? [];
            $required = $schema['required'] ?? [];

            return [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'parameters' => collect($schema['properties'] ?? [])
                    ->map(fn (array $property, string $name): array => [
                        'type' => $this->schemaType($property),
                        'required' => in_array($name, $required, true),
                        'description' => $property['description'] ?? '',
                    ])
                    ->all(),
            ];
        }, $tools);
    }

    /**
     * Columns a file of the given upload type (UploadQueue::TYPE_*) can be
     * mapped to: key => label shown in the configuration dialog.
     *
     * @return array<string, string>
     */
    public function uploadColumns(int $type): array
    {
        return collect(app(UploadQueueColumnRegistry::class)->validatorClasses($type))
            ->mapWithKeys(fn (string $class): array => [$class::$key => $class::$label])
            ->all();
    }

    /**
     * Types of active interactions (their categories), e.g. "Substrate".
     *
     * @return array<int, string>
     */
    public function activeInteractionTypes(): array
    {
        return Category::query()
            ->where('type', Category::TYPE_ACTIVE_INTERACTION)
            ->orderBy('order')
            ->orderBy('title')
            ->pluck('title')
            ->all();
    }

    /**
     * States of an upload as the website shows them, in the order of the
     * workflow.
     *
     * @return array<int, string>
     */
    public function uploadStates(): array
    {
        return UploadQueue::$ui_enum_states;
    }

    /**
     * @return array{max_kilobytes: int, extensions: array<int, string>}
     */
    public function uploadFile(): array
    {
        return [
            'max_kilobytes' => StoreLabUploadRequest::MAX_FILE_KILOBYTES,
            'extensions' => StoreLabUploadRequest::FILE_EXTENSIONS,
        ];
    }

    /**
     * @return array{max_molecules: int, temperature: array{min: int, max: int, default: int}, description_length: int, max_atoms: int, allowed_elements: array<int, string>}
     */
    public function predictionLimits(): array
    {
        return [
            'max_molecules' => StorePredictionDatasetRequest::MAX_MOLECULES,
            'temperature' => [
                'min' => StorePredictionDatasetRequest::MIN_TEMPERATURE,
                'max' => StorePredictionDatasetRequest::MAX_TEMPERATURE,
                'default' => 25,
            ],
            'description_length' => StorePredictionDatasetRequest::MAX_DESCRIPTION_LENGTH,
            'max_atoms' => (int) config('prediction-workers.structure_validation.max_atoms'),
            'allowed_elements' => config('prediction-workers.structure_validation.allowed_elements'),
        ];
    }

    /**
     * RDF serializations of dereferenced IRIs: name => [media type, extension].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function rdfFormats(): array
    {
        return RdfController::FORMATS;
    }

    public function fair(string $key): mixed
    {
        return config("fair.{$key}");
    }

    /**
     * Text safe inside a Markdown table cell.
     */
    public function cell(?string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], (string) $text);
    }

    /**
     * Path parameters of a route URI ("structures/{identifier}") with their
     * label and example from config/api_explorer.php.
     *
     * @return array<string, array{label: string, example: string}>
     */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{(\w+)\}/', $uri, $matches);

        return collect($matches[1])
            ->mapWithKeys(fn (string $name): array => [$name => config("api_explorer.path_params.{$name}")])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function schemaType(array $property): string
    {
        $type = $property['type'] ?? 'string';
        $type = is_array($type) ? implode(' or ', $type) : $type;

        return isset($property['enum'])
            ? $type.': '.implode(', ', array_map(fn ($value): string => (string) $value, $property['enum']))
            : Str::of($type)->toString();
    }
}
