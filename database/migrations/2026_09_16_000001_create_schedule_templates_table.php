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
        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained('boats')->onDelete('cascade');
            $table->string('route');
            $table->string('departure_time', 10); // Format: 'H:i' e.g. '10:30'
            $table->string('arrival_time', 10)->nullable(); // Format: 'H:i' e.g. '12:30'
            $table->string('recurrence_type', 20)->default('daily'); // 'daily', 'weekly'
            $table->json('days_of_week')->nullable(); // e.g. ['Mon', 'Wed', 'Fri']
            $table->integer('available_seats')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_templates');
    }
};
