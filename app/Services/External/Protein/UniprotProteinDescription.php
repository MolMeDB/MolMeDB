<?php

namespace App\Services\External\Protein;

class UniprotProteinDescription
{
    public function __construct(
        public array $recommendedName,
        public array $alternativeNames) {}

    public static function fromRawData(array $data): self
    {
        $recommendedName = $data['recommendedName'] ?? [];
        $alternativeNames = $data['alternativeNames'] ?? [];

        return new self($recommendedName, $alternativeNames);
    }

    public function getRecommendedName(): ?string
    {
        return $this->recommendedName['fullName']['value'] ?? null;
    }

    public function getRecommendedShortNames(): array
    {
        $names = [];
        foreach ($this->recommendedName['shortNames'] ?? [] as $shortName) {
            if (! empty($shortName['value'])) {
                $names[] = $shortName['value'];
            }
        }

        return $names;
    }

    public function getRecommendedNameEvidences(): array
    {
        return $this->recommendedName['fullName']['evidences'] ?? [];
    }

    public function getAlternativeNames(): array
    {
        $names = [];
        foreach ($this->alternativeNames as $alternativeName) {
            if (! empty($alternativeName['fullName']['value'])) {
                $names[] = $alternativeName['fullName']['value'];
            }
        }

        return $names;
    }
}
