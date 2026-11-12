<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: delivery channels for notifications, plus the platform setting group that governs them. */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Null owner: a platform channel that receives everything. Otherwise a domain user's own channel.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 100);
            // Encrypted JSON: bot token and chat id, webhook URL, or generic webhook URL and signing secret.
            $table->text('secret_ciphertext');
            // A masked, non-secret description of the destination shown in lists.
            $table->string('destination_hint', 160);
            // Null on a platform channel means every event; a user channel always lists its events.
            $table->jsonb('events')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('paused_reason', 500)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table notification_channels add constraint notification_channels_type_check check (type in ('telegram', 'discord', 'slack', 'webhook'))");
            DB::statement("alter table notification_channels add constraint notification_channels_status_check check (status in ('active', 'paused'))");
            // The generic webhook is an administrator-only channel, so a domain user can never point the platform at an arbitrary URL.
            DB::statement("alter table notification_channels add constraint notification_channels_webhook_platform_check check (type <> 'webhook' or user_id is null)");
            DB::statement("alter table notification_channels add constraint notification_channels_user_events_check check (user_id is null or events is not null)");
        }

        DB::table('system_settings')->insert([
            'group' => 'notifications', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            'values' => json_encode([
                'domain_user_channels' => false, 'max_channels_per_user' => 5, 'max_platform_channels' => 20,
                'channel_messages_per_minute' => 6, 'pause_after_failures' => 10,
                'origin_error_percent' => 20, 'origin_error_min_requests' => 100,
            ]),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'notifications')->delete();
        Schema::dropIfExists('notification_channels');
    }
};
