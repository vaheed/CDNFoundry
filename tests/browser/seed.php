<?php

use App\Enums\UserType;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Seeds the disposable browser database only. Credentials come from the environment, generated per run.
$admin = User::query()->create([
    'name' => 'Browser Admin', 'email' => 'admin@browser.test', 'type' => UserType::Admin,
    'password' => Hash::make(getenv('CDNF_BROWSER_ADMIN_PASSWORD')), 'email_verified_at' => now(),
]);
$user = User::query()->create([
    'name' => 'Browser User', 'email' => 'user@browser.test', 'type' => UserType::User,
    'password' => Hash::make(getenv('CDNF_BROWSER_USER_PASSWORD')), 'email_verified_at' => now(),
]);
foreach (['alpha-browser.example', 'beta-browser.example', 'gamma-browser.example'] as $name) {
    Domain::createPendingFor($user, $name, '127.0.0.1');
}
Domain::createPendingFor($admin, 'private-browser.example', '127.0.0.1');
echo "browser seed complete\n";
