<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

class BookingAmendmentSchema
{
    public function install(Connection $db): void
    {
        if (! $db->getSchemaBuilder()->hasTable('booking_amendments')) {
            $db->getSchemaBuilder()->create('booking_amendments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('reservation_id')->index();
                $table->unsignedInteger('invoice_id')->nullable()->index();
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
