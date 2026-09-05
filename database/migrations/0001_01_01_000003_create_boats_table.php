<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('owner')->nullable();
            $table->string('operator')->nullable();
            $table->string('boat_number')->nullable();
            $table->string('license_number')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('passenger_capacity')->nullable();
            $table->unsignedInteger('vehicle_capacity')->nullable();
            $table->string('status')->default('Available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boats');
    }
};
