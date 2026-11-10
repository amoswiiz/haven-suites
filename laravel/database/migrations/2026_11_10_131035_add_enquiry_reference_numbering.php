<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('reference_prefix', 5)->nullable();
        });
        Schema::create('enquiry_reference_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('period', 4);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });
        // Company prefixes can coincide, so website references are unique per company rather than globally.
        Schema::table('public_enquiry_receipts', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('public_enquiry_receipts', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'reference']);
            $table->unique(['reference']);
        });
        Schema::dropIfExists('enquiry_reference_counters');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('reference_prefix');
        });
    }
};
