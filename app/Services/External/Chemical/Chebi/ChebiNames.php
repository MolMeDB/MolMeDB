<?php

namespace App\Services\External\Chemical\Chebi;

class ChebiNames
{
    public function __construct(
        public ?string $iupac_name = null,
        public ?array $synonyms = null
    ) {}

    public static function fromApiResponse(array $response): self
    {
        $iupac_name = $response['names']['IUPAC NAME'][0]['ascii_name'] ?? null;
        $iupac_name = $iupac_name ? ucfirst(strtolower($iupac_name)) : null;

        $synonyms = [];

        foreach ($response['names']['SYNONYM'] ?? [] as $synonym) {
            if (! empty($synonym['ascii_name'])) {
                $synonyms[] = ucfirst(strtolower((string) $synonym['ascii_name']));
            }
        }

        return new self($iupac_name, array_values(array_unique($synonyms)));
    }
}
