<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiry_tracking_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique()->constrained('sales_leads')->cascadeOnDelete();
            $table->string('reference', 20);
            $table->char('code_hash', 64)->unique();
            $table->text('code_encrypted');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_tracking_links');
    }
};
