<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void { Schema::table('bookings', function(Blueprint $t) { $t->unique('paymongo_payment_id','bookings_unique_payment'); $t->unique('paymongo_checkout_session_id','bookings_unique_checkout'); }); }
 public function down():void { Schema::table('bookings', function(Blueprint $t) { $t->dropUnique('bookings_unique_payment'); $t->dropUnique('bookings_unique_checkout'); }); }
};
