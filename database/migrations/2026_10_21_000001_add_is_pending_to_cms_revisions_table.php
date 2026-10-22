<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_revisions', function (Blueprint $table): void {
            $table->boolean('is_pending')
                ->default(false)
                ->after('snapshot');

            $table->index([
                'page_id',
                'is_pending',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('cms_revisions', function (Blueprint $table): void {
            $table->dropIndex([
                'page_id',
                'is_pending',
            ]);

            $table->dropColumn('is_pending');
        });
    }
};
