<?php

namespace App\Models;

use Database\Factories\ApplicationErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An error the application reported, counted per day
 * (see App\Services\ApplicationErrorRecorder).
 */
class ApplicationError extends Model
{
    /** @use HasFactory<ApplicationErrorFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
