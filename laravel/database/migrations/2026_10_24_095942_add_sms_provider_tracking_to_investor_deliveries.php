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
        Schema::table('investor_deliveries', function (Blueprint $table) {
            $table->string('sms_provider', 40)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investor_deliveries', function (Blueprint $table) {
            $table->dropColumn(['sms_provider', 'provider_status', 'status_checked_at', 'delivered_at']);
        });
    }
};
