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
        Schema::create('travel_advisories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type');
            $table->string('route')->default('All Routes');
            $table->string('effective_from')->nullable();
            $table->string('until')->nullable();
            $table->string('severity')->default('Information');
            $table->string('status')->default('Published');
            $table->string('push_status')->default('Pending');
            $table->text('message');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_advisories');
    }
};
