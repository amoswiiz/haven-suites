<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOwnerRemittances extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type'=>'INT','constraint'=>10,'unsigned'=>true,'auto_increment'=>true],
            'unit_id' => ['type'=>'INT','constraint'=>10,'unsigned'=>true],
            'period_month' => ['type'=>'DATE'],
            'payment_date' => ['type'=>'DATE'],
            'amount' => ['type'=>'DECIMAL','constraint'=>'12,2'],
            'payment_method' => ['type'=>'VARCHAR','constraint'=>30],
            'reference' => ['type'=>'VARCHAR','constraint'=>100,'null'=>true],
            'notes' => ['type'=>'TEXT','null'=>true],
            'recorded_by' => ['type'=>'INT','constraint'=>10,'unsigned'=>true,'null'=>true],
            'created_at' => ['type'=>'DATETIME','null'=>true],
            'updated_at' => ['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['unit_id','period_month']);
        $this->forge->createTable('owner_remittances', true);
    }
    public function down()
    {
        $this->forge->dropTable('owner_remittances', true);
    }
}
