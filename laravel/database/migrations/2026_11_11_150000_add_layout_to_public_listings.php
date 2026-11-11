<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listings can state their own layout (studio to large villas), bathrooms and beds.
 * Empty values fall back to the linked apartment record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('beds')->nullable();
            $table->string('bed_setup', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn(['bedrooms', 'bathrooms', 'beds', 'bed_setup']);
        });
    }
};
