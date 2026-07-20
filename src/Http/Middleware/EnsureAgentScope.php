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

        // The message IS the documentation: it names the grant, the constraint,
        // and both recovery paths, so the CLI can surface it verbatim and the
        // driving agent needs no doc lookup to know what to do next.
        abort_unless(in_array($verb, (array) $session->scopes, true), 403,
            "Agent session is not scoped for '{$verb}' (granted: ".implode(', ', (array) $session->scopes).'). '
            .'Scopes are fixed at approval — either record the intended change in a note/batch for later, '
            .'or run dispatch:session:end and request a fresh session (requesting with no --scope asks for the full allowlist).');

        return $next($request);
    }
}
