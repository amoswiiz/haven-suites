<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('assigned_to')->constrained('users');
            $t->foreignId('reviewer_id')->nullable()->constrained('users');
            $t->date('due_date');
            $t->string('priority', 15)->default('normal');
            $t->string('status', 20)->default('todo');
            $t->boolean('owner_visible')->default(false);
            $t->text('owner_description')->nullable();
            $t->boolean('share_cost')->default(false);
            $t->string('kind', 15)->default('routine');
            $t->unsignedBigInteger('estimate_minor')->nullable();
            $t->string('cost_status', 15)->default('none');
            $t->foreignId('cost_approved_by')->nullable()->constrained('users');
            $t->unsignedBigInteger('expense_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'assigned_to', 'status']);
            $t->index(['tenant_id', 'unit_id']);
        });
        Schema::create('task_updates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->constrained('task_items')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->text('body');
            $t->boolean('shared')->default(false);
            $t->string('path')->nullable();
            $t->string('filename')->nullable();
            $t->timestamps();
        });
        Schema::create('task_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->constrained('task_items')->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('message', 500);
            $t->timestamps();
        });
        Schema::create('task_notices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('task_id')->constrained('task_items')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('event_key', 160);
            $t->string('message', 200);
            $t->boolean('owner')->default(false);
            $t->timestamp('read_at')->nullable();
            $t->string('email_status', 20)->default('pending');
            $t->string('sms_status', 20)->default('pending');
            $t->timestamps();
            $t->unique(['task_id', 'user_id', 'event_key']);
            $t->index(['tenant_id', 'user_id', 'read_at']);
        });
        Schema::create('task_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->boolean('email_enabled')->default(false);
            $t->boolean('sms_enabled')->default(false);
            $t->string('phone', 20)->nullable();
            $t->timestamp('sms_consented_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['task_preferences', 'task_notices', 'task_events', 'task_updates', 'task_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
