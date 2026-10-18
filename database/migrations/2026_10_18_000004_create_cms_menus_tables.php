<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->timestamps();
        });

        Schema::create('cms_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')
                ->constrained('cms_menus')
                ->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('cms_menu_items')
                ->cascadeOnDelete();
            $table->foreignId('page_id')
                ->nullable()
                ->constrained('cms_pages')
                ->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->string('label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index([
                'menu_id',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_menus');
    }
};
