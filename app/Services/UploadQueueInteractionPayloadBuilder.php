<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Dataset;
use App\Models\Identifier;
use App\Models\Protein;
use App\Models\ProteinIdentifier;
use App\Models\Publication;
use App\Models\Structure;
use App\Models\UploadQueue;
use App\Rules\UploadFile\ActiveInteractions\ColumnInteractionType;
use Modules\References\EuropePMC\Enums\Sources;
use RuntimeException;

class UploadQueueInteractionPayloadBuilder
{
    private const DEFAULT_ACTIVE_CATEGORY_TITLE = 'Unassigned';

    /**
     * Per-instance memoization caches. An upload file commonly repeats the same
     * compound/protein/publication across many rows, so without these caches
     * every row pays for a fresh RDKit call and several DB lookups.
     *
     * @var array<string, string>
     */
    private array $canonicalSmilesCache = [];

    /**
     * @var array<string, int|null>
     */
    private array $structureResolutionCache = [];

    /**
     * @var array<string, int|null>
     */
    private array $proteinResolutionCache = [];

    private ?ColumnInteractionType $interactionTypeColumn = null;

    /**
     * @var array<string, int|null>
     */
    private array $publicationResolutionCache = [];

    public function __construct(
        private readonly UploadQueueColumnRegistry $columns,
        private readonly PublicationReferenceResolver $publicationResolver,
        private readonly UploadQueueExternalLookupCache $externalLookupCache,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function passivePayload(UploadQueue $record, array $row, bool $createMissingRecords = true): array
    {
        return [
            'dataset_id' => $record->dataset_id,
            'structure_id' => $this->resolveStructureId($row, $record, $createMissingRecords),
            'publication_id' => $this->resolvePublicationId($record, $row, $createMissingRecords),
            ...$this->interactionValues($row, $this->columns->commonValueColumns()),
            ...$this->interactionValues($row, $this->columns->interactionValueColumns($record)),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function activePayload(UploadQueue $record, array $row, bool $createMissingRecords = true): array
    {
        $payload = [
            'dataset_id' => $record->dataset_id,
            'structure_id' => $this->resolveStructureId($row, $record, $createMissingRecords),
            'protein_id' => $this->resolveProteinId($row, $createMissingRecords),
            'publication_id' => $this->resolvePublicationId($record, $row, $createMissingRecords),
            ...$this->interactionValues($row, $this->columns->commonValueColumns()),
            ...$this->interactionValues($row, $this->columns->interactionValueColumns($record)),
        ];

        if ($createMissingRecords) {
            $payload['category_id'] = $this->resolveActiveCategoryId($row);
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<int|string, string>  $columns
     * @return array<string, mixed>
     */
    public function interactionValues(array $row, array $columns): array
    {
        $values = [];

        foreach ($columns as $source => $target) {
            if (is_int($source)) {
                $source = $target;
            }

            $value = $row[$source] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $values[$target] = $this->normalizeInteractionValue($target, $value);
        }

        return $values;
    }

    public function normalizeInteractionValue(string $column, string $value): float|string
    {
        $value = trim($value);

        if (in_array($column, ['charge', 'note'], true)) {
            return $value;
        }

        return round((float) str_replace(',', '.', $value), 2);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveStructureId(array $row, ?UploadQueue $record, bool $createMissingStructure): ?int
    {
        $identifierColumns = [
            'pubchem' => Identifier::TYPE_PUBCHEM,
            'chembl' => Identifier::TYPE_CHEMBL,
            'pdb' => Identifier::TYPE_PDB,
            'drugbank' => Identifier::TYPE_DRUGBANK,
            'chebi' => Identifier::TYPE_CHEBI,
            'name' => Identifier::TYPE_NAME,
        ];

        $cacheKey = $this->structureResolutionCacheKey($row, $identifierColumns, $createMissingStructure);
        if (array_key_exists($cacheKey, $this->structureResolutionCache)) {
            return $this->structureResolutionCache[$cacheKey];
        }

        $structure = null;
        $smiles = trim($row['smiles'] ?? '');

        if ($smiles !== '') {
            $smiles = $this->canonizeSmiles($smiles);
        }

        if ($smiles !== '') {
            $structure = Structure::withTrashed()
                ->where('canonical_smiles', $smiles)
                ->first();
        }

        foreach ($identifierColumns as $column => $type) {
            if ($structure || ! isset($row[$column])) {
                continue;
            }

            $identifier = Identifier::withTrashed()
                ->where('type', $type)
                ->where('value', trim($row[$column]))
                ->first();

            $structure = $identifier?->structure;
        }

        if (! $structure && $smiles === '') {
            throw new RuntimeException('Unable to resolve structure for imported row. Fill SMILES column or provide at least one valid structure identifier (PubChem CID, ChEMBL ID, PDB ID, DrugBank ID, ChEBI ID).');
        }

        if (! $structure && $createMissingStructure) {
            $structure = Structure::create([
                'canonical_smiles' => trim($smiles),
            ]);

            $structure->identifiers()->createMany(array_map(
                fn (string $column): array => [
                    'type' => $identifierColumns[$column],
                    'value' => trim($row[$column]),
                    'state' => Identifier::STATE_NEW,
                    'source_id' => $record?->dataset_id,
                    'source_type' => $record ? Dataset::class : null,
                ],
                array_filter(array_keys($identifierColumns), fn (string $column): bool => ! empty($row[$column] ?? null))
            ));
        }

        return $this->structureResolutionCache[$cacheKey] = $structure ? (int) $structure->id : null;
    }

    private function canonizeSmiles(string $smiles): string
    {
        if (array_key_exists($smiles, $this->canonicalSmilesCache)) {
            return $this->canonicalSmilesCache[$smiles];
        }

        $canonicalSmiles = $this->externalLookupCache->canonicalSmiles($smiles);
        if (! $canonicalSmiles) {
            throw new RuntimeException('Unable to canonize SMILES for imported row. Check RDKit service availability and SMILES validity.');
        }

        return $this->canonicalSmilesCache[$smiles] = $canonicalSmiles;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $identifierColumns
     */
    private function structureResolutionCacheKey(array $row, array $identifierColumns, bool $createMissingStructure): string
    {
        $parts = [trim($row['smiles'] ?? '')];

        foreach (array_keys($identifierColumns) as $column) {
            $parts[] = trim($row[$column] ?? '');
        }

        $parts[] = $createMissingStructure ? '1' : '0';

        return implode('|', $parts);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveProteinId(array $row, bool $createMissingProtein): ?int
    {
        $target = trim($row['active_target'] ?? '');
        if ($target === '') {
            throw new RuntimeException('Unable to resolve protein: target column is empty.');
        }

        $cacheKey = mb_strtolower($target).'|'.($createMissingProtein ? '1' : '0');
        if (array_key_exists($cacheKey, $this->proteinResolutionCache)) {
            return $this->proteinResolutionCache[$cacheKey];
        }

        $protein = Protein::withTrashed()
            ->whereRaw('LOWER(uniprot_id) = ?', [mb_strtolower($target)])
            ->first();

        if (! $protein && $createMissingProtein) {
            $protein = Protein::create([
                'uniprot_id' => $target,
            ]);
        }

        if ($protein && $createMissingProtein) {
            $this->addProteinName($protein, trim($row['protein_name'] ?? ''));
        }

        return $this->proteinResolutionCache[$cacheKey] = $protein ? (int) $protein->id : null;
    }

    /**
     * Adds the name to the protein unless the protein already has it. Existing names are never overwritten.
     */
    private function addProteinName(Protein $protein, string $name): void
    {
        if ($name === '') {
            return;
        }

        $exists = ProteinIdentifier::query()
            ->where('protein_id', $protein->id)
            ->where('type', ProteinIdentifier::TYPE_NAME)
            ->whereRaw('LOWER(value) = ?', [mb_strtolower($name)])
            ->exists();

        if (! $exists) {
            ProteinIdentifier::create([
                'protein_id' => $protein->id,
                'value' => $name,
                'type' => ProteinIdentifier::TYPE_NAME,
                'state' => ProteinIdentifier::STATE_NEW,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveActiveCategoryId(array $row): int
    {
        $type = trim($row[ColumnInteractionType::$key] ?? '');

        if ($type !== '') {
            $categoryId = $this->interactionTypeColumn()->categoryId($type);
            if ($categoryId !== null) {
                return $categoryId;
            }

            throw new RuntimeException("Unable to resolve interaction type: $type.");
        }

        return $this->defaultActiveCategoryId();
    }

    private function interactionTypeColumn(): ColumnInteractionType
    {
        return $this->interactionTypeColumn ??= ColumnInteractionType::make();
    }

    private function defaultActiveCategoryId(): int
    {
        return (int) Category::query()
            ->firstOrCreate([
                'title' => self::DEFAULT_ACTIVE_CATEGORY_TITLE,
                'type' => Category::TYPE_ACTIVE_INTERACTION,
            ], [
                'parent_id' => -1,
                'order' => 0,
            ])
            ->id;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolvePublicationId(UploadQueue $record, array $row, bool $createMissingPublication): ?int
    {
        $reference = $this->publicationResolver->normalizeReference($row['primaryReference'] ?? '');

        if ($reference !== '') {
            $cacheKey = mb_strtolower($reference).'|'.($createMissingPublication ? '1' : '0');
            if (array_key_exists($cacheKey, $this->publicationResolutionCache)) {
                return $this->publicationResolutionCache[$cacheKey];
            }

            if (! $createMissingPublication) {
                return $this->publicationResolutionCache[$cacheKey] = $this->findPublicationId($reference) ?? -1;
            }

            return $this->publicationResolutionCache[$cacheKey] = (int) $this->publicationResolver->resolveOrCreatePublication($reference)->id;
        }

        $sourcePublicationId = $record->config['source_publication_id'] ?? null;

        return is_numeric($sourcePublicationId) ? (int) $sourcePublicationId : null;
    }

    private function findPublicationId(string $reference): ?int
    {
        $publication = Publication::query()
            ->where(function ($query) use ($reference): void {
                $query
                    ->where(function ($query) use ($reference): void {
                        $query
                            ->where('identifier_source', Sources::MED->value)
                            ->where('identifier', $reference);
                    })
                    ->orWhereRaw('LOWER(doi) = ?', [mb_strtolower($reference)]);
            })
            ->first();

        return $publication ? (int) $publication->id : null;
    }
}
