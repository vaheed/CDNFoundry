<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's request rules. Each type (redirect first) is one row with typed conditions and a typed action. */
    public function up(): void
    {
        $json = DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json';
        Schema::create('request_rules', function (Blueprint $table) use ($json): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('priority');
            $table->{$json}('conditions');
            $table->{$json}('action');
            $table->string('preset', 32)->nullable();
            $table->char('fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['domain_id', 'type', 'fingerprint']);
            $table->index(['domain_id', 'type', 'priority']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE request_rules ADD CONSTRAINT request_rules_type_check CHECK (type IN ('redirect'))");
            DB::statement('ALTER TABLE request_rules ADD CONSTRAINT request_rules_priority_check CHECK (priority BETWEEN -1000000 AND 1000000)');
            DB::statement("ALTER TABLE request_rules ADD CONSTRAINT request_rules_conditions_array CHECK (jsonb_typeof(conditions) = 'array' AND jsonb_array_length(conditions) <= 5)");
            DB::statement("ALTER TABLE request_rules ADD CONSTRAINT request_rules_action_object CHECK (jsonb_typeof(action) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_rules');
    }
};
