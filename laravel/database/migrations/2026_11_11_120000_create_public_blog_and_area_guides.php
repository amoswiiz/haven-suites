<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Blog posts and editable neighbourhood introductions for the public website. */
    public function up(): void
    {
        Schema::create('public_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 160);
            $table->string('title', 180);
            $table->string('excerpt', 320)->nullable();
            $table->mediumText('body');
            $table->string('cover_path')->nullable();
            $table->string('cover_caption', 180)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'published_at']);
        });
        Schema::create('public_area_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('city', 100);
            $table->string('area', 150)->default('');
            $table->text('intro')->nullable();
            $table->text('highlights')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'city', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_area_guides');
        Schema::dropIfExists('public_posts');
    }
};
