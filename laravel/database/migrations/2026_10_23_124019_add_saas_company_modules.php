<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('requires_email_verification')->default(false);
            $table->boolean('is_platform_admin')->default(false);
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('currency', 3)->default('KES');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('plan')->default('legacy');
            $table->string('subscription_status')->default('legacy');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->string('requested_plan')->nullable();
        });
        Schema::create('company_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'email']);
        });
        Schema::create('platform_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');
        Schema::dropIfExists('company_invitations');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['contact_email', 'contact_phone', 'timezone', 'currency', 'onboarding_completed_at', 'plan', 'subscription_status', 'trial_ends_at', 'subscription_ends_at', 'requested_plan']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['requires_email_verification', 'is_platform_admin']));
    }
};
