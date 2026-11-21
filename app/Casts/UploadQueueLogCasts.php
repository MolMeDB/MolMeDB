<?php

namespace App\Casts;

use App\Enums\UploadQueueLogTypeEnums;
use App\ValueObjects\UploadQueueLog;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Collection;

class UploadQueueLogCasts implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): Collection
    {
        $logs = (array) json_decode($value ?? '[]');

        if (! UploadQueueLog::hasValidListStructure($logs)) {
            $logs = UploadQueueLog::remapOldLogs($logs);
        }

        return new Collection(collect(
            array_map(function ($row) {
                $object = is_array($row) ? (object) $row : $row;

                return new UploadQueueLog(
                    $object->message,
                    $object->context,
                    $object->timestamp ?? null,
                    isset($object->user_id) ? (string) $object->user_id : null,
                    $object->type ?? UploadQueueLogTypeEnums::STATE_CHANGE->value,
                    isset($object->state) ? intval($object->state) : null,
                    $this->payloadArray($object->payload ?? null),
                );
            }, $logs ?? [])
        ));
    }

    /**
     * The logs are decoded as objects, so a stored payload comes back as one
     * and is turned into the array it was saved as.
     *
     * @return array<string, mixed>|null
     */
    private function payloadArray(mixed $payload): ?array
    {
        if (is_object($payload)) {
            $payload = json_decode((string) json_encode($payload), true);
        }

        return is_array($payload) ? $payload : null;
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        $array = collect($value)->map(function ($item) {
            if ($item instanceof UploadQueueLog) {
                return $item->jsonSerialize();
            }

            return (array) $item;
        });

        return json_encode($array);
    }
}
