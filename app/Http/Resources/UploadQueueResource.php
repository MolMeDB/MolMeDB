<?php

namespace App\Http\Resources;

use App\Models\UploadQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadQueueResource extends JsonResource
{
    /**
     * The parts of a log's payload the uploader may see. A payload is an
     * internal record (database ids, sample rows, the address a notification
     * went to), and these responses also go to anyone holding a tracking
     * link, so a key is published only when it is listed here.
     */
    private const PUBLIC_LOG_PAYLOAD_KEYS = [
        'dataset_type',
        'dataset_name',
        'publication_pmid',
        'comment',
        'file_name',
        'file_size',
        'file_mime',
        'file_deleted',
        'validated_rows',
        'processed_rows',
        'prepared_rows',
        'created_rows',
        'skipped_rows',
        'next_line',
        'retry_delay_seconds',
        'retry_at',
        'service',
        'irreversible_notice',
        'admin_review_approved',
        'reason',
    ];

    /**
     * Lists of validation messages, published up to this many entries each.
     */
    private const PUBLIC_LOG_PAYLOAD_LISTS = ['errors', 'warnings'];

    private const PUBLIC_LOG_PAYLOAD_LIST_LIMIT = 100;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastLog = $this->logs?->last();

        return [
            'id' => $this->id,
            'state' => $this->state,
            'state_label' => UploadQueue::$ui_enum_states[$this->state] ?? UploadQueue::enumState($this->state),
            'state_phase' => UploadQueue::enumState($this->state),
            'can_reupload' => $this->canBeReuploaded(),
            'can_configure' => $this->canBeConfigured(),
            'can_enqueue' => $this->canBeEnqueued(),
            'can_revert' => $this->canBeRevertedToConfigState(),
            'can_cancel' => $this->canBeCanceled(),
            'dataset' => DatasetResource::make($this->dataset),
            'secondary_publication' => PublicationResource::make($this->publication),
            'file' => FileResource::make($this->file),
            'last_message' => $lastLog?->message,
            'config' => $this->config->toFrontendArray(),
            'processing_progress' => $this->processingProgress(),
            'logs' => $this->logs->map(fn ($log) => [
                'message' => $log->message,
                'context' => $log->context->value,
                'type' => $log->type->value,
                'state' => $log->state,
                'state_label' => $log->state !== null
                    ? (UploadQueue::$ui_enum_states[$log->state] ?? UploadQueue::enumState($log->state))
                    : null,
                'payload' => $this->publicLogPayload($log->payload),
                'timestamp' => $log->timestamp,
                'user_id' => $log->user_id,
            ])->values(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function publicLogPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $public = [];

        foreach (self::PUBLIC_LOG_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload) && (is_scalar($payload[$key]) || $payload[$key] === null)) {
                $public[$key] = $payload[$key];
            }
        }

        foreach (self::PUBLIC_LOG_PAYLOAD_LISTS as $key) {
            if (is_array($payload[$key] ?? null)) {
                $public[$key] = array_slice(
                    array_values(array_filter($payload[$key], is_string(...))),
                    0,
                    self::PUBLIC_LOG_PAYLOAD_LIST_LIMIT,
                );
            }
        }

        return $public === [] ? null : $public;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function processingProgress(): ?array
    {
        $progress = $this->config->processingProgress();
        if ($progress === []) {
            return null;
        }

        $processedRows = (int) ($progress['processed_rows'] ?? $progress['validated_rows'] ?? 0);
        $totalRows = isset($progress['total_rows']) && is_numeric($progress['total_rows'])
            ? (int) $progress['total_rows']
            : $this->config->validatedRows();

        return [
            'phase' => $progress['phase'] ?? null,
            'mode' => $progress['mode'] ?? null,
            'processed_rows' => $processedRows,
            'created_rows' => isset($progress['created_rows']) ? (int) $progress['created_rows'] : null,
            'skipped_rows' => isset($progress['skipped_rows']) ? (int) $progress['skipped_rows'] : null,
            'next_line' => isset($progress['next_line']) ? (int) $progress['next_line'] : null,
            'total_rows' => $totalRows,
            'percent' => $totalRows && $totalRows > 0
                ? min(100, max(0, round(($processedRows / $totalRows) * 100, 1)))
                : null,
            'heartbeat_at' => $progress['heartbeat_at'] ?? null,
        ];
    }
}
