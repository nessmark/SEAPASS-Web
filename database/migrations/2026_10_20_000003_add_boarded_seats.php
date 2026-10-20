<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('bookings', fn(Blueprint $t) => $t->json('boarded_seats')->nullable()); }
 public function down(): void { Schema::table('bookings', fn(Blueprint $t) => $t->dropColumn('boarded_seats')); }
};
