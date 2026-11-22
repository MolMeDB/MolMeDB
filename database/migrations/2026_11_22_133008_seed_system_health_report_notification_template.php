<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('notification_templates')->insertOrIgnore([
            [
                'key' => NotificationTemplate::KEY_SYSTEM_ADMIN_HEALTH_REPORT,
                'name' => NotificationTemplate::keyOptions()[NotificationTemplate::KEY_SYSTEM_ADMIN_HEALTH_REPORT],
                'notification_title' => 'System health {{ date }}: {{ status }}',
                'notification_body' => '{{ summary }}',
                'email_subject' => 'MolMeDB: system health {{ date }} — {{ status }}',
                'email_message' => '<p><strong>System health — {{ date }}</strong><br>{{ status }}</p>{{ report }}'
                    .'<p><em>Errors and failures are those of {{ date }}; the prediction queue, the search index, logs and disk space are as they were when this report was made.</em></p>',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('key', NotificationTemplate::KEY_SYSTEM_ADMIN_HEALTH_REPORT)
            ->delete();
    }
};
