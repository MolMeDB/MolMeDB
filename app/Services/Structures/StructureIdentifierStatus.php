<?php

namespace App\Services\Structures;

use App\Models\Identifier;
use App\Models\Structure;
use Illuminate\Support\Carbon;

/**
 * What a MolMeDB identifier (MM00040) points to now. Identifiers are
 * persistent: one that was merged into another structure is kept as an
 * obsolete MolMeDB identifier of the surviving structure (see
 * Structure::join_structures()), a removed structure stays soft deleted.
 */
class StructureIdentifierStatus
{
    public const ACTIVE = 'active';

    public const MERGED = 'merged';

    public const DELETED = 'deleted';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $identifier,
        public readonly string $status,
        public readonly ?Structure $structure = null,
        public readonly ?Carbon $deletedAt = null,
    ) {}

    public static function of(string $identifier): self
    {
        $structure = Structure::query()->where('identifier', $identifier)->first();

        if ($structure) {
            return new self($identifier, self::ACTIVE, $structure);
        }

        $successor = Identifier::query()
            ->where('type', Identifier::TYPE_MOLMEDB)
            ->where('value', $identifier)
            ->where('state', Identifier::STATE_OBSOLETE)
            ->whereHas('structure', fn ($query) => $query->whereNotNull('identifier'))
            ->with('structure')
            ->first()
            ?->structure;

        if ($successor) {
            return new self($identifier, self::MERGED, $successor);
        }

        $deleted = Structure::onlyTrashed()->where('identifier', $identifier)->first();

        if ($deleted) {
            return new self($identifier, self::DELETED, deletedAt: $deleted->deleted_at);
        }

        return new self($identifier, self::UNKNOWN);
    }

    /**
     * Identifier of the structure this one was merged into.
     */
    public function replacedBy(): ?string
    {
        return $this->status === self::MERGED ? $this->structure?->identifier : null;
    }

    /**
     * @return array{identifier: string, status: string, replaced_by: ?string, deleted_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'status' => $this->status,
            'replaced_by' => $this->replacedBy(),
            'deleted_at' => $this->deletedAt?->toIso8601String(),
        ];
    }
}
