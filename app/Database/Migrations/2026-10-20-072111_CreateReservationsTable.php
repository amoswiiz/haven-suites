<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReservationsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'unit_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'guest_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],
            'guest_phone' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
            ],
            'guest_email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'check_in' => [
                'type' => 'DATE',
            ],
            'check_out' => [
                'type' => 'DATE',
            ],
            'adults' => [
                'type' => 'INT',
                'default' => 1,
            ],
            'children' => [
                'type' => 'INT',
                'default' => 0,
            ],
            'booking_source' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'default' => 'Direct',
            ],
            'nightly_rate' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
            ],
            'total_amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
            ],
            'amount_paid' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'pending',
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addKey(['unit_id', 'check_in', 'check_out']);

        $this->forge->addForeignKey(
            'unit_id',
            'units',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->forge->createTable('reservations', true);
    }

    public function down()
    {
        $this->forge->dropTable('reservations', true);
    }
}
