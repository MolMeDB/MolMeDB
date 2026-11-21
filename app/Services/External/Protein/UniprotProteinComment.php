<?php

namespace App\Services\External\Protein;

class UniprotProteinComment
{
    public function __construct(
        public array $texts,
        public ?string $commentType) {}

    public static function fromRawData(array $data): self
    {
        $texts = $data['texts'] ?? [];
        $commentType = $data['commentType'] ?? null;

        return new self($texts, $commentType);
    }
}
