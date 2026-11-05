<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

class StaffBillSchema
{
    public function install(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('staff_bills')) {
            $schema->create('staff_bills', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('submitted_by');
                $t->unsignedInteger('staff_id');
                $t->unsignedInteger('unit_id');
                $t->uuid('submission_token')->unique();
                $t->date('purchase_date');
                $t->string('category', 50);
                $t->string('description', 255);
                $t->string('supplier', 150);
                $t->unsignedBigInteger('amount_minor');
                $t->string('payer', 20);
                $t->string('receipt_path');
                $t->string('status', 20)->default('pending');
                $t->unsignedInteger('expense_id')->nullable()->unique();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['submitted_by', 'status']);
            });
        }
        if (! $schema->hasTable('staff_bill_events')) {
            $schema->create('staff_bill_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('bill_id')->index();
                $t->unsignedBigInteger('actor_id');
                $t->string('action', 30);
                $t->text('note')->nullable();
                $t->text('snapshot');
                $t->timestamp('created_at');
            });
        }
    }
}
