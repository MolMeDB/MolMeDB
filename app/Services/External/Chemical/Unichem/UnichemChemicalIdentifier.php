<?php

namespace App\Services\External\Chemical\Unichem;

class UnichemChemicalIdentifier
{
    public ?string $compoundId = null;

    public ?int $unichemSourceId = null;

    public ?string $url = null;

    public function __construct(private readonly array $raw_data = [])
    {
        if (! empty($raw_data)) {
            $this->compoundId = $raw_data['compoundId'] ?? null;
            $this->unichemSourceId = $raw_data['id'] ?? null;
            $this->url = $raw_data['url'] ?? null;
        }
    }

    public function rawData(): array
    {
        return $this->raw_data;
    }

    public static function fromRawData(array $data): self
    {
        return new self($data);
    }
}
