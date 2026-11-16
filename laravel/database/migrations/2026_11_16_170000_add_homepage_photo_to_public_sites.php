<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The photo chosen for the top of the homepage, instead of the first photo of the first listing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->string('hero_photo_path')->nullable();
            $table->timestamp('hero_photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['hero_photo_path', 'hero_photo_updated_at']);
        });
    }
};
