<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Api\Public\V1\MethodResource;
use App\Models\Method;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Mirrors GET /api/v1/methods/{id} + its stats endpoint
 * (MethodController::show/stats) combined into one call.
 */
#[Name('get-method')]
#[Description('Get detail (including categories and interaction/structure counts) for one MolMeDB method by id.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMethodTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $method = Method::find($validated['id']);

        if (! $method) {
            return Response::error('Method not found.');
        }

        $method->load('categories');

        return Response::structured([
            'method' => MethodResource::make($method)->resolve(),
            'total' => [
                'interactions_passive' => $method->interactionsPassive()->count(),
                'interactions_active' => $method->interactionsActive()->count(),
                'structures' => $method->interactionsPassive()->distinct('structure_id')->count(),
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('The method\'s numeric id.')
                ->required(),
        ];
    }
}
