<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) { $table->string('username')->unique(); $table->boolean('active')->default(true); });
        Schema::create('tenants', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique();
            $table->string('database_name')->unique(); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('roles'); $table->unsignedBigInteger('legacy_user_id'); $table->primary(['tenant_id','user_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('tenant_user'); Schema::dropIfExists('tenants'); Schema::table('users', fn(Blueprint $table) => $table->dropColumn(['username','active'])); }
};
