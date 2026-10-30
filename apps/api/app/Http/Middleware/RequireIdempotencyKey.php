<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * POSTs that create money, stock or messages must carry an Idempotency-Key (B15 conventions).
 * A repeated key with the same body replays the first response; with a different body it is refused.
 * This is what stops a double-tap or a retry on a slow network from charging twice.
 */
class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) {
            return response()->json(['error' => ['code' => 'idempotency_key_required', 'message' => 'Send an Idempotency-Key header (16–100 characters).']], 400);
        }
        $hash = hash('sha256', $request->getContent());
        $userId = $request->user()?->id;
        $existing = DB::table('idempotency_records')->where(['scope' => $scope, 'key' => $key, 'user_id' => $userId])->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return response()->json(['error' => ['code' => 'idempotency_key_reused', 'message' => 'This key was already used for a different request.']], 409);
            }
            if ($existing->status_code !== null) {
                return response()->json(json_decode($existing->response, true), $existing->status_code, ['Idempotent-Replayed' => 'true']);
            }

            return response()->json(['error' => ['code' => 'in_progress', 'message' => 'The same request is still being processed.']], 409);
        }
        DB::table('idempotency_records')->insert(['scope' => $scope, 'key' => $key, 'user_id' => $userId, 'request_hash' => $hash,
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            DB::table('idempotency_records')->where(['scope' => $scope, 'key' => $key, 'user_id' => $userId])
                ->update(['status_code' => $response->getStatusCode(), 'response' => $response->getContent(), 'updated_at' => now()]);
        } else {
            DB::table('idempotency_records')->where(['scope' => $scope, 'key' => $key, 'user_id' => $userId])->delete();
        }

        return $response;
    }
}
