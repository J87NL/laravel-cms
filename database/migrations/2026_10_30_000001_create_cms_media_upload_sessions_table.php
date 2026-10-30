<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media_upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();
            $table->string('file_name');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->json('media_attributes');
            $table->timestamp('finishing_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media_upload_sessions');
    }
};
