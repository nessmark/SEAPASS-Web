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
        if (!Schema::hasTable('advisories')) {
            Schema::create('advisories', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->longText('content'); // HTML content matching broadcast email layout
                $table->string('severity')->default('info'); // info, warning, critical
                $table->string('type')->nullable(); // e.g. Weather Update, Sea Condition Warning, Port Suspension
                $table->string('affected_route')->nullable()->default('All Routes');
                $table->string('effective_from')->nullable();
                $table->string('until')->nullable();
                $table->boolean('is_published')->default(true);
                $table->timestamp('published_at')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advisories');
    }
};
