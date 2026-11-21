<?php

namespace App\Services\Structures;

use App\Libraries\Identifiers;
use App\Models\Identifier;
use App\Models\Structure;
use App\Models\StructureLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyStructureLinksPreprocessor
{
    /**
     * @return array{
     *     processed:int,
     *     conflicts:int,
     *     reassigned:int,
     *     obsolete_created:int,
     *     obsolete_updated:int,
     *     skipped_current_identifier:int
     * }
     */
    public function process(): array
    {
        $stats = [
            'processed' => 0,
            'conflicts' => 0,
            'reassigned' => 0,
            'obsolete_created' => 0,
            'obsolete_updated' => 0,
            'skipped_current_identifier' => 0,
        ];

        if (! Schema::hasTable('structure_links')) {
            return $stats;
        }

        StructureLink::query()
            ->with('structure')
            ->orderBy('id')
            ->chunkById(200, function ($links) use (&$stats): void {
                foreach ($links as $link) {
                    $stats['processed']++;

                    DB::transaction(function () use ($link, &$stats): void {
                        $link->refresh();

                        if (! $link->structure) {
                            return;
                        }

                        $activeStructure = Structure::query()
                            ->where('identifier', $link->identifier)
                            ->first();

                        if ($activeStructure && $activeStructure->id !== $link->structure_id) {
                            $stats['conflicts']++;

                            $activeStructure->identifier = $this->generateAvailableIdentifier($activeStructure);
                            $activeStructure->save();

                            $stats['reassigned']++;
                        }

                        $link->structure->refresh();

                        if ($link->structure->identifier === $link->identifier) {
                            $stats['skipped_current_identifier']++;

                            return;
                        }

                        $sourceIdentifierId = Identifier::withTrashed()
                            ->where('structure_id', $link->structure_id)
                            ->where('type', Identifier::TYPE_MOLMEDB)
                            ->where('value', $link->structure->identifier)
                            ->value('id');

                        $obsoleteIdentifier = Identifier::withTrashed()
                            ->where('structure_id', $link->structure_id)
                            ->where('type', Identifier::TYPE_MOLMEDB)
                            ->where('value', $link->identifier)
                            ->first();

                        $payload = [
                            'source_id' => $sourceIdentifierId,
                            'source_type' => $sourceIdentifierId ? Identifier::class : null,
                            'state' => Identifier::STATE_OBSOLETE,
                        ];

                        if (! $obsoleteIdentifier) {
                            Identifier::create([
                                ...$payload,
                                'structure_id' => $link->structure_id,
                                'type' => Identifier::TYPE_MOLMEDB,
                                'value' => $link->identifier,
                            ]);

                            $stats['obsolete_created']++;

                            return;
                        }

                        if ($obsoleteIdentifier->trashed()) {
                            $obsoleteIdentifier->restore();
                        }

                        $obsoleteIdentifier->fill($payload);
                        $obsoleteIdentifier->save();

                        $stats['obsolete_updated']++;
                    });
                }
            });

        return $stats;
    }

    protected function generateAvailableIdentifier(Structure $structure): string
    {
        if ($structure->parent) {
            return $this->generateAvailableChildIdentifier($structure);
        }

        return $this->generateAvailableRootIdentifier($structure->id);
    }

    protected function generateAvailableChildIdentifier(Structure $structure): string
    {
        $parentIdentifier = (string) $structure->parent?->identifier;

        if ($parentIdentifier === '' || Identifiers::isSubIdentifier($parentIdentifier)) {
            return $this->generateAvailableRootIdentifier($structure->id);
        }

        $suffix = 1;

        while (true) {
            $candidate = $parentIdentifier.'.'.$suffix;

            if (! $this->identifierExistsAnywhere($candidate, $structure->id)) {
                return $candidate;
            }

            $suffix++;
        }
    }

    protected function generateAvailableRootIdentifier(int $ignoreStructureId): string
    {
        $maxFromStructures = (int) Structure::withTrashed()
            ->where('identifier', 'like', Identifiers::PREFIX.'%')
            ->selectRaw("MAX((substring(split_part(identifier, '.', 1) from '[0-9]+$'))::int) as max_number")
            ->value('max_number');

        $maxFromLinks = (int) StructureLink::query()
            ->where('identifier', 'like', Identifiers::PREFIX.'%')
            ->selectRaw("MAX((substring(split_part(identifier, '.', 1) from '[0-9]+$'))::int) as max_number")
            ->value('max_number');

        $maxFromIdentifiers = (int) Identifier::withTrashed()
            ->where('type', Identifier::TYPE_MOLMEDB)
            ->where('value', 'like', Identifiers::PREFIX.'%')
            ->selectRaw("MAX((substring(split_part(value, '.', 1) from '[0-9]+$'))::int) as max_number")
            ->value('max_number');

        $nextNumber = max($maxFromStructures, $maxFromLinks, $maxFromIdentifiers) + 1;

        while (true) {
            $candidate = Identifiers::get_identifier($nextNumber);

            if (! $this->identifierExistsAnywhere($candidate, $ignoreStructureId)) {
                return $candidate;
            }

            $nextNumber++;
        }
    }

    protected function identifierExistsAnywhere(string $identifier, ?int $ignoreStructureId = null): bool
    {
        $usedByStructure = Structure::withTrashed()
            ->where('identifier', $identifier)
            ->when(
                $ignoreStructureId,
                fn ($query) => $query->where('id', '!=', $ignoreStructureId),
            )
            ->exists();

        if ($usedByStructure) {
            return true;
        }

        if (StructureLink::query()->where('identifier', $identifier)->exists()) {
            return true;
        }

        return Identifier::withTrashed()
            ->where('type', Identifier::TYPE_MOLMEDB)
            ->where('value', $identifier)
            ->exists();
    }
}
