<?php

namespace App\Services\External\Protein;

use Illuminate\Support\Facades\Http;
use Throwable;

class Uniprot
{
    private string $apiUrl;

    /**
     * Uniprot request fields.
     */
    const FIELD_ACCESSION = 'accession';

    const FIELD_PROTEIN_NAME = 'protein_name';

    const FIELD_CC_FUNCTION = 'cc_function';

    const FIELD_FT_BINDING = 'ft_binding';

    public function __construct()
    {
        $this->apiUrl = (string) config('services.uniprot.base_api_url');
    }

    /**
     * A deleted accession still answers with an "Inactive" entry, so only an
     * active record counts as an existing protein.
     */
    public function existsById(string $uniprotId): bool
    {
        return $this->getById($uniprotId)?->isActive() ?? false;
    }

    public function getById(string $uniprotId): ?UniprotRecord
    {
        $response = $this->makeRequest('/uniprotkb/'.rawurlencode($uniprotId), [
            ...$this->makeFieldParam([
                self::FIELD_ACCESSION,
                self::FIELD_PROTEIN_NAME,
                // self::FIELD_CC_FUNCTION,
                // self::FIELD_FT_BINDING,
            ]),
        ]);

        if (empty($response)) {
            return null;
        }

        return UniprotRecord::fromApiResponse($response);
    }

    private function makeFieldParam(array $fields): array
    {
        return ['fields' => implode(',', $fields)];
    }

    private function makeRequest(string $endpoint, array $params = [], int $timeout = 5): ?array
    {
        $url = rtrim($this->apiUrl, '/').'/'.ltrim($endpoint, '/');

        try {
            $response = Http::connectTimeout(5)
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->retry(2, 100)
                ->get($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
