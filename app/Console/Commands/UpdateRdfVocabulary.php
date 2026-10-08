<?php

namespace App\Console\Commands;

use App\Http\Controllers\RdfController;
use App\Models\File;
use App\Models\Filesystem;
use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Publishes a new version of the MolMeDB RDF vocabulary (OWL, RDF/XML), which
 * https://rdf.molmedb.upol.cz/vocabulary serves. The vocabulary is maintained
 * with the R2RML mappings (fair.rdf.vocabulary_source); every version is kept
 * in the RDF storage and the newest one is served.
 */
class UpdateRdfVocabulary extends Command
{
    protected $signature = 'rdf:update-vocabulary
        {source? : URL or local path of vocabulary.owl (default: config fair.rdf.vocabulary_source)}
        {--dry-run : Only download and check the vocabulary, store nothing}';

    protected $description = 'Stores a new version of the MolMeDB RDF vocabulary served at https://rdf.molmedb.upol.cz/vocabulary.';

    private const NAMESPACE = 'https://rdf.molmedb.upol.cz/vocabulary#';

    public function handle(): int
    {
        $source = (string) ($this->argument('source') ?: config('fair.rdf.vocabulary_source'));

        try {
            $vocabulary = $this->load($source);
            $terms = $this->check($vocabulary);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Vocabulary from {$source} is valid: {$terms} terms in ".self::NAMESPACE);

        $latest = File::query()->where('type', File::TYPE_RDF_VOCABULARY)->latest('id')->first();

        if ($latest?->hash === md5($vocabulary)) {
            $this->info('The served vocabulary is already this version, nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing stored.');

            return self::SUCCESS;
        }

        $filesystem = Filesystem::query()->where('type', Filesystem::TYPE_RDF_STORAGE)->first();

        if (! $filesystem?->isInitialized()) {
            $this->error('The RDF storage filesystem is not configured.');

            return self::FAILURE;
        }

        $path = 'vocabulary/vocabulary-'.now()->format('Y-m-d-His').'.owl';
        Storage::disk($filesystem->systemName)->put($path, $vocabulary);

        File::query()->create([
            'type' => File::TYPE_RDF_VOCABULARY,
            'name' => 'vocabulary.owl',
            'path' => $path,
            'storage' => $filesystem->systemName,
            'mime' => RdfController::FORMATS['rdfxml'][0],
            'hash' => md5($vocabulary),
            'comment' => "Imported from {$source}",
        ]);

        Cache::forget(RdfController::VOCABULARY_CACHE_KEY);

        $this->info("Stored as {$path} and served from now on.");

        return self::SUCCESS;
    }

    private function load(string $source): string
    {
        if (preg_match('#^https?://#', $source)) {
            $response = Http::timeout(30)->get($source);

            if (! $response->successful()) {
                throw new RuntimeException("Downloading {$source} failed with HTTP {$response->status()}.");
            }

            return $response->body();
        }

        if (! is_readable($source)) {
            throw new RuntimeException("{$source} cannot be read.");
        }

        return (string) file_get_contents($source);
    }

    /**
     * The file must be RDF/XML with an OWL ontology defining terms in the
     * namespace the published RDF uses (https, not the old http one).
     *
     * @return int number of terms defined in the namespace
     */
    private function check(string $vocabulary): int
    {
        $document = new DOMDocument;

        if (trim($vocabulary) === '' || ! @$document->loadXML($vocabulary)) {
            throw new RuntimeException('The vocabulary is not well-formed XML.');
        }

        $root = $document->documentElement;

        if ($root?->localName !== 'RDF' || $root->namespaceURI !== 'http://www.w3.org/1999/02/22-rdf-syntax-ns#') {
            throw new RuntimeException('The vocabulary is not an RDF/XML document.');
        }

        if ($document->getElementsByTagNameNS('http://www.w3.org/2002/07/owl#', 'Ontology')->length === 0) {
            throw new RuntimeException('The vocabulary does not declare an owl:Ontology.');
        }

        $terms = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            $about = $element->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about');

            if (str_starts_with($about, 'http://rdf.molmedb.upol.cz/vocabulary')) {
                throw new RuntimeException("The vocabulary uses the old http namespace ({$about}).");
            }

            if (str_starts_with($about, self::NAMESPACE)) {
                $terms[$about] = true;
            }
        }

        if ($terms === []) {
            throw new RuntimeException('The vocabulary defines no terms in '.self::NAMESPACE.'.');
        }

        return count($terms);
    }
}
