<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer listing pages: approximate map location and nearby places, rooms with their beds, property facts,
 * weekend and seasonal prices, a 360° tour, a "photos checked" date, a private check-in guide and photo room tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->json('nearby')->nullable();
            $table->json('rooms')->nullable();
            $table->unsignedSmallInteger('size_sqm')->nullable();
            $table->string('floor_level', 30)->nullable();
            $table->unsignedTinyInteger('parking_spaces')->nullable();
            $table->string('virtual_tour_url', 500)->nullable();
            $table->date('photos_verified_at')->nullable();
            $table->decimal('weekend_price', 12, 2)->nullable();
            $table->json('seasonal_rates')->nullable();
            $table->text('check_in_guide')->nullable();
        });
        Schema::table('public_listing_photos', function (Blueprint $table): void {
            $table->string('room', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_listing_photos', function (Blueprint $table): void {
            $table->dropColumn('room');
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'nearby', 'rooms', 'size_sqm', 'floor_level', 'parking_spaces',
                'virtual_tour_url', 'photos_verified_at', 'weekend_price', 'seasonal_rates', 'check_in_guide']);
        });
    }
};
