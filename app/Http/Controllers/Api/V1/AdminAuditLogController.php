<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The trail of staff decisions, read by the platform admin.
 *
 * Read only on purpose: there is no route that edits or removes an entry.
 */
class AdminAuditLogController extends Controller
{
    /**
     * The newest decisions first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = config('nadara.clinic_timezone');

        $logs = AuditLog::query()
            ->with('user')
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()
            ))
            ->when(isset($filters['to']), fn ($query) => $query->where(
                'created_at',
                '<=',
                CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc()
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return AuditLogResource::collection($logs);
    }
}
