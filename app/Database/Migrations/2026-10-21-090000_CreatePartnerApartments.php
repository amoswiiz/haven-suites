<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePartnerApartments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'owner_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],
            'owner_phone' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'owner_email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'apartment_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],
            'building_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'unit_number' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'location' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
            ],
            'bedrooms' => [
                'type' => 'INT',
                'default' => 1,
            ],
            'bathrooms' => [
                'type' => 'INT',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'active',
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
        $this->forge->addKey('owner_name');
        $this->forge->addKey('location');
        $this->forge->createTable('partner_apartments', true);
    }

    public function down()
    {
        $this->forge->dropTable('partner_apartments', true);
    }
}
