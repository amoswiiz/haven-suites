<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The small square icon browsers show in tabs and phones use for home-screen shortcuts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->string('favicon_path')->nullable();
            $table->timestamp('favicon_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['favicon_path', 'favicon_updated_at']);
        });
    }
};
