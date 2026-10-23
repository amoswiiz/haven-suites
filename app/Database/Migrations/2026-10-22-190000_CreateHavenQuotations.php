<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class CreateHavenQuotations extends Migration {
 public function up() {
  $this->forge->addField(['id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true], 'reservation_id'=>['type'=>'BIGINT','unsigned'=>true], 'token_hash'=>['type'=>'CHAR','constraint'=>64], 'snapshot_json'=>['type'=>'LONGTEXT'], 'created_by'=>['type'=>'BIGINT','unsigned'=>true], 'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'issued'], 'expires_at'=>['type'=>'DATETIME'], 'created_at'=>['type'=>'DATETIME']]);
  $this->forge->addKey('id',true); $this->forge->addUniqueKey('token_hash'); $this->forge->addKey('reservation_id'); $this->forge->createTable('haven_quotations',true);
  $this->forge->addField(['id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true], 'quotation_id'=>['type'=>'BIGINT','unsigned'=>true], 'amount'=>['type'=>'DECIMAL','constraint'=>'12,2'], 'mpesa_code'=>['type'=>'VARCHAR','constraint'=>30], 'mpesa_message'=>['type'=>'TEXT'], 'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'pending'], 'submitted_at'=>['type'=>'DATETIME'], 'reviewed_by'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true], 'reviewed_at'=>['type'=>'DATETIME','null'=>true]]);
  $this->forge->addKey('id',true); $this->forge->addUniqueKey('mpesa_code'); $this->forge->addKey('quotation_id'); $this->forge->createTable('haven_quote_payment_claims',true);
 }
 public function down() {$this->forge->dropTable('haven_quote_payment_claims',true);$this->forge->dropTable('haven_quotations',true);}
}
