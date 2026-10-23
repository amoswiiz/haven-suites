<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUnitExpenses extends Migration
{
    public function up()
    {
        // Expense categories.
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
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
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('expense_categories', true);

        // Unit operating expenses.
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
            'category_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'expense_date' => [
                'type' => 'DATE',
            ],
            'description' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
            ],
            'payment_status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'unpaid',
            ],
            'amount_paid' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
                'default' => '0.00',
            ],
            'paid_at' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'payment_reference' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ],
            'supplier_name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'source_type' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'manual',
            ],
            'source_id' => [
                'type' => 'INT',
                'unsigned' => true,
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
        $this->forge->addKey('unit_id');
        $this->forge->addKey('category_id');
        $this->forge->addKey('expense_date');
        $this->forge->addKey(
            ['source_type', 'source_id'],
            false,
            true
        );

        $this->forge->createTable('unit_expenses', true);

        // Default expense categories.
        $this->db->table('expense_categories')->insertBatch([
            ['name' => 'Cleaning'],
            ['name' => 'Maintenance'],
            ['name' => 'Refills & Consumables'],
            ['name' => 'Electricity'],
            ['name' => 'Water'],
            ['name' => 'Internet'],
            ['name' => 'Laundry'],
            ['name' => 'Supplies & Replacements'],
            ['name' => 'Other'],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('unit_expenses', true);
        $this->forge->dropTable('expense_categories', true);
    }
}
