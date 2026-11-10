<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An optional outside listing page (e.g. WordPress, Airbnb) for a partner apartment without a published app listing. */
    public function up(): void
    {
        Schema::create('partner_apartment_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_apartment_id');
            $table->string('listing_url', 500);
            $table->timestamps();
            $table->unique(['tenant_id', 'partner_apartment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_apartment_links');
    }
};
