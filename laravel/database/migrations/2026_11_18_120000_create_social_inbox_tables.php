<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One inbox for social comments and direct messages. Channel-agnostic, so Facebook, Instagram and later WhatsApp
 * (with coexistence: replies sent from the phone app arrive as echoes) share the same conversations and messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('external_id', 64);
            $table->string('page_id', 64)->nullable();
            $table->string('name', 150);
            $table->string('username', 150)->nullable();
            $table->text('access_token')->nullable();
            $table->string('status', 20)->default('connected');
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'external_id']);
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('social_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('external_id', 191);
            $table->string('participant_id', 64)->nullable();
            $table->string('participant_name', 150)->nullable();
            $table->string('participant_username', 150)->nullable();
            $table->string('context_id', 191)->nullable();
            $table->string('context_title', 255)->nullable();
            $table->string('context_url', 500)->nullable();
            $table->string('status', 20)->default('needs_reply');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->boolean('unread')->default(true);
            $table->string('last_preview', 255)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedBigInteger('sales_lead_id')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'kind', 'external_id']);
            $table->index(['tenant_id', 'status', 'last_message_at']);
        });
        Schema::create('social_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_thread_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191)->nullable();
            $table->string('direction', 3);
            $table->string('origin', 20);
            $table->string('author_name', 150)->nullable();
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('status', 20)->default('received');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['social_account_id', 'external_id']);
            $table->index(['social_thread_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_threads');
        Schema::dropIfExists('social_accounts');
    }
};
