<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name')->nullable();
            $table->string('title_pattern')->nullable();
            $table->text('default_meta_description')->nullable();
            $table->string('entity_type')->default('Person');
            $table->string('entity_name')->nullable();
            $table->string('entity_job_title')->nullable();
            $table->string('entity_email')->nullable();
            $table->json('entity_same_as')->nullable();
            $table->unsignedBigInteger('entity_image_id')->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable();
            $table->text('robots_extra')->nullable();
            $table->text('llms_intro')->nullable();
            $table->string('contact_recipient')->nullable();
            $table->foreignId('privacy_page_id')
                ->nullable()
                ->constrained('cms_pages')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_settings');
    }
};
