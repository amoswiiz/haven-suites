<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

class ExpenseCorrectionSchema
{
    public function install(Connection $db): void
    {
        if (! $db->getSchemaBuilder()->hasTable('expense_corrections')) {
            $db->getSchemaBuilder()->create('expense_corrections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('expense_id')->index();
                $table->unsignedBigInteger('actor_id');
                $table->string('actor_name');
                $table->text('reason');
                $table->text('before_snapshot');
                $table->text('after_snapshot');
                $table->timestamp('created_at');
            });
        }
    }
}
