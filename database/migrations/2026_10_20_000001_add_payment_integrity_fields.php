<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('bookings', function(Blueprint $table) {
   $table->timestamp('payment_received_at')->nullable();
   $table->timestamp('reservation_expires_at')->nullable()->index();
   $table->boolean('payment_review_required')->default(false);
  });
 }
 public function down(): void { Schema::table('bookings', fn(Blueprint $table) => $table->dropColumn(['payment_received_at','reservation_expires_at','payment_review_required'])); }
};
