<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->string('identity_certificate_serial', 64)->nullable()->unique();
            $table->timestampTz('identity_certificate_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edges', fn (Blueprint $table) => $table->dropColumn(['identity_certificate_serial', 'identity_certificate_expires_at']));
    }
};
