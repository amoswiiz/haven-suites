<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages sent to all referral agents at once (availability, offers).
 * Each recipient row records its own channel so WhatsApp can be added beside SMS later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agents', function (Blueprint $table): void {
            $table->boolean('broadcast_opt_out')->default(false);
        });
        Schema::create('agent_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('general');
            $table->string('channel', 20)->default('sms');
            $table->text('message');
            $table->json('listing_ids')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedSmallInteger('segments')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('agent_broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained('agent_broadcasts')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('sales_agents')->nullOnDelete();
            $table->string('agent_name', 150);
            $table->string('channel', 20)->default('sms');
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('sms_provider', 30)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('provider_status', 30)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_broadcast_recipients');
        Schema::dropIfExists('agent_broadcasts');
        Schema::table('sales_agents', function (Blueprint $table): void {
            $table->dropColumn('broadcast_opt_out');
        });
    }
};
