<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'filter' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (
            isset($filters['from'], $filters['to']) &&
            $filters['from'] > $filters['to']
        ) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be on or after the start date.'],
            ]);
        }

        $query = AuditLog::query()
            ->with('user:id,name,email');

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $filter = trim($filters['filter'] ?? '');

        if ($filter !== '') {
            $query->where(function ($search) use ($filter) {
                $search
                    ->where('event', 'like', "%{$filter}%")
                    ->orWhere('description', 'like', "%{$filter}%")
                    ->orWhere('ip_address', 'like', "%{$filter}%")
                    ->orWhereHas('user', function ($userQuery) use ($filter) {
                        $userQuery
                            ->where('name', 'like', "%{$filter}%")
                            ->orWhere('email', 'like', "%{$filter}%");
                    });
            });
        }

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }
}