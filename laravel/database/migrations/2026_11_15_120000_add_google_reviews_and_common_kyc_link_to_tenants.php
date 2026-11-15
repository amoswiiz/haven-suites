<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The company's Google review link, and one shared KYC registration link that any guest can use. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('google_review_url', 500)->nullable();
            $table->string('kyc_join_code', 20)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['kyc_join_code']);
            $table->dropColumn(['google_review_url', 'kyc_join_code']);
        });
    }
};
