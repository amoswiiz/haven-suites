<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who each blog post is written for (guests or property owners), and the Google rating shown on the website. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_posts', function (Blueprint $table): void {
            $table->string('audience', 20)->default('guests');
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->decimal('google_rating', 2, 1)->nullable();
            $table->unsignedInteger('google_review_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_posts', function (Blueprint $table): void {
            $table->dropColumn('audience');
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['google_rating', 'google_review_count']);
        });
    }
};
