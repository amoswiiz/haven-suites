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
        Schema::table('manual_sms_messages', function (Blueprint $table) {
            $table->unsignedInteger('reservation_id')->nullable();
            $table->string('communication_stage', 40)->nullable();
            $table->index(['tenant_id', 'reservation_id'], 'manual_sms_booking_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_sms_messages', function (Blueprint $table) {
            $table->dropIndex('manual_sms_booking_index');
            $table->dropColumn(['reservation_id', 'communication_stage']);
        });
    }
};
