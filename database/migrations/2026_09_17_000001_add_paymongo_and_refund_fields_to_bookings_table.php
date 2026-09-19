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
            if (!Schema::hasColumn('bookings', 'paymongo_checkout_session_id')) {
                $table->string('paymongo_checkout_session_id')->nullable()->index()->after('amount_collected');
            }
            if (!Schema::hasColumn('bookings', 'paymongo_payment_intent_id')) {
                $table->string('paymongo_payment_intent_id')->nullable()->index()->after('paymongo_checkout_session_id');
            }
            if (!Schema::hasColumn('bookings', 'paymongo_payment_id')) {
                $table->string('paymongo_payment_id')->nullable()->index()->after('paymongo_payment_intent_id');
            }
            if (!Schema::hasColumn('bookings', 'paymongo_refund_id')) {
                $table->string('paymongo_refund_id')->nullable()->after('paymongo_payment_id');
            }
            if (!Schema::hasColumn('bookings', 'refund_status')) {
                $table->string('refund_status')->nullable()->after('paymongo_refund_id'); // e.g. 'none', 'pending', 'refunded', 'failed'
            }
            if (!Schema::hasColumn('bookings', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_status');
            }
            if (!Schema::hasColumn('bookings', 'refund_reason')) {
                $table->text('refund_reason')->nullable()->after('refund_amount');
            }
            if (!Schema::hasColumn('bookings', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('refund_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $columns = [
                'paymongo_checkout_session_id',
                'paymongo_payment_intent_id',
                'paymongo_payment_id',
                'paymongo_refund_id',
                'refund_status',
                'refund_amount',
                'refund_reason',
                'rejection_reason',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
