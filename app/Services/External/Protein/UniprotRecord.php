<?php

namespace App\Services\External\Protein;

class UniprotRecord
{
    public ?string $entryType = null;

    public ?string $id = null;

    public ?UniprotProteinDescription $proteinDescription = null;

    /** @var UniprotProteinComment[] */
    public $comments = [];

    public static function fromApiResponse(array $data): self
    {
        $record = new self;
        $record->entryType = $data['entryType'] ?? null;
        $record->id = $data['primaryAccession'] ?? null;
        if (isset($data['proteinDescription']) && is_array($data['proteinDescription'])) {
            $record->proteinDescription = UniprotProteinDescription::fromRawData($data['proteinDescription']);
        }
        if (isset($data['comments']) && is_array($data['comments'])) {
            foreach ($data['comments'] as $commentData) {
                if (! is_array($commentData)) {
                    continue;
                }
                $record->comments[] = UniprotProteinComment::fromRawData($commentData);
            }
        }

        return $record;
    }

    public function isActive(): bool
    {
        return $this->entryType !== 'Inactive';
    }
}
