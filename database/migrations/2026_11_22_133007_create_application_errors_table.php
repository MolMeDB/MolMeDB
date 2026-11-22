<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per day and kind of error: what the application reported and
     * how often, for the daily system health report.
     */
    public function up(): void
    {
        Schema::create('application_errors', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->char('fingerprint', 40);
            $table->string('exception');
            $table->text('message');
            $table->string('location')->nullable();
            $table->string('context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('notified_at')->nullable();

            $table->unique(['day', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_errors');
    }
};
