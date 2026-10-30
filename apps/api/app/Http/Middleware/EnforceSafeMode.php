<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** When the integrity check fails, refuse every write until a verified release is redeployed (SEC-INT-041). */
class EnforceSafeMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && Cache::get('integrity_safe_mode')) {
            return response()->json(['error' => ['code' => 'safe_mode', 'message' => 'The system is in read-only safe mode after a failed integrity check. The owner has been alerted.']], 503);
        }

        return $next($request);
    }
}
