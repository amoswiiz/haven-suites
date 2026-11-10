<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_availability_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_apartment_id');
            $table->string('apartment_label', 255);
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            $table->unsignedSmallInteger('guests')->nullable();
            $table->char('code_hash', 64)->unique();
            $table->text('code_encrypted');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('response', 20)->nullable();
            $table->decimal('nightly_price', 12, 2)->nullable();
            $table->string('response_note', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['tenant_id', 'partner_apartment_id', 'created_at'], 'partner_requests_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_availability_requests');
    }
};
