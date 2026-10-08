<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles generated from resources/docs by `php artisan docs:sync` keep the
 * path of their source file; they are read-only in the administration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_articles', function (Blueprint $table): void {
            $table->string('source', 255)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('document_articles', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
