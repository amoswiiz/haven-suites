<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Why choose us" points and the team shown on the About page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->text('about_why')->nullable();
            $table->json('about_team')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['about_why', 'about_team']);
        });
    }
};
