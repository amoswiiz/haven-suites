<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddHousekeepingBillingFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('housekeeping', [
            'bedrooms_cleaned' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'cleaning_type',
            ],
            'cleaning_charge' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => true,
                'after' => 'bedrooms_cleaned',
            ],
            'charge_adjusted_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'cleaning_charge',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('housekeeping', [
            'bedrooms_cleaned',
            'cleaning_charge',
            'charge_adjusted_by',
        ]);
    }
}
