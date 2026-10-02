<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\Rdf\RdfEndpointUnavailable;
use App\Services\Rdf\RdfResource;
use App\Services\Rdf\RdfSparqlClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dereferences MolMeDB RDF IRIs (https://rdf.molmedb.upol.cz/..., proxied to
 * /api/rdf/...): RDF clients get the resource description in Turtle,
 * N-Triples or RDF/XML, browsers are sent (303) to the page about the
 * resource or get a simple HTML listing of its statements.
 */
class RdfController extends Controller
{
    /**
     * Supported serializations: format name => [media type, file extension].
     */
    public const FORMATS = [
        'turtle' => ['text/turtle', 'ttl'],
        'ntriples' => ['application/n-triples', 'nt'],
        'rdfxml' => ['application/rdf+xml', 'rdf'],
    ];

    private const ACCEPTED_TYPES = [
        'text/turtle' => 'turtle',
        'application/x-turtle' => 'turtle',
        'application/n-triples' => 'ntriples',
        'text/plain' => 'ntriples',
        'application/rdf+xml' => 'rdfxml',
        'application/xml' => 'rdfxml',
        'text/xml' => 'rdfxml',
        'text/html' => 'html',
        'application/xhtml+xml' => 'html',
    ];

    public function show(Request $request, string $path, RdfSparqlClient $sparql): Response
    {
        $resource = RdfResource::fromPath($path);

        abort_if($resource === null, 404);

        $format = $this->negotiate($request);

        try {
            abort_unless($sparql->describes($resource->iri()), 404);

            if ($format === 'html') {
                $landingPage = $resource->landingPage();

                if ($landingPage !== null) {
                    return redirect()->away($landingPage, 303)->header('Vary', 'Accept');
                }

                return response()
                    ->view('rdf.describe', [
                        'iri' => $resource->iri(),
                        'statements' => $sparql->statements($resource->iri()),
                        'formats' => self::FORMATS,
                    ])
                    ->header('Vary', 'Accept');
            }

            [$mimeType] = self::FORMATS[$format];

            return response($sparql->construct($resource->iri(), $mimeType), 200, [
                'Content-Type' => $mimeType.'; charset=UTF-8',
                'Vary' => 'Accept',
                'Link' => $this->alternateLinks($resource),
            ]);
        } catch (RdfEndpointUnavailable) {
            abort(503, 'The MolMeDB RDF SPARQL endpoint is temporarily unavailable.');
        }
    }

    /**
     * The MolMeDB vocabulary (OWL, RDF/XML); its terms are hash IRIs
     * (https://rdf.molmedb.upol.cz/vocabulary#LogK), so the whole document is
     * the description of each term. Served from the RDF storage, where it can
     * be replaced without a deployment.
     */
    public function vocabulary(): Response
    {
        $vocabulary = Cache::remember('rdf:vocabulary', 3600, function (): ?string {
            $file = File::query()->where('type', File::TYPE_RDF_VOCABULARY)->latest('id')->first();

            if (! $file || ! Storage::disk($file->storage)->exists($file->path)) {
                return null;
            }

            return Storage::disk($file->storage)->get($file->path);
        });

        abort_if($vocabulary === null, 404);

        return response($vocabulary, 200, ['Content-Type' => 'application/rdf+xml; charset=UTF-8']);
    }

    /**
     * Format from ?format= (turtle, ntriples, rdfxml) or the Accept header,
     * Turtle when the client accepts anything.
     */
    private function negotiate(Request $request): string
    {
        $requested = $request->query('format');

        if (is_string($requested) && array_key_exists($requested, self::FORMATS)) {
            return $requested;
        }

        foreach ($request->getAcceptableContentTypes() as $type) {
            if (isset(self::ACCEPTED_TYPES[$type])) {
                return self::ACCEPTED_TYPES[$type];
            }
        }

        return 'turtle';
    }

    private function alternateLinks(RdfResource $resource): string
    {
        return collect(self::FORMATS)
            ->map(fn (array $format, string $name): string => "<{$resource->iri()}?format={$name}>; rel=\"alternate\"; type=\"{$format[0]}\"")
            ->implode(', ');
    }
}
