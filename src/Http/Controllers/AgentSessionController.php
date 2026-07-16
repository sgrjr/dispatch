<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sgrjr\Dispatch\Services\AgentSessionService;

/**
 * Session commissioning (§20 Phase 1) — UNAUTHENTICATED endpoints, gated by
 * the `dispatch.agent.bootstrap` middleware declared in routes/agent.php (not
 * rechecked here). An agent REQUESTS a session here; a human approves it via
 * the AgentSessions Livewire queue; the agent POLLS here until it receives a
 * one-time bearer token.
 */
class AgentSessionController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $v = $request->validate([
            'agent_name' => ['required', 'string', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'meta' => ['nullable', 'array'],
        ]);

        $payload = app(AgentSessionService::class)->request(
            $v['agent_name'],
            $v['purpose'] ?? null,
            array_filter(['scopes' => $v['scopes'] ?? null] + ($v['meta'] ?? [])),
            $request->ip(),
        );

        return response()->json($payload, 201);
    }

    public function poll(Request $request, string $publicId): JsonResponse
    {
        $r = app(AgentSessionService::class)->poll($publicId, (string) $request->query('device_code', ''));

        if ($r['status'] === 'invalid') {
            abort(404);
        }

        return response()->json($r);
    }
}
