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
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'passenger_id')) {
                $table->foreignId('passenger_id')->nullable()->after('trip_schedule_id')->constrained('passengers')->nullOnDelete();
            }
            if (!Schema::hasColumn('bookings', 'email')) {
                $table->string('email')->nullable()->after('contact_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'passenger_id')) {
                $table->dropForeign(['passenger_id']);
                $table->dropColumn('passenger_id');
            }
            if (Schema::hasColumn('bookings', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
