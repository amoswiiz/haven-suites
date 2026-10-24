<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_operating_arrangements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('unit_id');
            $t->string('mode', 20);
            $t->unsignedInteger('commission_basis_points')->default(0);
            $t->bigInteger('monthly_rent_minor')->default(0);
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->string('landlord_name')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['tenant_id', 'unit_id', 'starts_on']);
        });
        Schema::create('unit_rent_charges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('unit_id');
            $t->foreignId('arrangement_id')->constrained('unit_operating_arrangements');
            $t->date('period_month');
            $t->bigInteger('amount_minor');
            $t->unsignedBigInteger('expense_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'unit_id', 'period_month']);
        });
        Schema::create('unit_accounting_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('unit_id');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->text('details');
            $t->timestamps();
        });
        Schema::table('investor_payouts', function (Blueprint $t): void {
            $t->unsignedBigInteger('remittance_id')->nullable()->change();
            $t->unsignedBigInteger('rent_payment_id')->nullable();
            $t->unique(['tenant_id', 'investor_id', 'rent_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('investor_payouts', function (Blueprint $t): void {
            $t->dropUnique(['tenant_id', 'investor_id', 'rent_payment_id']);
            $t->dropColumn('rent_payment_id');
        });
        Schema::dropIfExists('unit_accounting_events');
        Schema::dropIfExists('unit_rent_charges');
        Schema::dropIfExists('unit_operating_arrangements');
    }
};
