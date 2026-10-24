<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('name');
            $t->string('email');
            $t->string('phone', 30)->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('email_notifications')->default(true);
            $t->boolean('sms_notifications')->default(false);
            $t->timestamp('sms_consented_at')->nullable();
            $t->timestamp('phone_verified_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'email']);
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::table('company_invitations', function (Blueprint $t): void {
            $t->foreignId('investor_id')->nullable()->constrained('investors');
        });
        Schema::create('investor_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investor_id')->constrained('investors');
            $t->unsignedBigInteger('unit_id');
            $t->unsignedInteger('share_basis_points');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('agreement_reference');
            $t->timestamps();
            $t->index(['tenant_id', 'investor_id', 'unit_id']);
        });
        Schema::create('investor_statements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investor_id')->constrained('investors');
            $t->foreignId('assignment_id')->constrained('investor_assignments');
            $t->unsignedBigInteger('unit_id');
            $t->date('period_month');
            $t->unsignedInteger('revision');
            $t->json('snapshot');
            $t->string('source_hash', 64);
            $t->string('correction_reason')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->foreignId('published_by')->constrained('users');
            $t->timestamp('published_at');
            $t->timestamps();
            $t->unique(['assignment_id', 'period_month', 'revision']);
        });
        Schema::create('investor_payouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investor_id')->constrained('investors');
            $t->foreignId('assignment_id')->constrained('investor_assignments');
            $t->unsignedBigInteger('remittance_id');
            $t->string('property_label');
            $t->date('payment_date');
            $t->bigInteger('amount_minor');
            $t->string('reference')->nullable();
            $t->string('currency', 3);
            $t->foreignId('published_by')->constrained('users');
            $t->timestamps();
            $t->unique(['tenant_id', 'investor_id', 'remittance_id']);
        });
        Schema::create('investor_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investor_id')->constrained('investors');
            $t->string('event_key')->unique();
            $t->string('type');
            $t->string('title');
            $t->text('body');
            $t->string('portal_url');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('investor_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->constrained('investor_events');
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 10);
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->string('provider_reference')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'channel']);
        });
        Schema::create('investor_audit_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('investor_id')->constrained('investors');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->text('details');
            $t->timestamps();
        });
        Schema::create('investor_notification_settings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('email_enabled')->default(true);
            $t->boolean('sms_enabled')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['investor_notification_settings', 'investor_audit_events', 'investor_deliveries', 'investor_events', 'investor_payouts', 'investor_statements', 'investor_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('company_invitations', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('investor_id');
        });
        Schema::dropIfExists('investors');
    }
};
