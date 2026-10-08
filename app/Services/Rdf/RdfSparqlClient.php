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

    private const TIMEOUT_SECONDS = 30;

    /**
     * Statements per direction in a dereferenced RDF document.
     */
    public const DESCRIPTION_LIMIT = 10000;

    /**
     * Statements per direction in the HTML listing.
     */
    public const LISTING_LIMIT = 500;

    /**
     * Whether the RDF has any statement with the resource as subject or object.
     *
     * @throws RdfEndpointUnavailable
     */
    public function describes(string $iri): bool
    {
        return Cache::remember($this->cacheKey('ask', $iri), self::CACHE_SECONDS, function () use ($iri): bool {
            $response = $this->query("ASK { { <{$iri}> ?p ?o } UNION { ?s ?p <{$iri}> } }", 'application/sparql-results+json');

            return (bool) ($response->json('boolean') ?? false);
        });
    }

    /**
     * Description of the resource, serialized by the endpoint: the statements
     * leading out of it and into it, at most DESCRIPTION_LIMIT each way (a
     * data source such as PubChem is the subject and object of >100,000).
     *
     * @param  string  $mimeType  text/turtle, application/n-triples or application/rdf+xml
     *
     * @throws RdfEndpointUnavailable
     */
    public function construct(string $iri, string $mimeType): string
    {
        $limit = self::DESCRIPTION_LIMIT;

        return Cache::remember($this->cacheKey('construct:'.$mimeType, $iri), self::CACHE_SECONDS, fn (): string => $this
            ->query(<<<SPARQL
                CONSTRUCT { <{$iri}> ?p ?o . ?s ?q <{$iri}> }
                WHERE {
                  { SELECT ?p ?o WHERE { <{$iri}> ?p ?o } LIMIT {$limit} }
                  UNION
                  { SELECT ?s ?q WHERE { ?s ?q <{$iri}> } LIMIT {$limit} }
                }
                SPARQL, $mimeType)
            ->body());
    }

    /**
     * Statements leading out of and into the resource, with labels of the
     * resources on the other side, for the HTML view; at most LISTING_LIMIT
     * each way (`truncated` says whether there are more).
     *
     * @return array{outgoing: array{rows: array<int, array{predicate: string, value: string, type: string, datatype: ?string, label: ?string}>, truncated: bool}, incoming: array{rows: array<int, array{predicate: string, value: string, type: string, datatype: ?string, label: ?string}>, truncated: bool}}
     *
     * @throws RdfEndpointUnavailable
     */
    public function statements(string $iri): array
    {
        return Cache::remember($this->cacheKey('statements', $iri), self::CACHE_SECONDS, fn (): array => [
            'outgoing' => $this->listing("<{$iri}> ?p ?x"),
            'incoming' => $this->listing("?x ?p <{$iri}>"),
        ]);
    }

    /**
     * @return array{rows: array<int, array{predicate: string, value: string, type: string, datatype: ?string, label: ?string}>, truncated: bool}
     */
    private function listing(string $pattern): array
    {
        $limit = self::LISTING_LIMIT + 1;
        $query = <<<SPARQL
            SELECT ?p ?x (SAMPLE(?label) AS ?l) WHERE {
              {$pattern} .
              OPTIONAL { ?x <http://www.w3.org/2000/01/rdf-schema#label> ?label }
            }
            GROUP BY ?p ?x
            LIMIT {$limit}
            SPARQL;

        // Sorted here: the IDSM endpoint fails on ORDER BY over a subject variable.
        $bindings = $this->query($query, 'application/sparql-results+json')->json('results.bindings') ?? [];
        usort($bindings, fn (array $a, array $b): int => [$a['p']['value'], $a['x']['value']] <=> [$b['p']['value'], $b['x']['value']]);

        return [
            'rows' => array_map(fn (array $row): array => [
                'predicate' => $row['p']['value'],
                'value' => $row['x']['value'],
                'type' => $row['x']['type'],
                'datatype' => $row['x']['datatype'] ?? null,
                'label' => $row['l']['value'] ?? null,
            ], array_slice($bindings, 0, self::LISTING_LIMIT)),
            'truncated' => count($bindings) > self::LISTING_LIMIT,
        ];
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
