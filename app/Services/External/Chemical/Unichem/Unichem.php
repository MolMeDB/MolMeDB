<?php

namespace App\Services\External\Chemical\Unichem;

use Illuminate\Support\Facades\Http;
use Throwable;

class Unichem
{
    private string $apiUrl;

    /**
     * LIST of relevant SOURCES
     * Can be found here: https://www.ebi.ac.uk/unichem/api/v1/sources/
     */
    public const SOURCE_CHEMBL = 1;

    public const SOURCE_DRUGBANK = 2;

    public const SOURCE_RCSB_PDB = 3;

    public const SOURCE_PDBE = 5;

    public const SOURCE_CHEBI = 7;

    public const SOURCE_PUBCHEM = 22;

    public static $sources = [
        self::SOURCE_CHEMBL,
        self::SOURCE_DRUGBANK,
        self::SOURCE_RCSB_PDB,
        self::SOURCE_PDBE,
        self::SOURCE_CHEBI,
        self::SOURCE_PUBCHEM,
    ];

    public function __construct()
    {
        $this->apiUrl = (string) config('services.unichem.base_api_url');
    }

    public function getChemicalBySourceId(int $source, string $sourceIdentifier): ?UnichemChemical
    {
        $record = $this->makeRequest('compounds', [
            'type' => 'sourceID',
            'compound' => $sourceIdentifier,
            'sourceID' => $source,
        ]);

        $record = $record['compounds'][0] ?? null;

        if (! is_array($record)) {
            return null;
        }

        return UnichemChemical::fromRawData($record);
    }

    public function is_reachable(int $timeout = 5): bool
    {
        $url = rtrim($this->apiUrl, '/').'/sources';

        try {
            return Http::connectTimeout(5)
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->get($url)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function makeRequest(string $endpoint, array $params = [], int $timeout = 5): ?array
    {
        $url = rtrim($this->apiUrl, '/').'/'.ltrim($endpoint, '/');

        try {
            $response = Http::connectTimeout(5)
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->retry(2, 100)
                ->post($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Throwable) {
            // retry() throws once a 4xx/5xx answer is final; an outage is "no answer" here.
            return null;
        }
    }
}
