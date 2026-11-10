<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
            $table->timestamp('logo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_sites', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'logo_updated_at']);
        });
    }
};
