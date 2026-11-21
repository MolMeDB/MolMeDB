<?php

use App\Models\Filesystem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $backup = Filesystem::where('type', Filesystem::TYPE_BACKUPS)->first();

        Filesystem::create([
            'type' => Filesystem::TYPE_PREDICTIONS_COSMO_EXPORT,
            'name' => 'Backups - predictions COSMO export',
            'description' => 'Where to keep the weekly export of prediction results with COSMO files? Must be on the same server as the prediction results storage.',
            'scope_id' => $backup->id,
            'root_path' => '/Exports/Cosmo',
        ]);
    }

    public function down(): void
    {
        Filesystem::where('type', Filesystem::TYPE_PREDICTIONS_COSMO_EXPORT)->delete();
    }
};
