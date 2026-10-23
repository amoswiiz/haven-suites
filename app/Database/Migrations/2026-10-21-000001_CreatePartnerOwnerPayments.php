<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePartnerOwnerPayments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'partner_apartment_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'reservation_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'payment_date' => ['type' => 'DATE'],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 30],
            'payment_reference' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'recorded_by' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('partner_apartment_id');
        $this->forge->addKey('reservation_id');
        $this->forge->addKey('payment_date');
        $this->forge->createTable('partner_owner_payments', true);
    }

    public function down()
    {
        $this->forge->dropTable('partner_owner_payments', true);
    }
}
