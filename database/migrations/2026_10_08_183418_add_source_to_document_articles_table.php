<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An article can be published from a Markdown file in resources/docs by
 * `php artisan docs:sync`: "source" is the path of the file, and
 * "synced_from_source" whether the file is published (the article is then
 * read-only in the administration). Turning it off keeps the path, so the
 * article can be edited by hand without being taken over again. A file
 * belongs to one article at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_articles', function (Blueprint $table): void {
            $table->string('source', 255)->nullable()->unique()->after('content');
            $table->boolean('synced_from_source')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('document_articles', function (Blueprint $table): void {
            $table->dropColumn(['source', 'synced_from_source']);
        });
    }
};
