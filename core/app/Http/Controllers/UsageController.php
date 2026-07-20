<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\UsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageController extends Controller
{
    public function index(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        [$from, $to] = $this->range($request);
        $page = $this->query($domain, $from, $to)->cursorPaginate(100);

        return response()->json(['data' => $page, 'meta' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'units' => ['bandwidth' => 'bytes']]]);
    }

    public function export(Request $request, Domain $domain): JsonResponse|StreamedResponse
    {
        Gate::authorize('view', $domain);
        [$from, $to] = $this->range($request);
        $format = $request->validate(['format' => ['sometimes', 'in:json,csv']])['format'] ?? 'json';
        $query = $this->query($domain, $from, $to);
        if ($format === 'json') {
            return response()->json(['data' => $query->limit(10000)->get(), 'meta' => ['contract_version' => 1, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'units' => ['bandwidth' => 'bytes']]]);
        }

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['contract_version', 'domain_id', 'interval_start', 'interval_end', 'granularity', 'requests', 'bytes_in', 'bytes_out', 'cache_hits', 'dns_queries', 'status']);
            $query->lazyById(500)->each(fn (UsageRollup $row) => fputcsv($output, [1, $row->domain_id, $row->interval_start->toIso8601String(), $row->interval_end->toIso8601String(), $row->granularity, $row->requests, $row->bytes_in, $row->bytes_out, $row->cache_hits, $row->dns_queries, $row->status]));
            fclose($output);
        }, "usage-domain-{$domain->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function csv(Request $request, Domain $domain): StreamedResponse
    {
        $request->merge(['format' => 'csv']);

        return $this->export($request, $domain);
    }

    public function range(Request $request): array
    {
        $validated = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date']]);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->utc() : CarbonImmutable::now('UTC');
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->utc() : $to->subDays(30);
        abort_if($from >= $to || abs($to->diffInDays($from)) > 400, 422, 'Usage ranges must be positive and no longer than 400 days.');

        return [$from, $to];
    }

    public function query(?Domain $domain, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return UsageRollup::query()->when($domain !== null, fn (Builder $query): Builder => $query->where('domain_id', $domain->id))
            ->where('interval_start', '>=', $from)->where('interval_start', '<', $to)->orderBy('id');
    }
}
