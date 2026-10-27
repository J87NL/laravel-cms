<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_settings', function (Blueprint $table): void {
            $table->string('contact_turnstile_site_key')->nullable()->after('contact_recipient');
            $table->text('contact_turnstile_secret_key')->nullable()->after('contact_turnstile_site_key');
        });
    }

    public function down(): void
    {
        Schema::table('cms_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_turnstile_site_key',
                'contact_turnstile_secret_key',
            ]);
        });
    }
};
