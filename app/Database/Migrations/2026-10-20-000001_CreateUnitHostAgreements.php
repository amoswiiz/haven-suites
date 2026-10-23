<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUnitHostAgreements extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'unit_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'host_name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'host_phone' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'commission_rate' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'effective_from' => ['type' => 'DATE'],
            'effective_to' => ['type' => 'DATE', 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['unit_id', 'effective_from'], false, true);
        $this->forge->addKey(['unit_id', 'effective_to']);
        $this->forge->createTable('unit_host_agreements', true);
    }

    public function down()
    {
        $this->forge->dropTable('unit_host_agreements', true);
    }
}
