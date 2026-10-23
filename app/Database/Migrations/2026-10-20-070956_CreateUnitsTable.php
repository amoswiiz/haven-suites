<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUnitsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'property_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'unit_number' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'bedrooms' => [
                'type' => 'INT',
                'default' => 1,
            ],
            'bathrooms' => [
                'type' => 'INT',
                'default' => 1,
            ],
            'floor_number' => [
                'type' => 'INT',
                'null' => true,
            ],
            'nightly_rate' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => 0,
            ],
            'owner_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'owner_phone' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'active',
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
        $this->forge->addUniqueKey(
            ['property_id', 'unit_number'],
            'unique_property_unit'
        );
        $this->forge->addForeignKey(
            'property_id',
            'properties',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->forge->createTable('units', true);
    }

    public function down()
    {
        $this->forge->dropTable('units', true);
    }
}
