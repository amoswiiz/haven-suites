<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCleanerPayments extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'staff_id' => [
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
        $this->forge->addKey('staff_id');
        $this->forge->addKey('payment_date');

        $this->forge->createTable('cleaner_payments', true);

        // Allocations link each payment to the tasks it settles.
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'payment_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'housekeeping_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'amount' => [
                'type' => 'DECIMAL',
                'constraint' => '12,2',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('payment_id');
        $this->forge->addKey('housekeeping_id');
        $this->forge->addUniqueKey(
            ['payment_id', 'housekeeping_id'],
            'unique_payment_task'
        );

        $this->forge->createTable(
            'cleaner_payment_allocations',
            true
        );
    }

    public function down()
    {
        $this->forge->dropTable(
            'cleaner_payment_allocations',
            true
        );

        $this->forge->dropTable(
            'cleaner_payments',
            true
        );
    }
}
