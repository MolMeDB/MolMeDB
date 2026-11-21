<?php

namespace App\Services\External\Chemical\Unichem;

class UnichemChemical
{
    public ?string $charge = null;

    public ?string $formula = null;

    public ?string $inchi = null;

    public ?array $identifiers = null;

    public function __construct(private readonly array $raw_data = [])
    {
        if (! empty($raw_data['inchi']) && is_array($raw_data['inchi'])) {
            $this->inchi = $raw_data['inchi']['inchi'] ?? null;
            $this->formula = $raw_data['inchi']['formula'] ?? null;
            $this->charge = $raw_data['inchi']['charge'] ?? null;
        }

        if (! empty($raw_data['sources']) && is_array($raw_data['sources'])) {
            $this->identifiers = [];
            foreach ($raw_data['sources'] as $source) {
                if (! is_array($source) || ! in_array($source['id'] ?? null, Unichem::$sources, true)) {
                    continue;
                }

                $this->identifiers[] = UnichemChemicalIdentifier::fromRawData($source);
            }
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
