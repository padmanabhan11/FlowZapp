<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Ops\Metrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /internal/metrics — Prometheus text for the scraper (M4-T3). Not a
 * workspace endpoint: it is platform-wide, so it is protected by a bearer
 * token (METRICS_TOKEN) and disabled when none is set. Contains counts and
 * timings only, never content.
 */
final class MetricsController extends Controller
{
    public function __invoke(Request $request, Metrics $metrics): Response
    {
        $token = (string) config('services.metrics.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);
        $window = max(1, min(168, (int) $request->query('window_hours', '24')));

        return response($metrics->prometheus($window), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
