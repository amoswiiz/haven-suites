<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOwnerStatementRevisionHistory extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type'=>'INT','constraint'=>10,'unsigned'=>true,'auto_increment'=>true],
            'unit_id' => ['type'=>'INT','constraint'=>10,'unsigned'=>true],
            'period_month' => ['type'=>'DATE'],
            'statement_json' => ['type'=>'LONGTEXT'],
            'statement_hash' => ['type'=>'VARCHAR','constraint'=>64],
            'approved_by' => ['type'=>'INT','constraint'=>10,'unsigned'=>true],
            'approved_at' => ['type'=>'DATETIME'],
            'reopened_by' => ['type'=>'INT','constraint'=>10,'unsigned'=>true],
            'reopened_at' => ['type'=>'DATETIME'],
            'reopen_reason' => ['type'=>'TEXT'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['unit_id','period_month']);
        $this->forge->createTable('owner_statement_revision_history', true);
    }

    public function down()
    {
        $this->forge->dropTable('owner_statement_revision_history', true);
    }
}
