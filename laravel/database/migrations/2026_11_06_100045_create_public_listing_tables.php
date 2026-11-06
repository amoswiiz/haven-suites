<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('headline', 180)->nullable();
            $table->string('tagline', 500)->nullable();
            $table->string('contact_email', 180)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->foreignId('enquiry_assignee_id')->nullable()->constrained('users');
            $table->unsignedSmallInteger('response_hours')->default(24);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('public_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('unit_id');
            $table->string('slug', 160);
            $table->string('title', 180);
            $table->text('description');
            $table->string('city', 100);
            $table->string('neighbourhood', 150)->nullable();
            $table->string('public_location', 255)->nullable();
            $table->unsignedSmallInteger('max_guests');
            $table->unsignedSmallInteger('min_nights')->default(1);
            $table->string('check_in_time', 5)->default('14:00');
            $table->string('check_out_time', 5)->default('10:00');
            $table->decimal('starting_price', 12, 2)->nullable();
            $table->json('amenities')->nullable();
            $table->text('house_rules')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'unit_id']);
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'city']);
        });
        Schema::create('public_listing_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('public_listings')->cascadeOnDelete();
            $table->string('storage_key');
            $table->string('mime_type', 30);
            $table->string('caption', 180)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'listing_id', 'sort_order']);
        });
        Schema::create('public_listing_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('listing_id')->nullable()->index();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 40);
            $table->longText('before_snapshot')->nullable();
            $table->longText('after_snapshot');
            $table->timestamp('created_at');
        });
        Schema::create('public_unit_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('unit_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('kind', 20);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'unit_id', 'start_date', 'end_date'], 'public_closures_unit_dates_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_unit_closures');
        Schema::dropIfExists('public_listing_audit_events');
        Schema::dropIfExists('public_listing_photos');
        Schema::dropIfExists('public_listings');
        Schema::dropIfExists('public_sites');
    }
};
