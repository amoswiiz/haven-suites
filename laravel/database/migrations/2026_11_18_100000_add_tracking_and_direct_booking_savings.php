<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Website tracking IDs, the perks of booking direct, and each listing's nightly price on booking sites. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->string('ga_measurement_id', 20)->nullable();
            $table->string('meta_pixel_id', 20)->nullable();
            $table->text('direct_perks')->nullable();
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->decimal('ota_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['ga_measurement_id', 'meta_pixel_id', 'direct_perks']);
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn('ota_price');
        });
    }
};
