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
                'key' => NotificationTemplate::KEY_PREDICTION_ADMIN_SERVER_HEALTH,
                'name' => NotificationTemplate::keyOptions()[NotificationTemplate::KEY_PREDICTION_ADMIN_SERVER_HEALTH],
                'notification_title' => '{{ title }}',
                'notification_body' => '{{ summary }}{{ items }}',
                'email_subject' => 'MolMeDB: {{ title }}',
                'email_message' => '<p>{{ summary }}</p>{{ items }}'
                    .'<p>The administration\'s dashboard shows the current state: <a href="{{ admin_url }}">{{ admin_url }}</a></p>',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('key', NotificationTemplate::KEY_PREDICTION_ADMIN_SERVER_HEALTH)
            ->delete();
    }
};
