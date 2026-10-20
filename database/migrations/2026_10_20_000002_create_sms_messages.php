<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('sms_messages', function(Blueprint $t) { $t->id(); $t->string('deduplication_key')->unique(); $t->string('to'); $t->text('body'); $t->string('status')->default('pending'); $t->string('provider_id')->nullable(); $t->string('error_message')->nullable(); $t->timestamps(); }); }
 public function down(): void { Schema::dropIfExists('sms_messages'); }
};
