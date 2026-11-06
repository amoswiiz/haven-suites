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
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->uuid('capture_token')->nullable();
            $table->unique(['tenant_id', 'capture_token']);
        });
        Schema::table('manual_sms_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('enquiry_id')->nullable();
            $table->unique(['tenant_id', 'enquiry_id', 'communication_stage'], 'sms_enquiry_ack_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_sms_messages', function (Blueprint $table): void {
            $table->dropUnique('sms_enquiry_ack_unique');
            $table->dropColumn('enquiry_id');
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'capture_token']);
            $table->dropColumn('capture_token');
        });
    }
};
