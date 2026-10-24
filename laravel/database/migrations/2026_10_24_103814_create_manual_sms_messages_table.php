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
        Schema::create('manual_sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->constrained('users');
            $table->uuid('send_token');
            $table->string('phone', 20);
            $table->text('body');
            $table->string('sender_id');
            $table->string('channel', 10)->default('sms');
            $table->string('sms_provider', 40)->default('roycetalk');
            $table->string('status', 30)->default('processing');
            $table->string('provider_reference')->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'send_token']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_sms_messages');
    }
};
