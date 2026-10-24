<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('modules')->nullable();
        });
        Schema::create('sales_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('commission_terms')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('sales_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('phone_normalized', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 30);
            $table->string('channel', 30);
            $table->string('campaign')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            $table->unsignedInteger('guests')->default(1);
            $table->string('location')->nullable();
            $table->string('unit_preference')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->string('stage', 30)->default('new');
            $table->string('next_action');
            $table->timestamp('follow_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->decimal('required_deposit', 14, 2)->nullable();
            $table->text('payment_arrangement')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'stage', 'follow_up_at']);
            $table->index(['tenant_id', 'phone_normalized']);
            $table->unique(['tenant_id', 'reservation_id']);
        });
        Schema::create('sales_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('sales_leads')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('type');
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('sales_leads');
            $table->decimal('amount', 14, 2);
            $table->date('valid_until');
            $table->text('description');
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('messaging_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('external_account_id')->nullable();
            $table->string('status')->default('disconnected');
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel']);
        });
        Schema::create('messaging_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('sales_leads');
            $table->string('channel', 30);
            $table->string('external_id');
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'external_id']);
        });
        Schema::create('sales_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('sales_leads');
            $table->string('channel', 30);
            $table->string('direction', 20);
            $table->text('body');
            $table->string('external_message_id')->nullable();
            $table->string('delivery_status')->default('manual');
            $table->timestamp('received_at');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'external_message_id']);
        });
    }

    public function down(): void
    {
        foreach (['sales_messages', 'messaging_identities', 'messaging_connections', 'sales_quotes', 'sales_activities', 'sales_leads', 'sales_agents'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('modules');
        });
    }
};
