<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: scheduled nameserver re-verification and registration expiry tracking.
     * Every column is nullable or defaulted, so old and new releases can run together.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->timestampTz('ns_check_next_at')->nullable();
            $table->timestampTz('ns_checked_at')->nullable();
            $table->string('ns_check_result', 16)->nullable();
            $table->unsignedSmallInteger('ns_check_attempts')->default(0);
            $table->unsignedSmallInteger('ns_mismatch_count')->default(0);
            $table->jsonb('ns_observed')->nullable();
            $table->timestampTz('delegation_lost_at')->nullable();
            $table->timestampTz('registration_expires_at')->nullable();
            $table->timestampTz('registration_checked_at')->nullable();
            $table->timestampTz('registration_next_check_at')->nullable();
            $table->unsignedSmallInteger('registration_warned_days')->nullable();
        });
        // Partial indexes keep the due scans on the few rows that can be checked.
        DB::statement("CREATE INDEX domains_ns_check_due_index ON domains (ns_check_next_at) WHERE deleted_at IS NULL AND lifecycle_state IN ('pending_verification', 'active')");
        DB::statement("CREATE INDEX domains_registration_check_due_index ON domains (registration_next_check_at) WHERE deleted_at IS NULL AND nameservers_verified_at IS NOT NULL AND lifecycle_state <> 'deprovisioning'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_ns_check_result_valid CHECK (ns_check_result IS NULL OR ns_check_result IN ('matched', 'mismatch', 'unknown'))");
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_ns_observed_array CHECK (ns_observed IS NULL OR jsonb_typeof(ns_observed) = 'array')");
        }

        DB::table('system_settings')->insertOrIgnore([
            'group' => 'domain_monitoring',
            'values' => json_encode([
                'nameserver_enforcement' => true,
                'active_check_hours' => 12,
                'mismatch_confirmations' => 3,
                'confirmation_spacing_minutes' => 60,
                'registration_check_days' => 7,
                'registration_warning_days' => 30,
            ]),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'domain_monitoring')->delete();
        DB::statement('DROP INDEX IF EXISTS domains_registration_check_due_index');
        DB::statement('DROP INDEX IF EXISTS domains_ns_check_due_index');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_ns_observed_array');
            DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_ns_check_result_valid');
        }
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn([
                'ns_check_next_at', 'ns_checked_at', 'ns_check_result', 'ns_check_attempts', 'ns_mismatch_count', 'ns_observed',
                'delegation_lost_at', 'registration_expires_at', 'registration_checked_at', 'registration_next_check_at', 'registration_warned_days',
            ]);
        });
    }
};
