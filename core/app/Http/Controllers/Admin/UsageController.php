<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UsageController as DomainUsageController;
use App\Jobs\BuildUsageRollups;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageController extends Controller
{
    public function index(Request $request, DomainUsageController $usage): JsonResponse
    {
        [$from, $to] = $usage->range($request);

        return response()->json(['data' => $usage->query(null, $from, $to)->cursorPaginate(100), 'meta' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'units' => ['bandwidth' => 'bytes']]]);
    }

    public function export(Request $request, DomainUsageController $usage): JsonResponse|StreamedResponse
    {
        [$from, $to] = $usage->range($request);
        $format = $request->validate(['format' => ['sometimes', 'in:json,csv']])['format'] ?? 'json';
        $query = $usage->query(null, $from, $to);
        if ($format === 'json') {
            return response()->json(['data' => $query->limit(10000)->get(), 'meta' => ['contract_version' => 1, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]]);
        }

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['contract_version', 'domain_id', 'interval_start', 'interval_end', 'granularity', 'requests', 'bytes_in', 'bytes_out', 'cache_hits', 'dns_queries', 'status']);
            $query->lazyById(500)->each(fn ($row) => fputcsv($output, [1, $row->domain_id, $row->interval_start->toIso8601String(), $row->interval_end->toIso8601String(), $row->granularity, $row->requests, $row->bytes_in, $row->bytes_out, $row->cache_hits, $row->dns_queries, $row->status]));
            fclose($output);
        }, 'usage-all-domains.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function csv(Request $request, DomainUsageController $usage): StreamedResponse
    {
        $request->merge(['format' => 'csv']);

        return $this->export($request, $usage);
    }

    public function rebuild(Request $request): JsonResponse
    {
        $data = $request->validate(['domain_id' => ['sometimes', 'integer', 'exists:domains,id'], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        abort_if($from >= $to || abs($to->diffInDays($from)) > 31 || ! $from->isStartOfHour() || ! $to->isStartOfHour(), 422, 'Rebuild ranges must use complete UTC hours and span at most 31 days.');
        $operation = Operation::query()->create(['actor_id' => $request->user()->id, 'type' => 'usage.rebuild', 'status' => 'pending', 'input' => ['domain_id' => $data['domain_id'] ?? null, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]]);
        BuildUsageRollups::dispatch($from->toIso8601String(), $to->toIso8601String(), $data['domain_id'] ?? null, $operation->id)->afterCommit();

        return response()->json(['data' => ['operation_id' => $operation->id, 'status' => 'pending']], 202);
    }
}
