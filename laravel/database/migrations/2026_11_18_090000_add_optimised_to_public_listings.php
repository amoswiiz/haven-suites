<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff mark a listing as optimised once it has been renamed, checked and completed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->timestamp('optimised_at')->nullable();
            $table->unsignedBigInteger('optimised_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropColumn(['optimised_at', 'optimised_by']);
        });
    }
};
