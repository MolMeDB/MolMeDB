<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\MolMeDBOverviewResource;
use App\Mcp\Tools\FindSimilarStructuresTool;
use App\Mcp\Tools\GetInteractionTool;
use App\Mcp\Tools\GetMembraneTool;
use App\Mcp\Tools\GetMethodTool;
use App\Mcp\Tools\GetProteinTool;
use App\Mcp\Tools\GetPublicationTool;
use App\Mcp\Tools\GetStructureMolfileTool;
use App\Mcp\Tools\GetStructureTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\SearchInteractionsTool;
use App\Mcp\Tools\SearchMembranesTool;
use App\Mcp\Tools\SearchMethodsTool;
use App\Mcp\Tools\SearchProteinsTool;
use App\Mcp\Tools\SearchPublicationsTool;
use App\Mcp\Tools\SearchStructuresTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MolMeDB')]
#[Version('1.0.0')]
#[Instructions('Read-only access to MolMeDB (Molecules on Membranes Database): membranes, methods, proteins, publications, molecular structures, and their passive/active membrane-interaction records. Public data, no authentication required. Typical workflow: find a structure (search-structures), then its interactions with filters (search-interactions); the molmedb-overview resource explains the values and their units.')]
class MolMeDBServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SearchStructuresTool::class,
        GetStructureTool::class,
        FindSimilarStructuresTool::class,
        GetStructureMolfileTool::class,
        SearchInteractionsTool::class,
        GetInteractionTool::class,
        SearchMembranesTool::class,
        GetMembraneTool::class,
        SearchMethodsTool::class,
        GetMethodTool::class,
        SearchProteinsTool::class,
        GetProteinTool::class,
        SearchPublicationsTool::class,
        GetPublicationTool::class,
        ListCategoriesTool::class,
    ];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        MolMeDBOverviewResource::class,
    ];
}
