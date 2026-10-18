<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('cms_pages')
                ->restrictOnDelete();
            $table->string('slug');
            $table->string('path')->unique();
            $table->string('title');
            $table->string('menu_title')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('unpublish_at')->nullable();
            $table->boolean('is_home')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedBigInteger('og_image_id')->nullable()->index();
            $table->string('canonical_url')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('schema_type')->nullable();
            $table->timestamps();

            $table->index([
                'parent_id',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
