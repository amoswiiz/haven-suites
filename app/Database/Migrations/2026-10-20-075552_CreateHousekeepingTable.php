<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateHousekeepingTable extends Migration
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
            'reservation_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'assigned_staff_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'verified_by_staff_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'cleaning_type' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
            ],
            'scheduled_at' => [
                'type' => 'DATETIME',
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
            'completed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'verified_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addKey('unit_id');
        $this->forge->addKey('reservation_id');
        $this->forge->addKey('assigned_staff_id');

        $this->forge->addForeignKey(
            'unit_id', 'units', 'id',
            'RESTRICT', 'RESTRICT'
        );
        $this->forge->addForeignKey(
            'reservation_id', 'reservations', 'id',
            'RESTRICT', 'RESTRICT'
        );
        $this->forge->addForeignKey(
            'assigned_staff_id', 'staff', 'id',
            'RESTRICT', 'RESTRICT'
        );
        $this->forge->addForeignKey(
            'verified_by_staff_id', 'staff', 'id',
            'RESTRICT', 'RESTRICT'
        );

        $this->forge->createTable('housekeeping', true);
    }

    public function down()
    {
        $this->forge->dropTable('housekeeping', true);
    }
}
