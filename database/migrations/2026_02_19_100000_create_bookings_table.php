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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('trip_schedule_id')->constrained('trip_schedules')->onDelete('cascade');
            $table->foreignId('passenger_id')->nullable()->constrained('passengers')->nullOnDelete();
            $table->string('passenger_name');
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->string('route');
            $table->date('trip_date');
            $table->string('departure_time_slot');
            $table->json('seat_numbers');
            $table->json('seat_breakdown')->nullable();
            $table->string('status')->default('pending');
            $table->text('qr_code')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('amount_collected', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
