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
        Schema::create('independent_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number')->nullable()->unique();
            $table->string('customer_name', 180);
            $table->date('issue_date');
            $table->date('valid_until');
            $table->string('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->json('snapshot');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('independent_quotations');
    }
};
