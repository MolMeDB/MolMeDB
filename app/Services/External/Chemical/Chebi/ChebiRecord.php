<?php

namespace App\Services\External\Chemical\Chebi;

class ChebiRecord
{
    public function __construct(
        public ?int $id,
        public string $chebi_id,
        public ?string $name,
        public ?ChebiChemicalData $chemical_data = null,
        public ?ChebiNames $names = null,
    ) {}

    public static function fromApiResponse(array $response): self
    {
        return new self(
            id: $response['id'] ?? null,
            chebi_id: $response['chebi_accession'] ?? '',
            name: ! empty($response['ascii_name']) ? ucfirst(strtolower((string) $response['ascii_name'])) : null,
            chemical_data: isset($response['chemical_data']) ? ChebiChemicalData::fromApiResponse($response) : null,
            names: isset($response['names']) ? ChebiNames::fromApiResponse($response) : null,
        );
    }
}
