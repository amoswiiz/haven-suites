<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateUnitOpeningBalancesAndClosings extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true],
            'unit_id'=>['type'=>'INT','unsigned'=>true],
            'effective_month'=>['type'=>'DATE'],
            'amount'=>['type'=>'DECIMAL','constraint'=>'12,2'],
            'notes'=>['type'=>'TEXT','null'=>true],
            'recorded_by'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'created_at'=>['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey('unit_id');
        $this->forge->createTable('unit_opening_balances',true);
        $this->forge->addField([
            'id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true],
            'unit_id'=>['type'=>'INT','unsigned'=>true],
            'period_month'=>['type'=>'DATE'],
            'statement_json'=>['type'=>'LONGTEXT'],
            'statement_hash'=>['type'=>'VARCHAR','constraint'=>64],
            'approved_by'=>['type'=>'INT','unsigned'=>true],
            'approved_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey(['unit_id','period_month']);
        $this->forge->createTable('owner_statement_closings',true);
    }
    public function down()
    {
        $this->forge->dropTable('owner_statement_closings',true);
        $this->forge->dropTable('unit_opening_balances',true);
    }
}
