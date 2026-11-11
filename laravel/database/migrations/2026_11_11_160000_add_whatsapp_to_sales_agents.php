<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Referral agents can have a separate WhatsApp number; when empty their phone is used. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agents', function (Blueprint $table): void {
            $table->string('whatsapp', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_agents', function (Blueprint $table): void {
            $table->dropColumn('whatsapp');
        });
    }
};
