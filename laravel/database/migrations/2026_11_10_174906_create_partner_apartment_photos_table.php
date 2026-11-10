<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One featured photo per partner apartment, so owners recognise the unit in availability requests. */
    public function up(): void
    {
        Schema::create('partner_apartment_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_apartment_id');
            $table->string('storage_key');
            $table->timestamps();
            $table->unique(['tenant_id', 'partner_apartment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_apartment_photos');
    }
};
