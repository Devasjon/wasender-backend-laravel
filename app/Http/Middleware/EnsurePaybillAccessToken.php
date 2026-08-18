<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the cloud-sync endpoint (/api/state). Paybill is a single-user
 * app with no login system, so instead of full authentication this just
 * checks a long-lived bearer token set in .env (APP_ACCESS_TOKEN) against
 * the same value Paybill's Settings → Cloud Sync page is configured with.
 *
 * If APP_ACCESS_TOKEN is not set in .env, every request is rejected — this
 * endpoint stores real bill/debt data, so it must not be left open by
 * accident.
 */
class EnsurePaybillAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.paybill.access_token');

        if (! $configured) {
            return response()->json([
                'error' => 'Server misconfigured: APP_ACCESS_TOKEN is not set in .env.',
            ], 500);
        }

        $provided = $request->bearerToken();

        if (! $provided || ! hash_equals($configured, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
