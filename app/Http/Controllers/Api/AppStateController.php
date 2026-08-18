<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cloud sync for Paybill's full app state (bills, debts, settings,
 * notification log) — the same JSON shape as the frontend's Export/Import
 * feature (src/lib/storage.ts, src/types.ts AppData), just fetchable/savable
 * from any device instead of living only in one browser's memory.
 *
 * Single-user app: everything is stored under owner = "default". Protected
 * by EnsurePaybillAccessToken (a shared bearer token), registered on the
 * 'paybill.token' middleware alias in bootstrap/app.php.
 */
class AppStateController extends Controller
{
    public function show(): JsonResponse
    {
        $state = AppState::where('owner', 'default')->first();

        if (! $state) {
            // Nothing synced yet — frontend falls back to its own sample
            // data / empty state and will create this on first save.
            return response()->json(['data' => null, 'updatedAt' => null]);
        }

        return response()->json([
            'data' => $state->data,
            'updatedAt' => $state->updated_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'array'],
        ]);

        $state = AppState::updateOrCreate(
            ['owner' => 'default'],
            ['data' => $validated['data']]
        );

        return response()->json([
            'ok' => true,
            'updatedAt' => $state->updated_at?->toIso8601String(),
        ]);
    }
}
