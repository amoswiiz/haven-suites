<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Editable About us page text. Blank fields fall back to the default wording. */
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->text('about_intro')->nullable();
            $table->text('about_how')->nullable();
            $table->string('about_main_locations', 255)->nullable();
            $table->text('about_other_areas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['about_intro', 'about_how', 'about_main_locations', 'about_other_areas']);
        });
    }
};
