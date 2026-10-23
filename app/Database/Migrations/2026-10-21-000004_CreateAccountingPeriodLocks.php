<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAccountingPeriodLocks extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'unit_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'period_month' => ['type' => 'DATE'],
            'is_locked' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'locked_by' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'locked_at' => ['type' => 'DATETIME', 'null' => true],
            'reopened_by' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'reopened_at' => ['type' => 'DATETIME', 'null' => true],
            'reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['unit_id', 'period_month'], 'unit_month_unique');
        $this->forge->createTable('accounting_period_locks', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'unit_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'period_month' => ['type' => 'DATE'],
            'action' => ['type' => 'VARCHAR', 'constraint' => 20],
            'actor_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'reason' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['unit_id', 'period_month']);
        $this->forge->createTable('accounting_period_lock_events', true);
    }

    public function down()
    {
        $this->forge->dropTable('accounting_period_lock_events', true);
        $this->forge->dropTable('accounting_period_locks', true);
    }
}
