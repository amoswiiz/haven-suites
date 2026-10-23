<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('plan');
            $table->string('provider');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('KES');
            $table->string('mode')->default('sandbox');
            $table->string('status')->default('pending');
            $table->string('gateway_id')->nullable()->unique();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('callback_token_hash', 64)->nullable()->unique();
            $table->text('phone')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
