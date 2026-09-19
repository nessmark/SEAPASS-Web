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
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->foreignId('schedule_template_id')
                ->nullable()
                ->after('boat_id')
                ->constrained('schedule_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropForeign(['schedule_template_id']);
            $table->dropColumn('schedule_template_id');
        });
    }
};
