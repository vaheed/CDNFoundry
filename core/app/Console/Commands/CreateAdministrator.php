<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdministrator extends Command
{
    protected $signature = 'cdnf:admin:create {--name=} {--email=}';

    protected $description = 'Create the first CDNFoundry administrator without exposing the password in shell history';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Administrator name')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Administrator email'))));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $administrator = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'type' => UserType::Admin,
        ]);
        AuditLog::record(null, 'user.bootstrap_created', $administrator, ['type' => UserType::Admin->value]);

        $this->info("Administrator {$email} created.");

        return self::SUCCESS;
    }
}
