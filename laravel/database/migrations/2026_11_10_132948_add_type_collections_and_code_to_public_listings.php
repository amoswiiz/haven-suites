<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->string('property_type', 20)->nullable();
            $table->json('collections')->nullable();
            $table->string('listing_code', 20)->nullable();
            $table->unique(['tenant_id', 'listing_code']);
        });
    }

    public function down(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'listing_code']);
            $table->dropColumn(['property_type', 'collections', 'listing_code']);
        });
    }
};
