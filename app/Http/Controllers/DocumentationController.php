<?php

namespace App\Http\Controllers;

use App\Filament\RichContentCustomBlocks\CaptionBlock;
use App\Filament\RichContentCustomBlocks\CodeSnippetBlock;
use App\Filament\RichContentCustomBlocks\DocumentVersionBlock;
use App\Filament\RichContentCustomBlocks\ErrorInfoboxBlock;
use App\Filament\RichContentCustomBlocks\InfoInfoboxBlock;
use App\Filament\RichContentCustomBlocks\SuccessInfoboxBlock;
use App\Filament\RichContentCustomBlocks\WarningInfoboxBlock;
use App\Models\DocumentArticle;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Http\JsonResponse;

class DocumentationController extends Controller
{
    public function tree(): JsonResponse
    {
        $roots = DocumentArticle::query()
            ->published()
            ->whereNull('parent_id')
            ->with([
                'children' => fn ($query) => $query->published(),
            ])
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $roots->map(fn (DocumentArticle $article) => $this->mapTreeNode($article))->values(),
        ]);
    }

    public function article(?string $parentSlug = null, ?string $childSlug = null): JsonResponse
    {
        $article = $this->resolveArticle($parentSlug, $childSlug);

        if (! $article) {
            return response()->json([
                'message' => 'Documentation article was not found.',
            ], 404);
        }

        $parent = $article->parent;
        $breadcrumbs = [];
        if ($parent) {
            $breadcrumbs[] = [
                'title' => $parent->title,
                'path' => $parent->slug,
            ];
        }

        $breadcrumbs[] = [
            'title' => $article->title,
            'path' => $article->fullSlug(),
        ];

        return response()->json([
            'data' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'path' => $article->fullSlug(),
                // Articles from resources/docs are stored rendered (docs:sync).
                'content' => $article->isManaged() ? $article->content : RichContentRenderer::make($article->content)
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsVisibility('public')
                    ->customBlocks([
                        InfoInfoboxBlock::class,
                        WarningInfoboxBlock::class,
                        ErrorInfoboxBlock::class,
                        SuccessInfoboxBlock::class,
                        CodeSnippetBlock::class,
                        CaptionBlock::class,
                        DocumentVersionBlock::class => [
                            'published_at' => $article->created_at?->toDateString(),
                            'updated_at' => $article->updated_at?->toDateString(),
                        ],
                    ])
                    ->toHtml(),
                'breadcrumbs' => $breadcrumbs,
            ],
        ]);
    }

    private function resolveArticle(?string $parentSlug, ?string $childSlug): ?DocumentArticle
    {
        if ($parentSlug === null && $childSlug === null) {
            return DocumentArticle::query()
                ->published()
                ->whereNull('parent_id')
                ->orderBy('position')
                ->orderBy('title')
                ->first();
        }

        if ($parentSlug !== null && $childSlug === null) {
            return DocumentArticle::query()
                ->published()
                ->whereNull('parent_id')
                ->where('slug', $parentSlug)
                ->first();
        }

        $parent = DocumentArticle::query()
            ->published()
            ->whereNull('parent_id')
            ->where('slug', $parentSlug)
            ->first();

        if (! $parent) {
            return null;
        }

        return DocumentArticle::query()
            ->published()
            ->where('parent_id', $parent->id)
            ->where('slug', $childSlug)
            ->first();
    }

    private function mapTreeNode(DocumentArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'path' => $article->fullSlug(),
            'children' => $article->children
                ->map(fn (DocumentArticle $child) => [
                    'id' => $child->id,
                    'title' => $child->title,
                    'slug' => $child->slug,
                    'path' => $child->fullSlug(),
                ])
                ->values(),
        ];
    }
}
