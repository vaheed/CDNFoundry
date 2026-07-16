<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
