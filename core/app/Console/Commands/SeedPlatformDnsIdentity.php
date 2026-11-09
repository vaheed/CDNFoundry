<?php

namespace App\Console\Commands;

use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class SeedPlatformDnsIdentity extends Command
{
    protected $signature = 'cdnf:dns:identity:seed {values : JSON object with the full platform DNS identity}';

    protected $description = 'Store the first platform DNS identity from Fleet. An identity that already exists is never changed.';

    public function handle(): int
    {
        try {
            $values = json_decode((string) $this->argument('values'), true, 32, JSON_THROW_ON_ERROR);
            $settings = PlatformDnsSettingsRequest::validateInput(is_array($values) ? $values : []);
        } catch (JsonException|ValidationException $exception) {
            $this->error('The identity is not valid: '.($exception instanceof ValidationException ? json_encode($exception->errors()) : $exception->getMessage()));

            return self::FAILURE;
        }
        $operation = DB::transaction(function () use ($settings): ?Operation {
            if (PlatformDnsSetting::query()->lockForUpdate()->find(1) !== null) {
                return null;
            }
            $stored = PlatformDnsSetting::query()->create([...$settings, 'id' => 1, 'revision' => 1]);
            $operation = Operation::query()->create(['type' => 'platform_dns_identity.update', 'status' => 'pending', 'input' => ['settings_id' => 1, 'revision' => 1]]);
            AuditLog::record(null, 'platform_dns_settings.update_requested', $stored, ['operation_id' => $operation->id, 'revision' => 1, 'source' => 'fleet']);

            return $operation;
        });
        if ($operation === null) {
            $this->info('A platform DNS identity already exists; it was left as it is.');

            return self::SUCCESS;
        }
        ApplyPlatformDnsSettings::dispatch($operation->getKey());
        $this->info("Stored the platform DNS identity; applying it as operation {$operation->getKey()}.");

        return self::SUCCESS;
    }
}
