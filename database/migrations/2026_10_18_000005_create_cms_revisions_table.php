<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')
                ->constrained('cms_pages')
                ->cascadeOnDelete();
            $table->json('snapshot');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index([
                'page_id',
                'id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_revisions');
    }
};
