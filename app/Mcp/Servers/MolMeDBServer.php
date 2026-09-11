<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetMembraneTool;
use App\Mcp\Tools\GetMethodTool;
use App\Mcp\Tools\GetProteinInteractionsTool;
use App\Mcp\Tools\GetProteinTool;
use App\Mcp\Tools\GetStructureInteractionsTool;
use App\Mcp\Tools\GetStructureTool;
use App\Mcp\Tools\SearchMembranesTool;
use App\Mcp\Tools\SearchMethodsTool;
use App\Mcp\Tools\SearchProteinsTool;
use App\Mcp\Tools\SearchStructuresTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MolMeDB')]
#[Version('1.0.0')]
#[Instructions('Read-only access to MolMeDB (Molecules on Membranes Database): membranes, methods, proteins, publications, molecular structures, and their passive/active membrane-interaction records. Public data, no authentication required.')]
class MolMeDBServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SearchStructuresTool::class,
        GetStructureTool::class,
        GetStructureInteractionsTool::class,
        SearchMembranesTool::class,
        GetMembraneTool::class,
        SearchMethodsTool::class,
        GetMethodTool::class,
        SearchProteinsTool::class,
        GetProteinTool::class,
        GetProteinInteractionsTool::class,
    ];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        //
    ];
}
