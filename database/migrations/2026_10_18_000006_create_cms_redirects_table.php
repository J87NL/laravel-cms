<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('source')->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_redirects');
    }
};
