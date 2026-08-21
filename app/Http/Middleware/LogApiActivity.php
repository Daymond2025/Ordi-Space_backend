<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journal technique (perf/erreurs) de chaque appel API, distinct du
 * JOURNAL_AUDIT métier (qui, lui, n'enregistre que les actions sensibles
 * via des Observers Eloquent — voir app/Observers).
 */
class LogApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        Log::channel('stack')->info('api_request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
