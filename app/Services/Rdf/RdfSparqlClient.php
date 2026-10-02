<?php

namespace App\Services\Rdf;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads the published MolMeDB RDF from its SPARQL endpoint (IDSM, see
 * config fair.rdf.sparql_endpoint). The endpoint holds the RDF the IRIs were
 * minted in, so dereferenced documents always match the published dataset.
 *
 * Answers are cached for a day: the RDF is only refreshed when IDSM imports a
 * new MolMeDB dump.
 */
class RdfSparqlClient
{
    private const CACHE_SECONDS = 86400;

    private const TIMEOUT_SECONDS = 15;

    /**
     * Whether the RDF has any statement about the resource.
     *
     * @throws RdfEndpointUnavailable
     */
    public function describes(string $iri): bool
    {
        return Cache::remember($this->cacheKey('ask', $iri), self::CACHE_SECONDS, function () use ($iri): bool {
            $response = $this->query("ASK { <{$iri}> ?p ?o }", 'application/sparql-results+json');

            return (bool) ($response->json('boolean') ?? false);
        });
    }

    /**
     * Statements about the resource (as subject), serialized by the endpoint.
     *
     * @param  string  $mimeType  text/turtle, application/n-triples or application/rdf+xml
     *
     * @throws RdfEndpointUnavailable
     */
    public function construct(string $iri, string $mimeType): string
    {
        return Cache::remember($this->cacheKey('construct:'.$mimeType, $iri), self::CACHE_SECONDS, fn (): string => $this
            ->query("CONSTRUCT { <{$iri}> ?p ?o } WHERE { <{$iri}> ?p ?o }", $mimeType)
            ->body());
    }

    /**
     * Statements about the resource with labels of the linked resources, for the HTML view.
     *
     * @return array<int, array{predicate: string, object: string, object_type: string, datatype: ?string, label: ?string}>
     *
     * @throws RdfEndpointUnavailable
     */
    public function statements(string $iri): array
    {
        return Cache::remember($this->cacheKey('statements', $iri), self::CACHE_SECONDS, function () use ($iri): array {
            $query = <<<SPARQL
                SELECT ?p ?o (SAMPLE(?label) AS ?l) WHERE {
                  <{$iri}> ?p ?o .
                  OPTIONAL { ?o <http://www.w3.org/2000/01/rdf-schema#label> ?label }
                }
                GROUP BY ?p ?o
                ORDER BY ?p ?o
                SPARQL;

            $bindings = $this->query($query, 'application/sparql-results+json')->json('results.bindings') ?? [];

            return array_map(fn (array $row): array => [
                'predicate' => $row['p']['value'],
                'object' => $row['o']['value'],
                'object_type' => $row['o']['type'],
                'datatype' => $row['o']['datatype'] ?? null,
                'label' => $row['l']['value'] ?? null,
            ], $bindings);
        });
    }

    /**
     * @throws RdfEndpointUnavailable
     */
    private function query(string $query, string $accept): \Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout(self::TIMEOUT_SECONDS)
                ->accept($accept)
                ->asForm()
                ->post((string) config('fair.rdf.sparql_endpoint'), ['query' => $query])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new RdfEndpointUnavailable('The MolMeDB RDF SPARQL endpoint is not available.', previous: $exception);
        }
    }

    private function cacheKey(string $kind, string $iri): string
    {
        return 'rdf:'.$kind.':'.sha1($iri);
    }
}
