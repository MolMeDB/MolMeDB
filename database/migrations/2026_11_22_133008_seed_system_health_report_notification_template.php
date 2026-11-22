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
            [
                'key' => NotificationTemplate::KEY_SYSTEM_ADMIN_ERROR_REPEATED,
                'name' => NotificationTemplate::keyOptions()[NotificationTemplate::KEY_SYSTEM_ADMIN_ERROR_REPEATED],
                'notification_title' => 'An application error keeps repeating',
                'notification_body' => '{{ occurrences }}× today: {{ error }}',
                'email_subject' => 'MolMeDB: an application error keeps repeating',
                'email_message' => '<p>The same error was reported {{ occurrences }} times today. It will not be announced again today; the daily system health report shows how often it happened.</p>'
                    .'<table style="border-collapse:collapse">'
                    .'<tr><td style="padding:4px 12px 4px 0"><strong>Error</strong></td><td>{{ error }}</td></tr>'
                    .'<tr><td style="padding:4px 12px 4px 0"><strong>Where</strong></td><td>{{ location }}</td></tr>'
                    .'<tr><td style="padding:4px 12px 4px 0"><strong>During</strong></td><td>{{ context }}</td></tr>'
                    .'</table>',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->whereIn('key', [
                NotificationTemplate::KEY_SYSTEM_ADMIN_HEALTH_REPORT,
                NotificationTemplate::KEY_SYSTEM_ADMIN_ERROR_REPEATED,
            ])
            ->delete();
    }
};
