<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->json('youtube_videos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn('youtube_videos');
        });
    }
};
