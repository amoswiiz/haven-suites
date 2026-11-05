<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use RuntimeException;

class MaintenanceReportSchema
{
    public function install(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('maintenance')) {
            throw new RuntimeException('The maintenance table is missing. Install the company operational schema before enabling maintenance reporting.');
        }

        $addReporterId = ! $schema->hasColumn('maintenance', 'reported_by_user_id');
        $addReporterName = ! $schema->hasColumn('maintenance', 'reported_by_name');
        if ($addReporterId || $addReporterName) {
            $schema->table('maintenance', function (Blueprint $table) use ($addReporterId, $addReporterName): void {
                if ($addReporterId) {
                    $table->unsignedBigInteger('reported_by_user_id')->nullable();
                }
                if ($addReporterName) {
                    $table->string('reported_by_name', 255)->nullable();
                }
            });
        }
    }
}
