<?php

namespace App\Services;

use App\Services\External\Chemical\Chebi\Chebi;
use App\Services\External\Chemical\Unichem\Unichem;
use Illuminate\Support\Facades\Cache;
use Modules\Rdkit\Rdkit;

class UploadQueueExternalLookupCache
{
    private const TTL_SECONDS = 172800;

    public function canonicalSmiles(string $smiles): ?string
    {
        $smiles = trim($smiles);
        if ($smiles === '') {
            return null;
        }

        $value = $this->remember('rdkit:canonical-smiles', $smiles, function () use ($smiles): ?string {
            $canonicalSmiles = (new Rdkit)->canonize_smiles($smiles);

            return is_string($canonicalSmiles) && trim($canonicalSmiles) !== '' ? $canonicalSmiles : null;
        });

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

    private function remember(string $namespace, string $input, callable $callback): mixed
    {
        $payload = Cache::memo(config('cache.upload_lookup_store'))->remember(
            $this->key($namespace, $input),
            self::TTL_SECONDS,
            fn (): array => ['value' => $callback()],
        );

        return is_array($payload) && array_key_exists('value', $payload)
            ? $payload['value']
            : null;
    }

    private function key(string $namespace, string $input): string
    {
        return 'upload-queue:external-lookup:'.$namespace.':'.hash('sha256', mb_strtolower(trim($input)));
    }
}
