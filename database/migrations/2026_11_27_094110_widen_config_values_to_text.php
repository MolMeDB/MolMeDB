<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application keeps small state in configs as well (the prediction
 * server alert, JSON), which does not fit 512 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->string('value', 512)->nullable()->change();
        });
    }
};
