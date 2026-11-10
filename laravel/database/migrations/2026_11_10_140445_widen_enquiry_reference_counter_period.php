<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Counters now run per day (YYMMDD) rather than per month (YYMM). */
    public function up(): void
    {
        Schema::table('enquiry_reference_counters', function (Blueprint $table): void {
            $table->string('period', 8)->change();
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_reference_counters', function (Blueprint $table): void {
            $table->char('period', 4)->change();
        });
    }
};
