<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class ResticBackupRepository
{
    public function configured(): bool
    {
        return (string) config('services.backups.repository') !== '' && is_readable((string) config('services.backups.password_file'));
    }

    public function create(): array
    {
        $output = $this->run(['/usr/local/bin/cdnf-backup-create']);
        $events = collect(preg_split('/\R/', trim($output)))->filter()->map(fn (string $line) => json_decode($line, true))->filter(fn ($row) => is_array($row));
        $summary = $events->last(fn (array $row) => isset($row['snapshot_id']));
        if (! is_array($summary)) {
            throw new RuntimeException('Restic did not return a snapshot identifier.');
        }

        return ['snapshot_id' => $summary['snapshot_id'], 'size_bytes' => (int) ($summary['data_added'] ?? $summary['total_bytes_processed'] ?? 0), 'manifest_sha256' => hash('sha256', $output)];
    }

    public function snapshotExists(string $snapshotId): bool
    {
        $this->assertSnapshot($snapshotId);
        $this->run(['restic', 'snapshots', '--json', $snapshotId]);

        return true;
    }

    public function forget(string $snapshotId): void
    {
        $this->assertSnapshot($snapshotId);
        $this->run(['restic', 'forget', $snapshotId]);
    }

    public function restore(string $snapshotId): void
    {
        $this->assertSnapshot($snapshotId);
        $this->run(['/usr/local/bin/cdnf-backup-restore', $snapshotId], 7200);
    }

    private function run(array $command, int $timeout = 3600): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Encrypted off-host backup repository is not configured.');
        }
        $process = new Process($command, null, [
            'RESTIC_REPOSITORY' => config('services.backups.repository'),
            'RESTIC_PASSWORD_FILE' => config('services.backups.password_file'),
            'AWS_ACCESS_KEY_ID' => config('services.backups.access_key'),
            'AWS_SECRET_ACCESS_KEY' => config('services.backups.secret_key'),
            'AWS_DEFAULT_REGION' => config('services.backups.region'),
            'PGHOST' => config('database.connections.pgsql.host'),
            'PGPORT' => (string) config('database.connections.pgsql.port'),
            'PGDATABASE' => config('database.connections.pgsql.database'),
            'PGUSER' => config('database.connections.pgsql.username'),
            'PGPASSWORD' => config('database.connections.pgsql.password'),
        ]);
        $process->setTimeout($timeout)->mustRun();

        return $process->getOutput();
    }

    private function assertSnapshot(string $snapshotId): void
    {
        if (! preg_match('/^[a-f0-9]{8,128}$/', $snapshotId)) {
            throw new RuntimeException('Invalid backup snapshot identifier.');
        }
    }
}
