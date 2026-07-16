<?php

namespace Sgrjr\Dispatch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a remote agent by its session bearer token (§20).
 *
 * The principal is the AgentSession itself (not a User). Bind it onto the
 * request as an attribute — NOT a container singleton, which would bleed across
 * requests under Octane/queue workers. A missing/expired/revoked token yields a
 * uniform 401 (no oracle distinguishing the three).
 */
class AuthenticateAgentSession
{
    public const ATTRIBUTE = 'dispatch.agent_session';

    public function __construct(protected AgentSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->resolveToken($request->bearerToken());

        abort_if($session === null, 401, 'Invalid or expired agent session.');

        $request->attributes->set(self::ATTRIBUTE, $session);
        $session->markUsed();

        return $next($request);
    }
}
