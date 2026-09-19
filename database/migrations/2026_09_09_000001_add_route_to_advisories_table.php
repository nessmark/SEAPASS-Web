<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('advisories') && !Schema::hasColumn('advisories', 'route')) {
            Schema::table('advisories', function (Blueprint $table) {
                $table->string('route')->nullable()->default('All Routes')->after('type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('advisories') && Schema::hasColumn('advisories', 'route')) {
            Schema::table('advisories', function (Blueprint $table) {
                $table->dropColumn('route');
            });
        }
    }
};
