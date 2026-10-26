<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('sales_leads')->where('stage', 'quotation_sent')->get() as $lead) {
                DB::table('sales_leads')->where('id', $lead->id)->update(['stage' => 'options_shared', 'version' => $lead->version + 1, 'updated_at' => now()]);
                DB::table('sales_activities')->insert(['tenant_id' => $lead->tenant_id, 'lead_id' => $lead->id, 'actor_id' => $lead->assigned_to, 'type' => 'workflow_migration', 'body' => 'Booking quotation stage retired. Moved to Options shared; existing quotations retained. No invoice or payment was created.', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::table('sales_leads')->where('stage', 'invoice_issued')->update(['stage' => 'options_shared', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
};
