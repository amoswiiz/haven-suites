<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('provider', 30);
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('username');
            $table->text('password_encrypted');
            $table->string('from_address');
            $table->string('from_name', 150);
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_mail_settings');
    }
};
