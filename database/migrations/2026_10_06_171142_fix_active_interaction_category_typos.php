<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Misspelled active interaction category titles mapped to their correct form.
     *
     * @var array<string, string>
     */
    private array $renames = [
        'Nonsubstate + inhibitor' => 'Non-substrate + inhibitor',
        'Nonsubtrate + noninhibitor' => 'Non-substrate + non-inhibitor',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            $this->rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            $this->rename($to, $from);
        }
    }

    private function rename(string $from, string $to): void
    {
        DB::table('categories')
            ->where('type', Category::TYPE_ACTIVE_INTERACTION)
            ->where('title', $from)
            ->update(['title' => $to, 'updated_at' => now()]);
    }
};
