<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest trust features for the public website: price estimate inputs per listing, editable policies, FAQ and
     * host introduction per site, and guest reviews collected by private link or copied from other platforms.
     */
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->decimal('cleaning_fee', 12, 2)->nullable();
            $table->unsignedTinyInteger('weekly_discount_percent')->nullable();
            $table->unsignedTinyInteger('monthly_discount_percent')->nullable();
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->text('cancellation_policy')->nullable();
            $table->text('payment_policy')->nullable();
            $table->text('faq')->nullable();
            $table->text('host_intro')->nullable();
            $table->string('host_reply_time', 80)->nullable();
            $table->string('host_languages', 120)->nullable();
        });
        Schema::create('public_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('public_listings')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('source', 20)->default('direct');
            $table->string('status', 20)->default('requested');
            $table->string('guest_name', 100);
            $table->string('guest_phone', 20)->nullable();
            $table->date('stayed_on')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->string('sms_status', 20)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'submitted_at']);
            $table->index(['tenant_id', 'listing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_reviews');
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['cancellation_policy', 'payment_policy', 'faq', 'host_intro', 'host_reply_time', 'host_languages']);
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn(['cleaning_fee', 'weekly_discount_percent', 'monthly_discount_percent']);
        });
    }
};
