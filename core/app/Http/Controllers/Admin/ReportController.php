<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeneratedReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => GeneratedReport::query()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50)]);
    }

    public function download(GeneratedReport $report): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($report->path), 404);

        return Storage::disk('local')->download($report->path, basename($report->path), ['Content-Type' => $report->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8', 'X-Content-SHA256' => $report->checksum_sha256]);
    }
}
