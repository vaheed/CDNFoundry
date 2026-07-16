<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return JsonResource::collection(AuditLog::query()->latest('id')->cursorPaginate(50));
    }
}
