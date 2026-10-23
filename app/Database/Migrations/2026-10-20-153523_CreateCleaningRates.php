<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCleaningRates extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'bedrooms' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => '0.00',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('bedrooms');

        $this->forge->createTable('cleaning_rates', true);

        $now = date('Y-m-d H:i:s');

        $this->db->table('cleaning_rates')->insertBatch([
            [
                'bedrooms' => 1,
                'amount' => 500.00,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'bedrooms' => 2,
                'amount' => 700.00,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'bedrooms' => 3,
                'amount' => 900.00,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('cleaning_rates', true);
    }
}
