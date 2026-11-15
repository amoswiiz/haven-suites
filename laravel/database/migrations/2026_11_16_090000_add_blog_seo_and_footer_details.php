<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The phrase each blog post should rank for, and the social profiles and office addresses shown in the website footer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_posts', function (Blueprint $table): void {
            $table->string('focus_keyword', 100)->nullable();
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->json('social_links')->nullable();
            $table->text('office_addresses')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_posts', function (Blueprint $table): void {
            $table->dropColumn('focus_keyword');
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['social_links', 'office_addresses']);
        });
    }
};
