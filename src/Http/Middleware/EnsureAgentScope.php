<?php

namespace Sgrjr\Dispatch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-verb scope gate for the agent surface. Applied declaratively per route
 * (`dispatch.agent.scope:claim`) so a new verb can't ship un-gated. 403 (not
 * 401): the token is valid, the verb is simply outside this session's grant.
 *
 * Runs AFTER AuthenticateAgentSession, which binds the session attribute.
 */
class EnsureAgentScope
{
    public function handle(Request $request, Closure $next, string $verb): Response
    {
        $session = $request->attributes->get(AuthenticateAgentSession::ATTRIBUTE);

        // Belt-and-suspenders: a mis-ordered/forgotten auth middleware self-denies
        // rather than running as an un-scoped principal.
        abort_if($session === null, 401);

        abort_unless(in_array($verb, (array) $session->scopes, true), 403, "Agent session is not scoped for '{$verb}'.");

        return $next($request);
    }
}
