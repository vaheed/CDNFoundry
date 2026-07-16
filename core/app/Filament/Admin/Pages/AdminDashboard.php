<?php

namespace App\Filament\Admin\Pages;

use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class AdminDashboard extends Dashboard
{
    protected string $view = 'filament.admin.pages.dashboard';

    public function getSummaryProperty(): array
    {
        return [
            'users' => User::query()->count(),
            'disabled_users' => User::query()->whereNotNull('disabled_at')->count(),
            'pending_operations' => Operation::query()->whereIn('status', ['pending', 'running'])->count(),
            'failed_operations' => Operation::query()->where('status', 'failed')->count(),
        ];
    }

    public function getRecentAuditsProperty(): Collection
    {
        return AuditLog::query()->with('actor')->latest('id')->limit(8)->get();
    }

    public function getQueueStateProperty(): array
    {
        try {
            return collect(['interactive', 'runtime', 'certificate_purge', 'bulk_maintenance'])
                ->mapWithKeys(fn (string $queue): array => [$queue => (int) Redis::connection()->llen("queues:$queue")])
                ->all();
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
