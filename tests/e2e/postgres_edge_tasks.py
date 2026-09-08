#!/usr/bin/env python3
"""Qualify concurrent task receipts on fresh tmpfs PostgreSQL, never PHPUnit."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
PHP = r'''<?php
require getenv('CDNF_QUALIFICATION_ROOT').'/core/vendor/autoload.php';
$app = require getenv('CDNF_QUALIFICATION_ROOT').'/core/bootstrap/app.php';
$app->useStoragePath(getenv('CDNF_QUALIFICATION_STORAGE'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== 'qualification' || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || config('database.connections.pgsql.database') !== 'cdnf_tasks_qualification'
    || ! str_starts_with(getenv('CDNF_QUALIFICATION_INSTANCE'), 'cdnf-task-qualification-')) {
    throw new RuntimeException('Isolated PostgreSQL identity guard failed.');
}
Illuminate\Support\Facades\Queue::fake();
$mode = $argv[1];
if ($mode === 'init') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $edge = App\Models\Edge::query()->create(['name' => 'task-qualification', 'country_code' => 'IR', 'continent_code' => 'AS']);
    $single = App\Models\EdgeTask::query()->create(['edge_id' => $edge->id, 'type' => 'cell_restart', 'status' => 'pending', 'payload' => []]);
    $operation = App\Models\Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'running', 'input' => []]);
    $tasks = [];
    foreach ([1, 2] as $number) {
        $tasks[] = App\Models\EdgeTask::query()->create(['edge_id' => $edge->id, 'type' => 'origin_test', 'status' => 'pending',
            'payload' => ['operation_id' => $operation->id, 'record_id' => 999999]])->id;
    }
    echo json_encode(['single' => $single->id, 'operation' => $operation->id, 'tasks' => $tasks])."\n";
} elseif ($mode === 'hold-terminal') {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
        App\Models\EdgeTask::query()->lockForUpdate()->findOrFail($argv[2])->update([
            'status' => 'succeeded', 'attempts' => 1, 'result' => ['status' => 'completed'], 'finished_at' => now(),
        ]);
        echo "locked\n"; flush();
        usleep(1500000);
    });
} elseif ($mode === 'report') {
    $edge = App\Models\Edge::query()->where('name', 'task-qualification')->sole();
    if (($argv[4] ?? '') === 'pause-aggregate') {
        Illuminate\Support\Facades\DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'select * from "edge_tasks" where "type" =')) {
                echo "aggregate-read\n"; flush();
                fgets(STDIN);
            }
        });
    }
    $origin = $argv[3] === 'origin';
    $payload = ['status' => $origin ? 'succeeded' : 'failed', 'result' => ['status' => $origin ? 'healthy' : 'failed']];
    $request = Illuminate\Http\Request::create('/edge/v1/qualification', 'POST', $payload);
    $request->attributes->set('edge', $edge);
    $response = app(App\Http\Controllers\EdgeAgentController::class)->taskResult($request, $argv[2]);
    $task = App\Models\EdgeTask::query()->findOrFail($argv[2]);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getData(true), 'task_status' => $task->status, 'attempts' => $task->attempts])."\n";
} elseif ($mode === 'operation') {
    $operation = App\Models\Operation::query()->findOrFail($argv[2]);
    echo json_encode(['status' => $operation->status, 'reported_completed' => $operation->result['completed'] ?? null,
        'completed_tasks' => App\Models\EdgeTask::query()->where('type', 'origin_test')->where('status', 'succeeded')->count()])."\n";
} elseif ($mode === 'dispatch-init') {
    $owner = App\Models\User::factory()->create();
    $domain = App\Models\Domain::query()->create(['name' => 'dispatch.example.com', 'display_name' => 'Dispatch',
        'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    $domain->users()->attach($owner);
    $record = $domain->dnsRecords()->create(['type' => 'A', 'mode' => 'proxied', 'name' => $domain->name, 'content' => '8.8.8.8',
        'ttl' => 60, 'content_hash' => hash('sha256', '8.8.8.8'), 'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http']]);
    App\Models\Edge::query()->where('name', 'task-qualification')->update(['registered_at' => now(), 'last_heartbeat_at' => now()]);
    $operation = App\Models\Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'actor_id' => $owner->id,
        'input' => ['domain_id' => $domain->id, 'record_id' => $record->id, 'addresses' => ['8.8.8.8'],
            'origin_checksum' => hash('sha256', App\Support\ArtifactSigner::encode($record->origin))]]);
    echo $operation->id."\n";
} elseif ($mode === 'dispatch') {
    if (($argv[3] ?? '') === 'pause-presence') {
        $paused = false;
        Illuminate\Support\Facades\DB::listen(function ($query) use (&$paused): void {
            $presence = str_starts_with($query->sql, 'select exists(')
                || str_starts_with($query->sql, 'select * from "edge_tasks" where "type" =');
            if (! $paused && $presence && str_contains($query->sql, '"edge_tasks"')) {
                $paused = true;
                echo "presence-read\n"; flush();
                fgets(STDIN);
            }
        });
    }
    (new App\Jobs\DispatchOriginTest($argv[2]))->handle();
    echo App\Models\EdgeTask::query()->where('payload->operation_id', $argv[2])->count()."\n";
}
'''


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def main() -> None:
    identifier = 'cdnf-task-qualification-' + uuid.uuid4().hex[:12]
    image = 'postgres@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15'
    with tempfile.TemporaryDirectory(prefix=identifier) as directory:
        root = Path(directory)
        script = root / 'qualification.php'
        script.write_text(PHP)
        for path in ('framework/cache', 'framework/sessions', 'framework/views', 'logs'):
            (root / 'storage' / path).mkdir(parents=True, exist_ok=True)
        subprocess.run(['docker', 'run', '-d', '--name', identifier, '--label', 'cdnfoundry.qualification=edge-tasks',
                        '--tmpfs', '/var/lib/postgresql:rw,size=512m', '--memory', '512m', '--cpus', '1',
                        '-p', '127.0.0.1::5432', '-e', 'POSTGRES_PASSWORD=isolated-fixture-only',
                        '-e', 'POSTGRES_DB=cdnf_tasks_qualification', image], check=True, capture_output=True)
        try:
            port = subprocess.check_output(['docker', 'port', identifier, '5432/tcp'], text=True).strip().rsplit(':', 1)[1]
            for _ in range(60):
                if subprocess.run(['docker', 'exec', identifier, 'pg_isready', '-h', '127.0.0.1', '-U', 'postgres'], capture_output=True).returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Disposable PostgreSQL did not become ready')
            env = {**os.environ, 'APP_ENV': 'qualification', 'APP_KEY': 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                   'APP_CONFIG_CACHE': str(root / 'config.php'), 'DB_CONNECTION': 'pgsql', 'DB_URL': '',
                   'DB_HOST': '127.0.0.1', 'DB_PORT': port, 'DB_DATABASE': 'cdnf_tasks_qualification',
                   'DB_USERNAME': 'postgres', 'DB_PASSWORD': 'isolated-fixture-only',
                   'CACHE_STORE': 'array', 'QUEUE_CONNECTION': 'sync', 'SESSION_DRIVER': 'array', 'LOG_CHANNEL': 'stderr',
                   'CDNF_QUALIFICATION_INSTANCE': identifier, 'CDNF_QUALIFICATION_ROOT': str(ROOT),
                   'CDNF_QUALIFICATION_STORAGE': str(root / 'storage')}
            def command(*args: str) -> list[str]:
                return ['php', str(script), *args]
            created = json.loads(subprocess.check_output(command('init'), env=env, text=True))
            holder = subprocess.Popen(command('hold-terminal', created['single']), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            require(holder.stdout.readline().strip() == 'locked', 'Terminal task writer did not acquire its row.')
            start = time.monotonic()
            terminal = json.loads(subprocess.check_output(command('report', created['single'], 'failed'), env=env, text=True))
            terminal_wait = round(time.monotonic()-start, 3)
            holder.communicate(timeout=10)
            require(holder.returncode == 0, 'Terminal writer failed.')
            first = subprocess.Popen(command('report', created['tasks'][0], 'origin', 'pause-aggregate'), env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            require(first.stdout.readline().strip() == 'aggregate-read', 'Aggregate reader did not reach the contention boundary.')
            second = subprocess.Popen(command('report', created['tasks'][1], 'origin'), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                second.communicate(timeout=2)
                aggregate_waited = False
            except subprocess.TimeoutExpired:
                aggregate_waited = True
            first.stdin.write('continue\n')
            first.stdin.flush()
            for process in (first, second):
                output, error = process.communicate(timeout=15)
                require(process.returncode == 0, 'Task reporter failed: '+error)
            aggregate = json.loads(subprocess.check_output(command('operation', created['operation']), env=env, text=True))
            print(json.dumps({'environment': identifier, 'image': image, 'terminal_receipt': terminal,
                              'terminal_wait_seconds': terminal_wait, 'aggregate_waited': aggregate_waited, 'aggregate': aggregate}))
            require(terminal['task_status'] == 'succeeded' and terminal['attempts'] == 1 and terminal['body']['data'].get('replayed') is True,
                    'Concurrent task result overwrote a terminal receipt.')
            require(aggregate == {'status': 'succeeded', 'reported_completed': 2, 'completed_tasks': 2}, 'Concurrent task results left a stale aggregate.')
            operation = subprocess.check_output(command('dispatch-init'), env=env, text=True).strip()
            first = subprocess.Popen(command('dispatch', operation, 'pause-presence'), env=env, stdin=subprocess.PIPE,
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            require(first.stdout.readline().strip() == 'presence-read', 'Dispatcher did not reach its task-presence boundary.')
            second = subprocess.Popen(command('dispatch', operation), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                second.communicate(timeout=2)
                dispatch_waited = False
            except subprocess.TimeoutExpired:
                dispatch_waited = True
            first.stdin.write('continue\n')
            first.stdin.flush()
            for process in (first, second):
                output, error = process.communicate(timeout=15)
                require(process.returncode == 0, 'Dispatcher failed: '+error)
            task_count = int(subprocess.check_output(command('dispatch', operation), env=env, text=True).strip())
            print(json.dumps({'origin_dispatch_waited': dispatch_waited, 'origin_dispatch_task_count': task_count}))
            require(task_count == 1, 'Concurrent origin dispatch created duplicate tasks.')
        finally:
            subprocess.run(['docker', 'rm', '-f', identifier], check=True, capture_output=True)


if __name__ == '__main__':
    main()
