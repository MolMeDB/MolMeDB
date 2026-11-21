<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Publication;
use Illuminate\Support\Str;
use Modules\References\CrossRef\CrossRef;
use Modules\References\EuropePMC\Enums\Sources;
use Modules\References\EuropePMC\EuropePMC;
use Modules\References\Models\Record;
use RuntimeException;
use Throwable;

class PublicationReferenceResolver
{
    /**
     * @var array<string, bool>
     */
    private static array $existsCache = [];

    public function __construct(private readonly UploadQueueExternalLookupCache $externalLookupCache) {}

    public function normalizeReference(string $reference): string
    {
        $reference = trim($reference);
        $reference = preg_replace('/^https?:\/\/(dx\.)?doi\.org\//i', '', $reference) ?? $reference;
        $reference = preg_replace('/^doi:\s*/i', '', $reference) ?? $reference;

        return trim($reference);
    }

    public function referenceExists(string $reference): bool
    {
        $reference = $this->normalizeReference($reference);
        if ($reference === '') {
            return false;
        }

        $cacheKey = mb_strtolower($reference);
        if (array_key_exists($cacheKey, self::$existsCache)) {
            return self::$existsCache[$cacheKey];
        }

        if ($this->findPublicationByReference($reference)) {
            return self::$existsCache[$cacheKey] = true;
        }

        $exists = $this->externalLookupCache->publicationReferenceExists(
            $reference,
            fn (): bool => $this->fetchPublicationRecord($reference) instanceof Record,
        );

        // Only found references stay in this process-wide cache: a miss may be
        // an outage of EuropePMC or CrossRef, and a queue worker keeps this
        // class for its whole life, so a cached miss would last until restart.
        if ($exists) {
            self::$existsCache[$cacheKey] = true;
        }

        return $exists;
    }

    public function resolveOrCreatePublication(string $reference): Publication
    {
        $reference = $this->normalizeReference($reference);
        if ($reference === '') {
            throw new RuntimeException('Publication reference is empty.');
        }

        $publication = $this->findPublicationByReference($reference);
        if ($publication) {
            return $publication;
        }

        $referenceRecord = $this->fetchPublicationRecord($reference);
        if (! $referenceRecord) {
            throw new RuntimeException("Unable to resolve publication reference '{$reference}'.");
        }

        $publication = $this->findPublicationByRecord($referenceRecord);
        if ($publication) {
            return $publication;
        }

        return $this->createPublicationFromReferenceRecord($referenceRecord, $reference);
    }

    private function findPublicationByReference(string $reference): ?Publication
    {
        return Publication::query()
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
    }

    private function findPublicationByIdentifier(string $identifier, Sources $source): ?Publication
    {
        return Publication::query()
            ->where('identifier_source', $source->value)
            ->where('identifier', $identifier)
            ->first();
    }

    private function findPublicationByRecord(Record $record): ?Publication
    {
        if (is_string($record->id) && trim($record->id) !== '' && $record->source instanceof Sources) {
            $publication = $this->findPublicationByIdentifier(trim($record->id), $record->source);
            if ($publication) {
                return $publication;
            }
        }

        if (is_string($record->doi) && trim($record->doi) !== '') {
            return Publication::query()
                ->whereRaw('LOWER(doi) = ?', [mb_strtolower(trim($record->doi))])
                ->first();
        }

        return null;
    }

    private function fetchPublicationRecord(string $reference): ?Record
    {
        if (ctype_digit($reference)) {
            return $this->fetchEuropePmcRecord($reference, Sources::MED);
        }

        $record = $this->fetchCrossRefRecord($reference);
        if ($record) {
            return $record;
        }

        return $this->searchEuropePmcRecordByDoi($reference);
    }

    private function fetchEuropePmcRecord(string $identifier, Sources $source): ?Record
    {
        try {
            return (new EuropePMC)->detail($identifier, $source);
        } catch (Throwable) {
            return null;
        }
    }

    private function fetchCrossRefRecord(string $doi): ?Record
    {
        try {
            return (new CrossRef)->work($doi);
        } catch (Throwable) {
            return null;
        }
    }

    private function searchEuropePmcRecordByDoi(string $doi): ?Record
    {
        try {
            $result = (new EuropePMC)->search('DOI:"'.$doi.'"', pageSize: 1);
        } catch (Throwable) {
            return null;
        }

        $records = is_array($result) ? ($result['records'] ?? []) : [];
        $record = $records[0] ?? null;

        return $record instanceof Record ? $record : null;
    }

    private function createPublicationFromReferenceRecord(Record $record, string $fallbackIdentifier): Publication
    {
        $doi = $record->doi ? trim($record->doi) : null;
        $identifier = $record->id ? trim($record->id) : (ctype_digit($fallbackIdentifier) ? $fallbackIdentifier : null);
        $identifierSource = $record->source?->value ?? (ctype_digit($fallbackIdentifier) ? Sources::MED->value : null);

        $publication = Publication::query()->create([
            'citation' => Str::limit(
                $record->citation() ?: ($record->title ?? $doi ?? 'Unknown citation'),
                1024
            ),
            'title' => $record->title ? Str::limit($record->title, 512) : null,
            'doi' => $doi ?? (! ctype_digit($fallbackIdentifier) ? $fallbackIdentifier : null),
            'identifier' => $identifier,
            'identifier_source' => $identifierSource,
            'journal' => $record->journal?->title,
            'volume' => $record->journal?->volume,
            'issue' => $record->journal?->issue,
            'page' => $record->pageInfo,
            'year' => is_numeric($record->journal?->yearOfPublication) ? (int) $record->journal?->yearOfPublication : null,
            'published_at' => $record->publicationDate(),
            'validated_at' => now(),
        ]);

        if (is_array($record->authors)) {
            foreach ($record->authors as $author) {
                $authorModel = Author::firstOrCreate([
                    'first_name' => $author->firstName,
                    'last_name' => $author->lastName,
                    'full_name' => $author->fullName,
                    'affiliation' => $author->affiliations && count($author->affiliations) > 0 ? $author->affiliations[0] : null,
                ]);

                $publication->authors()->syncWithoutDetaching([$authorModel->id]);
            }
        }

        return $publication->refresh();
    }
}
