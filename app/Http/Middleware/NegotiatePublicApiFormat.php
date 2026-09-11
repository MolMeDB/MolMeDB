<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API content negotiation:
 *  - Accept explicitly mentioning `text/html` (a browser navigating to the
 *    URL) -> the interactive "try it" explorer page for the matched route,
 *    instead of calling the controller at all.
 *  - Accept explicitly mentioning `application/ld+json` -> flag the request
 *    so resources can return a schema.org/Bioschemas JSON-LD representation
 *    instead of the plain flat one, then fall through to the controller.
 *  - Anything else (missing, `*\/*`, `application/json`, ...) -> the normal
 *    JSON endpoint response, same as before.
 */
class NegotiatePublicApiFormat
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $accept = strtolower((string) $request->headers->get('Accept'));

        if (str_contains($accept, 'text/html')) {
            return $this->renderExplorer($request);
        }

        if (str_contains($accept, 'application/ld+json')) {
            $request->attributes->set('response_format', 'jsonld');
        }

        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }

    private function renderExplorer(Request $request): Response
    {
        $route = $request->route();
        $uri = Str::after($route->uri(), 'api/public/v1/');
        $config = config("api_explorer.routes.$uri");

        if ($config === null) {
            return response()->json([
                'message' => 'No explorer page is available for this endpoint.',
            ], 404);
        }

        $rawPathValues = $this->extractRawPathParams($route, $request);
        $pathParams = [];

        foreach ($route->parameterNames() as $name) {
            $meta = config("api_explorer.path_params.$name", ['label' => $name, 'example' => '']);

            $pathParams[] = [
                'name' => $name,
                'label' => $meta['label'],
                'value' => $rawPathValues[$name] ?? $meta['example'],
            ];
        }

        $queryParams = [];

        foreach ($config['query'] ?? [] as $name => $meta) {
            $queryParams[] = [
                'name' => $name,
                'required' => $meta['required'] ?? false,
                'description' => $meta['description'] ?? '',
                'value' => $request->query($name, ''),
                'placeholder' => $meta['example'] ?? '',
            ];
        }

        return response()->view('api-explorer.show', [
            'uriTemplate' => '/'.$uri,
            'description' => $config['description'] ?? '',
            'pathParams' => $pathParams,
            'queryParams' => $queryParams,
            'exampleRequest' => $config['example'] ?? '/'.$uri,
            'isDownload' => $config['is_download'] ?? false,
            'maxResponseLines' => config('api_explorer.max_response_lines', 300),
            'baseUrl' => rtrim($request->getSchemeAndHttpHost().'/api/public/v1', '/'),
        ]);
    }

    /**
     * $route->parameter() returns the resolved route-model-binding instance
     * (e.g. a Membrane), not the raw URL segment — walk the URI template and
     * the actual request path side by side to recover the raw string instead.
     *
     * @return array<string, string>
     */
    private function extractRawPathParams($route, Request $request): array
    {
        $template = explode('/', $route->uri());
        $actual = explode('/', $request->path());
        $values = [];

        foreach ($template as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with(rtrim($segment, '?'), '}')) {
                $name = trim($segment, '{}?');
                $values[$name] = $actual[$i] ?? '';
            }
        }

        return $values;
    }
}
