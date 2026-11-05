<?php

namespace App\Console\Commands;

use App\Models\GeneratedReport;
use App\Support\Reports\OperationsReport;
use App\Support\Reports\Report;
use App\Support\Reports\UsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildReport extends Command
{
    protected $signature = 'cdnf:reports:build {kind : usage or operations} {--period= : YYYY-MM for usage, YYYY-Www for operations; default is the last finished one}';

    protected $description = 'Build a usage or operations report as CSV and PDF from rollups, and record it for download';

    public function handle(UsageReport $usage, OperationsReport $operations): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, ['usage', 'operations'], true)) {
            $this->error('The kind must be usage or operations.');

            return self::INVALID;
        }
        try {
            [$period, $report] = $kind === 'usage' ? $this->usage($usage) : $this->operations($operations);
        } catch (Throwable $exception) {
            $this->error('The report could not be built: '.$exception->getMessage());

            return self::FAILURE;
        }
        foreach (['csv' => $report->csv(), 'pdf' => $report->pdf()] as $format => $content) {
            $path = "reports/{$kind}-{$period}.{$format}";
            Storage::disk('local')->put($path, $content);
            GeneratedReport::query()->updateOrCreate(['kind' => $kind, 'period' => $period, 'format' => $format], ['path' => $path, 'size_bytes' => strlen($content), 'checksum_sha256' => hash('sha256', $content), 'created_at' => now()]);
        }
        $this->info("Built the {$kind} report for {$period}.");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: Report} */
    private function usage(UsageReport $usage): array
    {
        $month = $this->option('period') ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('period'), 'UTC') : CarbonImmutable::now('UTC')->subMonthNoOverflow();
        abort_if($month === false || $month->startOfMonth()->addMonth()->isFuture(), 422, 'Choose a month that has finished.');

        return [$month->format('Y-m'), $usage->forMonth($month)];
    }

    /** @return array{0: string, 1: Report} */
    private function operations(OperationsReport $operations): array
    {
        $week = $this->option('period') ? CarbonImmutable::parse((string) $this->option('period').'-1', 'UTC') : CarbonImmutable::now('UTC')->subWeek();
        abort_if($week->startOfWeek()->addWeek()->isFuture(), 422, 'Choose a week that has finished.');

        return [$week->format('o-\WW'), $operations->forWeek($week)];
    }
}
