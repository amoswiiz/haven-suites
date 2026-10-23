<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRentExpenseCategory extends Migration
{
    public function up()
    {
        $db = db_connect();
        $existing = $db->table('expense_categories')->where('name', 'Rent')->countAllResults();
        if ($existing === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('expense_categories')->insert([
                'name' => 'Rent', 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        // Preserve financial category history: do not delete Rent on rollback.
    }
}
