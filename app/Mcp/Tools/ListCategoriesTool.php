<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\CategoryTreeCollection;
use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/public/v1/{membranes,methods,proteins}/categories.
 */
#[Description('Get the category tree for membranes, methods, or proteins — use category ids from here as the category_id argument on the matching search tool.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCategoriesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'entity' => ['required', 'in:membrane,method,protein'],
        ]);

        [$type, $relation, $collection] = match ($validated['entity']) {
            'membrane' => [Category::TYPE_MEMBRANE, 'membranes', fn ($categories) => CategoryTreeCollection::forMembranes($categories)],
            'method' => [Category::TYPE_METHOD, 'methods', fn ($categories) => CategoryTreeCollection::forMethods($categories)],
            'protein' => [Category::TYPE_PROTEIN, 'proteins', fn ($categories) => CategoryTreeCollection::forProteins($categories)],
        };

        $categories = Category::where('type', $type)
            ->with($relation)
            ->orderBy('order', 'asc')
            ->get();

        return Response::structured([
            'data' => $collection($categories)->resolve(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()
                ->enum(['membrane', 'method', 'protein'])
                ->description('Which category tree to fetch.')
                ->required(),
        ];
    }
}
