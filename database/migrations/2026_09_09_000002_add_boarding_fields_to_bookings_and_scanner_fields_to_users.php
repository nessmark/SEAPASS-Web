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
        // Add boarding columns to bookings table
        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (!Schema::hasColumn('bookings', 'is_boarded')) {
                    $table->boolean('is_boarded')->default(false)->after('status');
                }
                if (!Schema::hasColumn('bookings', 'boarded_at')) {
                    $table->timestamp('boarded_at')->nullable()->after('is_boarded');
                }
                if (!Schema::hasColumn('bookings', 'boarded_by')) {
                    $table->unsignedBigInteger('boarded_by')->nullable()->after('boarded_at');
                }
            });
        }

        // Add scanner staff port assignment and status to users table
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'assigned_port')) {
                    $table->string('assigned_port')->nullable()->after('role');
                }
                if (!Schema::hasColumn('users', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('assigned_port');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (Schema::hasColumn('bookings', 'is_boarded')) {
                    $table->dropColumn('is_boarded');
                }
                if (Schema::hasColumn('bookings', 'boarded_at')) {
                    $table->dropColumn('boarded_at');
                }
                if (Schema::hasColumn('bookings', 'boarded_by')) {
                    $table->dropColumn('boarded_by');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'assigned_port')) {
                    $table->dropColumn('assigned_port');
                }
                if (Schema::hasColumn('users', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
