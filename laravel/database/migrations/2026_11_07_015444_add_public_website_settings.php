<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('whatsapp_number', 16)->nullable();
            $table->string('whatsapp_message', 1000)->nullable();
            $table->json('navigation_links')->nullable();
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('whatsapp_number', 16)->nullable();
            $table->string('whatsapp_message', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn(['seo_title', 'seo_description', 'whatsapp_number', 'whatsapp_message']);
        });
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['seo_title', 'seo_description', 'whatsapp_number', 'whatsapp_message', 'navigation_links']);
        });
    }
};
