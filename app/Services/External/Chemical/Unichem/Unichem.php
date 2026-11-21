<?php

namespace App\Services\External\Chemical\Unichem;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    /**
     * @throws UnichemUnavailableException when UniChem gives no usable answer
     */
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

        try {
            return UnichemChemical::fromRawData($record);
        } catch (Throwable) {
            return null;
        }
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
                // A 4xx answer is final; only an outage is worth a second try.
                ->retry(2, 100, fn (Throwable $exception): bool => ! $exception instanceof RequestException || $exception->response->serverError(), throw: false)
                ->post($url, $params);

        } catch (ConnectionException $exception) {
            throw new UnichemUnavailableException('UniChem did not answer.', previous: $exception);
        }

        // "Not found" is an answer; an outage is not, and must not be read
        // (or cached) as an identifier that does not exist.
        if ($response->serverError() || $response->status() === 429) {
            throw new UnichemUnavailableException('UniChem answered with HTTP '.$response->status().'.');
        }

        $data = $response->successful() ? $response->json() : null;

        return is_array($data) ? $data : null;
    }
}
