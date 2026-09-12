#!/usr/bin/env python3
"""Qualify domain-name concurrency on a disposable PostgreSQL, never PHPUnit.

Uses the actual Laravel migrations and domain creation/finalization methods.
No existing database, container, or named volume is mutated.
"""
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
    || config('database.connections.pgsql.database') !== 'cdnf_claim_qualification'
    || ! str_starts_with(getenv('CDNF_QUALIFICATION_INSTANCE'), 'cdnf-claim-qualification-')) {
    throw new RuntimeException('Isolated PostgreSQL identity guard failed.');
}
Illuminate\Support\Facades\Queue::fake();
$mode = $argv[1];
if ($mode === 'init') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    App\Models\User::factory()->create(['email' => 'owner-a@example.test']);
    App\Models\User::factory()->create(['email' => 'owner-b@example.test']);
    echo "initialized\n";
} elseif (str_starts_with($mode, 'tls-')) {
    $user = App\Models\User::findOrFail(1);
    auth()->login($user);
    $request = function (array $data) use ($user) {
        $request = Illuminate\Http\Request::create('/api/qualification/tls', 'POST', $data);
        $request->setUserResolver(fn () => $user);
        return $request;
    };
    if ($mode === 'tls-init') {
        $domain = App\Models\Domain::query()->create(['name' => 'example.test', 'display_name' => 'TLS race',
            'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
        $domain->users()->attach($user);
        $domain->dnsRecords()->create(['name' => 'www.example.test', 'type' => 'A', 'mode' => 'proxied', 'ttl' => 300,
            'content' => '8.8.8.8', 'content_hash' => hash('sha256', 'tls-initial'), 'origin' => [
                'host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => 'www.example.test', 'sni' => null,
                'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0,
            ]]);
        $initial = Tests\Support\CertificateChain::make('valid', ['www.example.test', 'api.example.test']);
        app(App\Http\Controllers\TlsController::class)->upload($request($initial), $domain);
        file_put_contents(getenv('CDNF_TLS_BUNDLE'), json_encode(Tests\Support\CertificateChain::make('valid')));
        chmod(getenv('CDNF_TLS_BUNDLE'), 0600);
    } else {
        $domain = App\Models\Domain::query()->where('name', 'example.test')->sole();
        if ($mode === 'tls-managed-init') {
            $managed = $domain->tlsCertificates()->where('kind', 'managed')->first();
            if ($managed === null) {
                $bundle = Tests\Support\CertificateChain::make('valid', ['example.test', '*.example.test']);
                $validated = App\Support\UploadedCertificate::validate($domain, $bundle['certificate'], $bundle['chain'], $bundle['private_key']);
                $managed = $domain->tlsCertificates()->create([
                    'kind' => 'managed', 'status' => 'active', 'certificate_pem' => $validated['certificate_pem'],
                    'chain_pem' => $validated['chain_pem'], 'private_key_ciphertext' => $validated['private_key'],
                    'names' => $validated['names'], 'fingerprint_sha256' => $validated['fingerprint_sha256'],
                    'not_before' => $validated['not_before'], 'expires_at' => $validated['expires_at'], 'activated_at' => now(),
                ]);
            }
            $domain->update(['tls_mode' => 'managed', 'active_tls_certificate_id' => null, 'revision' => $domain->revision + 1]);
            echo json_encode(['revision' => $domain->revision, 'managed_id' => $managed->id])."\n";
        } elseif ($mode === 'tls-managed-hold') {
            Illuminate\Support\Facades\DB::transaction(function () use ($domain, $request, $argv): void {
                $locked = App\Models\Domain::query()->lockForUpdate()->findOrFail($domain->id);
                if ($argv[2] === 'custom') {
                    app(App\Http\Controllers\TlsController::class)->update($request(['mode' => 'custom']), $locked);
                } else {
                    $data = $locked->dnsRecords()->firstOrFail()->only(['type', 'content', 'ttl', 'mode', 'origin']);
                    $data['name'] = 'managed-race';
                    app(App\Http\Controllers\DnsRecordController::class)->store($request($data), $locked);
                }
                echo "locked\n"; flush();
                fgets(STDIN);
                echo json_encode($locked->refresh()->only(['revision', 'tls_mode', 'active_tls_certificate_id']))."\n";
            });
        } elseif ($mode === 'tls-managed-run') {
            config(['services.acme.renew_before_days' => 1]);
            (new App\Jobs\EnsureManagedCertificates($domain->id))->handle();
            echo json_encode($domain->refresh()->only(['revision', 'tls_mode', 'active_tls_certificate_id']))."\n";
        } elseif ($mode === 'tls-upload') {
            try {
                $status = app(App\Http\Controllers\TlsController::class)->upload(
                    $request(json_decode(file_get_contents(getenv('CDNF_TLS_BUNDLE')), true)), $domain)->getStatusCode();
            } catch (Illuminate\Validation\ValidationException) {
                $status = 422;
            } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $status = $exception->getStatusCode();
            }
            echo json_encode(['status' => $status])."\n";
        } elseif ($mode === 'tls-change') {
            $data = $domain->dnsRecords()->firstOrFail()->only(['type', 'content', 'ttl', 'mode', 'origin']);
            $data['name'] = 'api';
            app(App\Http\Controllers\DnsRecordController::class)->store($request($data), $domain);
        } elseif ($mode === 'tls-state') {
            echo json_encode(['revision' => $domain->revision, 'certificate_id' => $domain->active_tls_certificate_id,
                'certificate_count' => $domain->tlsCertificates()->count(), 'names' => $domain->activeTlsCertificate->names,
                'proxied_names' => $domain->dnsRecords()->where('mode', 'proxied')->orderBy('name')->pluck('name')])."\n";
        }
    }
} elseif ($mode === 'policy-init') {
    $domain = App\Models\Domain::query()->create(['name' => 'policy.example.com', 'display_name' => 'policy', 'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
    $domain->dnsRecords()->create(['name' => $domain->name, 'type' => 'A', 'mode' => 'proxied', 'ttl' => 60,
        'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'origin' => [
            'host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => $domain->name, 'sni' => null,
            'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0,
        ]]);
    $edge = App\Models\Edge::query()->create(['name' => 'policy-race', 'country_code' => 'IR', 'continent_code' => 'AS']);
    $pool = App\Models\EdgePool::query()->where('kind', 'shared')->firstOrFail();
    $edge->cells()->create(['slot' => 1, 'edge_pool_id' => $pool->id, 'status' => 'assigned']);
    $pool->endpoints()->create(['edge_id' => $edge->id, 'ipv4' => '1.0.0.1']);
    $domain->edgePlacement()->create(['target_pool_id' => $pool->id, 'desired_revision' => 1, 'state' => 'deploying']);
} elseif ($mode === 'policy-change') {
    $pool = App\Models\EdgePool::query()->where('kind', 'shared')->firstOrFail();
    $pool->update(['cache_profile' => $argv[2], 'revision' => $pool->revision + 1]);
} elseif ($mode === 'policy-reconcile') {
    $domain = App\Models\Domain::query()->where('name', 'policy.example.com')->sole();
    (new App\Jobs\ReconcileEdgeDomain($domain->id))->handle();
    $artifacts = App\Models\EdgeArtifact::query()->where('domain_id', $domain->id)->orderBy('sequence')->get();
    echo json_encode(['revision' => $domain->refresh()->revision,
        'artifacts' => $artifacts->map(fn ($row) => ['sequence' => $row->sequence, 'revision' => $row->revision, 'checksum' => $row->checksum, 'profile' => $row->payload['cache']['profile_name']]),
        'snapshots' => App\Models\EdgeRevision::query()->where('domain_id', $domain->id)->count()])."\n";
} elseif ($mode === 'edge-init') {
    $edge = App\Models\Edge::query()->firstOrCreate(['name' => 'sequence-race'], ['country_code' => 'IR', 'continent_code' => 'AS']);
    $edge->update(['active_sequence' => 0]);
    $edge->cells()->firstOrCreate(['slot' => 1], ['status' => 'stopped']);
    $edge->artifacts()->firstOrCreate(['sequence' => 1], ['kind' => 'domain', 'revision' => 1, 'payload' => [], 'checksum' => str_repeat('a', 64), 'signature' => str_repeat('b', 128)]);
} elseif ($mode === 'edge-hold') {
    Illuminate\Support\Facades\DB::transaction(function (): void {
        App\Models\Edge::query()->where('name', 'sequence-race')->lockForUpdate()->sole()->update(['active_sequence' => 42]);
        echo "locked\n"; flush();
        usleep(1500000);
    });
} elseif ($mode === 'edge-report') {
    $edge = App\Models\Edge::query()->where('name', 'sequence-race')->sole();
    echo "loaded\n"; flush();
    fgets(STDIN);
    $action = $argv[2];
    $payload = $action === 'applied' ? ['sequence' => 1] : [
        'agent_version' => '1.2.0', 'listener_ready' => false, 'active_sequence' => 1,
        'cells' => [['name' => 'cell-01', 'status' => 'stopped', 'capacity' => ['active_connections' => 0]]],
    ];
    $request = Illuminate\Http\Request::create('/edge/v1/qualification', 'POST', $payload);
    $request->attributes->set('edge', $edge);
    try {
        $status = app(App\Http\Controllers\EdgeAgentController::class)->$action($request)->getStatusCode();
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        $status = $exception->getStatusCode();
    }
    echo json_encode(['status' => $status, 'active_sequence' => $edge->refresh()->active_sequence])."\n";
} elseif ($mode === 'create') {
    try {
        $domain = App\Models\Domain::createPendingFor(App\Models\User::findOrFail((int) $argv[2]), $argv[3]);
        echo 'created:'.$domain->id."\n";
    } catch (Illuminate\Validation\ValidationException $exception) {
        echo 'rejected:'.json_encode($exception->errors())."\n";
    }
} elseif ($mode === 'verify') {
    $domain = App\Models\Domain::query()->where('name', 'race.example.com')->sole();
    if ($domain->users()->count() !== 1 || App\Models\Domain::query()->count() !== 1) {
        throw new RuntimeException('Concurrent claim isolation failed.');
    }
    try {
        App\Models\Domain::query()->create(['name' => $domain->name, 'display_name' => 'duplicate']);
        throw new RuntimeException('Active domain uniqueness constraint did not reject duplicate.');
    } catch (Illuminate\Database\UniqueConstraintViolationException) {
    }
    $domain->forceFill(['lifecycle_state' => App\Enums\DomainLifecycleState::Deprovisioning,
        'deprovision_after' => now()->subMinute()])->save();
    (new App\Jobs\FinalizeDomainDeprovisioning($domain->id))->handle();
    if (App\Models\Domain::query()->exists() || ! App\Models\DomainNameTombstone::query()->where('name', $domain->name)->exists()) {
        throw new RuntimeException('Finalization failed to preserve reclaim evidence.');
    }
    echo "constraints_and_finalization=passed\n";
} elseif ($mode === 'hold') {
    Illuminate\Support\Facades\DB::transaction(function (): void {
        App\Models\Domain::lockCanonicalName('locked.example.com');
        echo "locked\n"; flush();
        usleep(1500000);
    });
} elseif ($mode === 'idempotency') {
    $request = Illuminate\Http\Request::create('/api/qualification/mutation', 'POST', [], [], [], ['HTTP_IDEMPOTENCY_KEY' => $argv[2]]);
    $user = App\Models\User::findOrFail(1);
    $request->setUserResolver(fn () => $user);
    $response = (new App\Http\Middleware\IdempotentRequest)->handle($request, function () use ($user) {
        App\Models\AuditLog::record($user, 'qualification.idempotency');
        echo "entered\n"; flush();
        usleep(1500000);
        return response()->json(['data' => ['created' => true]], 201);
    });
    echo json_encode(['status' => $response->getStatusCode(), 'replayed' => $response->headers->get('Idempotency-Replayed'),
        'mutations' => App\Models\AuditLog::query()->where('action', 'qualification.idempotency')->count()])."\n";
}
'''


def main() -> None:
    identifier = 'cdnf-claim-qualification-' + uuid.uuid4().hex[:12]
    image = 'postgres@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15'
    with tempfile.TemporaryDirectory(prefix=identifier) as directory:
        root = Path(directory)
        script = root / 'qualification.php'
        script.write_text(PHP)
        for path in ('framework/cache', 'framework/sessions', 'framework/views', 'logs'):
            (root / 'storage' / path).mkdir(parents=True, exist_ok=True)
        subprocess.run(['docker', 'run', '-d', '--name', identifier, '--label', 'cdnfoundry.qualification=domain-claims',
                        '--tmpfs', '/var/lib/postgresql:rw,size=512m', '--memory', '512m', '--cpus', '1',
                        '-p', '127.0.0.1::5432', '-e', 'POSTGRES_PASSWORD=isolated-fixture-only',
                        '-e', 'POSTGRES_DB=cdnf_claim_qualification', image], check=True, capture_output=True)
        try:
            port = subprocess.check_output(['docker', 'port', identifier, '5432/tcp'], text=True).strip().rsplit(':', 1)[1]
            for _ in range(60):
                if subprocess.run(['docker', 'exec', identifier, 'pg_isready', '-U', 'postgres'], capture_output=True).returncode == 0:
                    break
                time.sleep(0.5)
            else:
                raise RuntimeError('Disposable PostgreSQL did not become ready')
            env = {**os.environ, 'APP_ENV': 'qualification', 'APP_KEY': 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                   'APP_CONFIG_CACHE': str(root / 'config.php'), 'DB_CONNECTION': 'pgsql', 'DB_URL': '',
                   'DB_HOST': '127.0.0.1', 'DB_PORT': port, 'DB_DATABASE': 'cdnf_claim_qualification',
                   'DB_USERNAME': 'postgres', 'DB_PASSWORD': 'isolated-fixture-only',
                   'CACHE_STORE': 'array', 'QUEUE_CONNECTION': 'sync', 'SESSION_DRIVER': 'array', 'LOG_CHANNEL': 'stderr',
                   'CDNF_QUALIFICATION_INSTANCE': identifier, 'CDNF_QUALIFICATION_ROOT': str(ROOT),
                   'CDNF_QUALIFICATION_STORAGE': str(root / 'storage')}
            def command(*args: str) -> list[str]:
                return ['php', str(script), *args]
            subprocess.run(command('init'), env=env, check=True, capture_output=True)
            processes = [subprocess.Popen(command('create', str(actor), name), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                         for actor, name in ((1, 'Race.Example.COM.'), (2, 'race.example.com'))]
            results = [process.communicate(timeout=30) for process in processes]
            assert all(process.returncode == 0 for process in processes), results
            assert sum(output.startswith('created:') for output, _ in results) == 1, results
            assert sum(output.startswith('rejected:') for output, _ in results) == 1, results
            subprocess.run(command('verify'), env=env, check=True, capture_output=True)
            reclaim = subprocess.check_output(command('create', '2', 'RACE.EXAMPLE.COM.'), env=env, text=True)
            assert 'reclaim cooldown' in reclaim
            holder = subprocess.Popen(command('hold'), env=env, stdout=subprocess.PIPE, text=True)
            assert holder.stdout.readline().strip() == 'locked'
            started = time.monotonic()
            result = subprocess.check_output(command('create', '2', 'locked.example.com'), env=env, text=True)
            waited = time.monotonic() - started
            assert result.startswith('created:') and waited >= 1.0
            assert holder.wait(timeout=10) == 0
            # Process-local array caches deliberately share no cache lease.
            # PostgreSQL must serialize the actual mutation and receipt alone.
            key = str(uuid.uuid4())
            first = subprocess.Popen(command('idempotency', key), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            assert first.stdout.readline().strip() == 'entered'
            contender = json.loads(subprocess.check_output(command('idempotency', key), env=env, text=True))
            assert contender['status'] == 409, contender
            output, error = first.communicate(timeout=15)
            assert first.returncode == 0, error
            assert json.loads(output)['mutations'] == 1
            replay = json.loads(subprocess.check_output(command('idempotency', key), env=env, text=True))
            assert replay == {'status': 201, 'replayed': 'true', 'mutations': 1}, replay
            interrupted_key = str(uuid.uuid4())
            interrupted = subprocess.Popen(command('idempotency', interrupted_key), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            assert interrupted.stdout.readline().strip() == 'entered'
            interrupted.kill()
            interrupted.communicate(timeout=10)
            retried = subprocess.check_output(command('idempotency', interrupted_key), env=env, text=True)
            assert json.loads(retried.splitlines()[-1]) == {'status': 201, 'replayed': None, 'mutations': 2}, retried
            sequence_waits = {}
            for action in ('applied', 'heartbeat'):
                subprocess.run(command('edge-init'), env=env, check=True, capture_output=True)
                reporter = subprocess.Popen(command('edge-report', action), env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                assert reporter.stdout.readline().strip() == 'loaded'
                writer = subprocess.Popen(command('edge-hold'), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                assert writer.stdout.readline().strip() == 'locked'
                started = time.monotonic()
                output, error = reporter.communicate(input='continue\n', timeout=15)
                assert reporter.returncode == 0, error
                sequence_waits[action] = round(time.monotonic() - started, 3)
                assert sequence_waits[action] >= 1.0
                assert json.loads(output) == {'status': 409 if action == 'applied' else 200, 'active_sequence': 42}, output
                writer.communicate(timeout=10)
                assert writer.returncode == 0
            subprocess.run(command('policy-init'), env=env, check=True, capture_output=True)
            policy_results = []
            for profile in ('standard', 'small', 'standard'):
                subprocess.run(command('policy-change', profile), env=env, check=True, capture_output=True)
                workers = [subprocess.Popen(command('policy-reconcile'), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
                for worker in workers:
                    output, error = worker.communicate(timeout=30)
                    assert worker.returncode == 0, error
                result = json.loads(subprocess.check_output(command('policy-reconcile'), env=env, text=True))
                policy_results.append(result)
                assert result['artifacts'][-1]['profile'] == profile, result
            assert [result['revision'] for result in policy_results] == [1, 2, 3], policy_results
            assert [len(result['artifacts']) for result in policy_results] == [1, 2, 3], policy_results
            assert policy_results[-1]['snapshots'] == 3
            assert policy_results[-1]['artifacts'][:2] == policy_results[1]['artifacts'], policy_results
            # Pause the real OpenSSL executable after the application has read
            # hostname coverage. A second PHP/PG process commits an actual DNS
            # controller mutation before the TLS upload may acquire its row lock.
            tls_env = {**env, 'CDNF_TLS_BUNDLE': str(root / 'tls-bundle.json'),
                       'CDNF_TLS_READY': str(root / 'tls-ready'), 'CDNF_TLS_RELEASE': str(root / 'tls-release')}
            subprocess.run(command('tls-init'), env=tls_env, check=True, capture_output=True)
            initial_tls = json.loads(subprocess.check_output(command('tls-state'), env=tls_env, text=True))
            assert set(initial_tls['names']) == {'api.example.test', 'www.example.test'}
            shim = root / 'bin'
            shim.mkdir()
            (shim / 'openssl').write_text('#!/bin/sh\nif [ "$1" = "verify" ]; then\n'
                '  touch "$CDNF_TLS_READY"\n  while [ ! -f "$CDNF_TLS_RELEASE" ]; do sleep 0.02; done\nfi\n'
                'exec /usr/bin/openssl "$@"\n')
            (shim / 'openssl').chmod(0o700)
            uploader = subprocess.Popen(command('tls-upload'), env={**tls_env, 'PATH': str(shim) + ':' + env['PATH']},
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                for _ in range(150):
                    if Path(tls_env['CDNF_TLS_READY']).exists():
                        break
                    assert uploader.poll() is None, 'TLS upload terminated before validation barrier'
                    time.sleep(.02)
                else:
                    raise AssertionError('TLS upload did not reach real OpenSSL validation')
                subprocess.run(command('tls-change'), env=tls_env, check=True, capture_output=True, timeout=2)
                changed_tls = json.loads(subprocess.check_output(command('tls-state'), env=tls_env, text=True))
                assert changed_tls['revision'] == initial_tls['revision'] + 1
                Path(tls_env['CDNF_TLS_RELEASE']).touch()
                output, error = uploader.communicate(timeout=10)
                assert uploader.returncode == 0, error
                final_tls = json.loads(subprocess.check_output(command('tls-state'), env=tls_env, text=True))
                assert json.loads(output)['status'] == 409, {'response': json.loads(output), 'before': changed_tls, 'after': final_tls}
                assert final_tls == changed_tls, final_tls
                assert final_tls['certificate_id'] == initial_tls['certificate_id']
                assert final_tls['certificate_count'] == 1
                assert final_tls['proxied_names'] == ['api.example.test', 'www.example.test']
                retried_tls = json.loads(subprocess.check_output(command('tls-upload'), env=tls_env, text=True))
                assert retried_tls['status'] == 422, retried_tls
            finally:
                Path(tls_env['CDNF_TLS_RELEASE']).touch()
                if uploader.poll() is None:
                    uploader.kill()
                uploader.communicate(timeout=10)
            managed_races = {}
            for change in ('custom', 'dns'):
                initialized = json.loads(subprocess.check_output(command('tls-managed-init'), env=tls_env, text=True))
                holder = subprocess.Popen(command('tls-managed-hold', change), env=tls_env, stdin=subprocess.PIPE,
                                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                worker = None
                try:
                    assert holder.stdout.readline().strip() == 'locked'
                    worker = subprocess.Popen(command('tls-managed-run'), env=tls_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                    for _ in range(60):
                        blocked = subprocess.check_output(['docker', 'exec', identifier, 'psql', '-U', 'postgres',
                            '-d', 'cdnf_claim_qualification', '-Atc',
                            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query LIKE '%domains%'"], text=True)
                        if int(blocked.strip()) > 0:
                            break
                        assert worker.poll() is None, 'Managed worker exited without contending on the domain'
                        time.sleep(.02)
                    else:
                        raise AssertionError('Managed worker did not contend on the domain row')
                    writer_output, writer_error = holder.communicate(input='continue\n', timeout=10)
                    assert holder.returncode == 0, writer_error
                    output, error = worker.communicate(timeout=15)
                    assert worker.returncode == 0, error
                    written = json.loads(writer_output)
                    actual = json.loads(output)
                    expected = written if change == 'custom' else {
                        **written, 'revision': written['revision'] + 1, 'active_tls_certificate_id': initialized['managed_id'],
                    }
                    managed_races[change] = {'writer': written, 'worker': actual, 'expected': expected, 'passed': actual == expected}
                finally:
                    if holder.poll() is None:
                        holder.kill()
                    holder.communicate(timeout=10)
                    if worker is not None:
                        if worker.poll() is None:
                            worker.kill()
                        worker.communicate(timeout=10)
            assert all(case['passed'] for case in managed_races.values()), managed_races
            digest = subprocess.check_output(['docker', 'image', 'inspect', image, '--format', '{{json .RepoDigests}}'], text=True).strip()
            print(json.dumps({'postgres_domain_claims': 'passed', 'instance': identifier, 'image_digests': json.loads(digest),
                              'concurrent_applicants': 2, 'active_configurations': 1, 'lock_wait_seconds': round(waited, 3),
                              'idempotency_concurrency_and_process_death': 'passed',
                              'edge_sequence_concurrent_wait_seconds': sequence_waits,
                              'pool_policy_restore_and_duplicate_workers': 'passed',
                              'tls_upload_concurrent_hostname_change': 'passed',
                              'tls_upload_revision_before_and_after_dns_change': [initial_tls['revision'], final_tls['revision']],
                              'tls_upload_retained_certificate_count': final_tls['certificate_count'],
                              'managed_tls_activation_concurrency': managed_races,
                              'database': 'disposable tmpfs, actual migrations; no PHPUnit or persistent volumes'}))
        finally:
            subprocess.run(['docker', 'rm', '-f', identifier], check=True, capture_output=True)


if __name__ == '__main__':
    main()
