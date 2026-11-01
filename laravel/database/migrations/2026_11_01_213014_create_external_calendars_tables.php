<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('external_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->string('name', 100);
            $table->text('url_encrypted');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'unit_id']);
        });
        Schema::create('external_calendar_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('external_calendars')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('unit_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->index(['tenant_id', 'unit_id', 'start_date', 'end_date'], 'external_blocks_availability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_calendar_blocks');
        Schema::dropIfExists('external_calendars');
    }
};
