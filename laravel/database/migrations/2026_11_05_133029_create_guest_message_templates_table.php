<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_message_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('unit_id')->nullable();
            $t->string('name', 120);
            $t->string('purpose', 30);
            $t->string('channel', 20)->default('sms');
            $t->text('body');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('updated_by')->constrained('users');
            $t->timestamps();
            $t->index(['tenant_id', 'channel', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_message_templates');
    }
};
