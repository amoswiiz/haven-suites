<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExpensePayments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'expense_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'payment_date' => [
                'type' => 'DATE',
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
            ],
            'reference' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'recorded_by' => [
                'type' => 'INT',
                'unsigned' => true,
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
        $this->forge->addKey('expense_id');
        $this->forge->addKey('payment_date');

        $this->forge->addForeignKey(
            'expense_id',
            'unit_expenses',
            'id',
            'RESTRICT',
            'RESTRICT'
        );

        $this->forge->createTable('expense_payments');

        // Preserve payments already recorded when an
        // expense was initially created.
        $this->db->query("
            INSERT INTO expense_payments
                (
                    expense_id,
                    payment_date,
                    amount,
                    payment_method,
                    reference,
                    notes,
                    recorded_by,
                    created_at
                )
            SELECT
                id,
                COALESCE(paid_at, expense_date),
                amount_paid,
                COALESCE(
                    NULLIF(payment_method, ''),
                    'legacy'
                ),
                payment_reference,
                'Opening payment imported from unit expense',
                recorded_by,
                NOW()
            FROM unit_expenses
            WHERE amount_paid > 0
        ");
    }

    public function down()
    {
        $this->forge->dropTable('expense_payments', true);
    }
}
