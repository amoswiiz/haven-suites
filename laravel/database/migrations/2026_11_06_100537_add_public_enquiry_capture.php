<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->unsignedBigInteger('public_listing_id')->nullable();
            $table->unsignedBigInteger('public_unit_id')->nullable();
            $table->string('public_origin', 30)->nullable();
            $table->timestamp('public_consented_at')->nullable();
            $table->unsignedBigInteger('public_task_id')->nullable();
            $table->index(['tenant_id', 'public_listing_id']);
        });
        Schema::table('sales_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
        });
        Schema::table('manual_sms_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('sent_by')->nullable()->change();
        });
        Schema::table('task_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        Schema::create('public_enquiry_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('sales_leads')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('reference', 20)->unique();
            $table->timestamps();
            $table->unique(['tenant_id', 'lead_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sales_activities')->whereNull('actor_id')->exists()
            || DB::table('manual_sms_messages')->whereNull('sent_by')->exists()
            || DB::table('task_items')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Website enquiries contain system-attributed records. Preserve those records before reversing public enquiry capture.');
        }
        Schema::dropIfExists('public_enquiry_receipts');
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'public_listing_id']);
            $table->dropColumn(['public_listing_id', 'public_unit_id', 'public_origin', 'public_consented_at', 'public_task_id']);
        });
        Schema::table('sales_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_id')->nullable(false)->change();
        });
        Schema::table('manual_sms_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('sent_by')->nullable(false)->change();
        });
        Schema::table('task_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
