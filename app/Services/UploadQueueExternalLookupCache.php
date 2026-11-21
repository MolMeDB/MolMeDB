<?php

namespace App\Services;

use App\Services\External\Chemical\Chebi\Chebi;
use App\Services\External\Chemical\Unichem\Unichem;
use Illuminate\Support\Facades\Cache;
use Modules\Rdkit\Rdkit;

class UploadQueueExternalLookupCache
{
    private const TTL_SECONDS = 172800;

    /**
     * A negative answer may come from an outage of the external service
     * rather than from invalid input (the clients return null/false for
     * both), so it is kept only briefly.
     */
    private const NEGATIVE_TTL_SECONDS = 600;

    public function canonicalSmiles(string $smiles): ?string
    {
        $smiles = trim($smiles);
        if ($smiles === '') {
            return null;
        }

        // SMILES are case-sensitive (c1ccccc1 is benzene, C1CCCCC1 cyclohexane),
        // so their key keeps the case. "v2" drops entries cached under the old
        // case-insensitive key, which could hold another molecule's form.
        $value = $this->remember('rdkit:canonical-smiles:v2', $smiles, function () use ($smiles): ?string {
            $canonicalSmiles = (new Rdkit)->canonize_smiles($smiles);

            return is_string($canonicalSmiles) && trim($canonicalSmiles) !== '' ? $canonicalSmiles : null;
        }, caseSensitive: true);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function unichemIdentifierExists(int $source, string $identifier): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return false;
        }

        return (bool) $this->remember("unichem:{$source}:identifier-exists", $identifier, function () use ($source, $identifier): bool {
            return (new Unichem)->getChemicalBySourceId($source, $identifier)?->inchi !== null;
        });
    }

    public function chebiIdentifierExists(string $identifier): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return false;
        }

        return (bool) $this->remember('chebi:identifier-exists', $identifier, function () use ($identifier): bool {
            return (new Chebi)->existsById($identifier);
        });
    }

    public function publicationReferenceExists(string $reference, callable $lookup): bool
    {
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }

        return (bool) $this->remember('publication:reference-exists', $reference, fn (): bool => (bool) $lookup());
    }

    private function remember(string $namespace, string $input, callable $callback, bool $caseSensitive = false): mixed
    {
        $store = Cache::memo(config('cache.upload_lookup_store'));
        $key = $this->key($namespace, $input, $caseSensitive);
        $payload = $store->get($key);

        if (is_array($payload) && array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        $value = $callback();
        $store->put($key, ['value' => $value], $value ? self::TTL_SECONDS : self::NEGATIVE_TTL_SECONDS);

        return $value;
    }

    private function key(string $namespace, string $input, bool $caseSensitive): string
    {
        $input = trim($input);

        return 'upload-queue:external-lookup:'.$namespace.':'.hash('sha256', $caseSensitive ? $input : mb_strtolower($input));
    }
}
