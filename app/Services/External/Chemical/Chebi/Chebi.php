<?php

namespace App\Services\External\Chemical\Chebi;

use Illuminate\Support\Facades\Http;
use Throwable;

class Chebi
{
    private string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = (string) config('services.chebi.base_api_url');
    }

    public function existsById(string $chebi_id): bool
    {
        $record = $this->getById($chebi_id);

        return $record !== null;
    }

    public function getById(string $chebi_id): ?ChebiRecord
    {
        $response = $this->makeRequest('/compound/'.$this->pathSegment($chebi_id), [
            'only_ontology_parents' => 'false',
            'only_ontology_children' => 'false',
        ]);

        if (empty($response) || ! is_array($response)) {
            return null;
        }

        return ChebiRecord::fromApiResponse($response);
    }

    public function getStructureById(string $chebi_id, $width = 300, $height = 300): ?string
    {
        $url = rtrim($this->apiUrl, '/').'/compound/'.$this->pathSegment($chebi_id).'/structure/';

        try {
            $response = Http::connectTimeout(5)
                ->timeout(5)
                ->retry(2, 100)
                ->get($url, ['width' => (int) $width, 'height' => (int) $height]);

            if ($response->successful()) {
                return $response->body();
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The identifier without its "CHEBI:" prefix, safe to put into the path.
     */
    private function pathSegment(string $chebi_id): string
    {
        return rawurlencode((string) preg_replace('/^CHEBI:/i', '', trim($chebi_id)));
    }

    private function makeRequest(string $endpoint, array $params = [], int $timeout = 5): array|string|null
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
