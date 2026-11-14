<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: per-domain log push destinations, and the platform settings that bound them. */
    public function up(): void
    {
        Schema::create('log_push_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('name', 100);
            $table->jsonb('datasets');
            // Encrypted JSON: endpoint and credentials. Never returned by the API.
            $table->text('secret_ciphertext');
            $table->string('destination_hint', 160);
            $table->boolean('truncate_client_ip')->default(false);
            $table->string('status', 12)->default('active');
            // One cursor per dataset: the last delivered occurred_at and event id. Written only when rows were delivered.
            $table->jsonb('cursors')->default('{}');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedInteger('lag_seconds')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('failures_total')->default(0);
            $table->unsignedBigInteger('rows_pushed')->default(0);
            $table->unsignedBigInteger('bytes_pushed')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_gap_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_run_at']);
            $table->index('domain_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table log_push_destinations add constraint log_push_destinations_kind_check check (kind in ('https', 's3'))");
            DB::statement("alter table log_push_destinations add constraint log_push_destinations_status_check check (status in ('active', 'paused'))");
            DB::statement("alter table log_push_destinations add constraint log_push_destinations_datasets_check check (jsonb_typeof(datasets) = 'array' and jsonb_array_length(datasets) between 1 and 3)");
        }
        DB::table('system_settings')->insert([
            'group' => 'log_push', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            'values' => json_encode([
                'domain_user_log_push' => false, 'max_destinations_per_domain' => 2, 'max_destinations' => 2000,
                'batch_rows' => 5000, 'max_batches_per_run' => 5, 'failure_notify_after' => 5,
            ]),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'log_push')->delete();
        Schema::dropIfExists('log_push_destinations');
    }
};
