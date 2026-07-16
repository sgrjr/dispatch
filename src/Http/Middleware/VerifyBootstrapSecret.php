<?php

namespace Sgrjr\Dispatch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse gate on the UNAUTHENTICATED session-request endpoint (§20 fork 3).
 *
 * An open `POST agent/session` on production is a spam + social-engineering
 * vector (the approval queue is itself an attack surface). A configured
 * `bootstrap_secret` must match the `X-Dispatch-Bootstrap` header (constant-time).
 *
 * Fail-closed in production: if no secret is configured there, refuse (503) and
 * warn loudly — a silent open endpoint on prod is the trap. On non-production
 * (local/testing) an unset secret leaves the endpoint open so dev/CI works;
 * set the secret explicitly to opt into enforcement everywhere.
 */
class VerifyBootstrapSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('dispatch.agent.bootstrap_secret');

        if ($configured === null || $configured === false || $configured === '') {
            if (app()->environment('production')) {
                Log::warning('Dispatch: agent session request endpoint has no bootstrap_secret configured in production; refusing.');
                abort(503, 'Agent session requests are not configured.');
            }

            return $next($request);
        }

        $provided = (string) $request->header('X-Dispatch-Bootstrap', '');

        abort_unless(hash_equals((string) $configured, $provided), 401, 'Invalid bootstrap secret.');

        return $next($request);
    }
}
