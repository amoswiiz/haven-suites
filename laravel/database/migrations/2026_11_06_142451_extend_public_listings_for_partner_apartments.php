<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->unsignedInteger('unit_id')->nullable()->change();
            $table->unsignedInteger('partner_apartment_id')->nullable();
            $table->unsignedSmallInteger('max_guests')->nullable()->change();
            $table->unsignedSmallInteger('min_nights')->nullable()->default(null)->change();
            $table->unique(['tenant_id', 'partner_apartment_id'], 'public_listings_tenant_partner_unique');
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->unsignedInteger('public_partner_apartment_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('public_listings')->whereNull('unit_id')->orWhereNull('max_guests')->orWhereNull('min_nights')->exists()
            || DB::table('sales_leads')->whereNotNull('public_partner_apartment_id')->exists()) {
            throw new RuntimeException('Remove or complete partner listing drafts and preserve partner enquiry sources before rolling back this migration.');
        }
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn('public_partner_apartment_id');
        });
        Schema::table('public_listings', function (Blueprint $table): void {
            $table->dropUnique('public_listings_tenant_partner_unique');
            $table->dropColumn('partner_apartment_id');
            $table->unsignedInteger('unit_id')->nullable(false)->change();
            $table->unsignedSmallInteger('max_guests')->nullable(false)->change();
            $table->unsignedSmallInteger('min_nights')->nullable(false)->default(1)->change();
        });
    }
};
