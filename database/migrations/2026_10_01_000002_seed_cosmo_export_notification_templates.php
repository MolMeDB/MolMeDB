<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $row = fn (string $label, string $value): string => '<tr><td style="padding:4px 12px 4px 0"><strong>'.$label.'</strong></td><td>{{ '.$value.' }}</td></tr>';

        DB::table('notification_templates')->insertOrIgnore([
            [
                'key' => NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
                'name' => NotificationTemplate::keyOptions()[NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED],
                'notification_title' => 'COSMO export finished',
                'notification_body' => 'Weekly COSMO export {{ date }}: {{ total }} configurations, {{ added }} new, {{ updated }} updated, {{ removed }} removed.',
                'email_subject' => 'MolMeDB: weekly COSMO export finished ({{ date }})',
                'email_message' => '<p><strong>Weekly COSMO export — {{ date }}</strong><br><em>{{ export_path }}</em></p><table style="border-collapse:collapse">'
                    .$row('Configurations in the dump', 'total')
                    .$row('New archives', 'added')
                    .$row('Updated archives', 'updated')
                    .$row('Removed archives', 'removed')
                    .$row('Unchanged archives', 'unchanged')
                    .$row('Skipped predictions (no COSMO files or empty result)', 'skipped')
                    .$row('Dump size', 'size')
                    .$row('Duration', 'duration')
                    .'</table>',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED,
                'name' => NotificationTemplate::keyOptions()[NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED],
                'notification_title' => 'COSMO export failed',
                'notification_body' => 'Weekly COSMO export failed while {{ step }}: {{ error }}',
                'email_subject' => 'MolMeDB: weekly COSMO export failed',
                'email_message' => '<p>The weekly COSMO export failed. The previous dump was kept.</p><table style="border-collapse:collapse">'
                    .$row('Step', 'step')
                    .$row('Error', 'error')
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
                NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FINISHED,
                NotificationTemplate::KEY_SYSTEM_ADMIN_COSMO_EXPORT_FAILED,
            ])
            ->delete();
    }
};
