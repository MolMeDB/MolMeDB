<?php

namespace App\Services\Rdf;

/**
 * A resource in the MolMeDB RDF namespace (https://rdf.molmedb.upol.cz/...),
 * see the URI patterns in the MolMeDB RDF article (J. Cheminform. 2026,
 * doi:10.1186/s13321-026-01208-3, Table 2).
 */
class RdfResource
{
    /**
     * Path of a dereferenceable resource, relative to the RDF base URL.
     */
    public const PATH_PATTERN = '(substance|interaction|transporter|reference)/[A-Za-z0-9_.,\-]+|molecule/MM[0-9]+(\.[0-9]+)?';

    private function __construct(public readonly string $path) {}

    public static function fromPath(string $path): ?self
    {
        return preg_match('#^(?:'.self::PATH_PATTERN.')$#', $path) === 1 ? new self($path) : null;
    }

    /**
     * Molecules use their identifiers.org IRI (https://identifiers.org/molmedb/MM00040),
     * which resolves to /mol/MM00040; RDF requests for that page come here as
     * molecule/MM00040.
     */
    public function iri(): string
    {
        if (str_starts_with($this->path, 'molecule/')) {
            return 'https://identifiers.org/'.config('fair.identifiers_org_namespace').'/'.substr($this->path, strlen('molecule/'));
        }

        return rtrim((string) config('fair.rdf.base_url'), '/').'/'.$this->path;
    }

    /**
     * Human-readable page about the resource on the public site, when there is one.
     */
    public function landingPage(): ?string
    {
        $site = config('fair.frontend_url');
        [$domain, $local] = explode('/', $this->path, 2);

        return match (true) {
            $domain === 'molecule' => "{$site}/mol/{$local}",
            // Substance attributes: substance/{MolMeDB identifier}_{attribute}
            $domain === 'substance' && preg_match('/^(MM\d+(?:\.\d+)?)_/', $local, $match) === 1 => "{$site}/mol/{$match[1]}",
            $domain === 'interaction' && preg_match('/^membrane(\d+)$/', $local, $match) === 1 => "{$site}/membrane/{$match[1]}",
            $domain === 'interaction' && preg_match('/^method(\d+)$/', $local, $match) === 1 => "{$site}/method/{$match[1]}",
            $domain === 'transporter' && preg_match('/^target(\d+)$/', $local, $match) === 1 => "{$site}/protein/{$match[1]}",
            $domain === 'reference' && preg_match('/^ref(\d+)$/', $local, $match) === 1 => "{$site}/publication/{$match[1]}",
            default => null,
        };
    }
}
