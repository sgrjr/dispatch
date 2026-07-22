<div>
    <style>
        .dispatch-agent-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 0;
            border-bottom: 1px solid var(--dispatch-border);
        }
        .dispatch-agent-row:last-child { border-bottom: none; }
        .dispatch-agent-meta { font-size: 0.78rem; color: var(--dispatch-text-muted); margin-top: 0.35rem; }
        .dispatch-agent-code {
            display: inline-block;
            margin-top: 0.5rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.15em;
            padding: 0.35rem 0.75rem;
            background: var(--dispatch-surface-muted);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-sm);
            color: var(--dispatch-text);
        }
        .dispatch-agent-confirm { font-size: 0.72rem; color: var(--dispatch-text-muted); margin-top: 0.3rem; }
        .dispatch-agent-actions { display: flex; gap: 0.5rem; align-items: flex-start; }
        .dispatch-agent-ttl { display: flex; flex-direction: column; gap: 0.2rem; }
        .dispatch-agent-ttl label { font-size: 0.72rem; color: var(--dispatch-text-muted); }
        .dispatch-agent-ttl .dispatch-select { width: auto; font-size: 0.78rem; padding: 0.35rem 0.5rem; }
    </style>

    <section class="dispatch-card">
        <h2 style="margin:0 0 0.25rem; font-size:1rem;">Pending requests</h2>
        <p class="dispatch-agent-meta" style="margin-top:0;">
            A remote agent asked to work this backlog. Only approve a request if you initiated it — check that
            the code below matches exactly what the requesting agent displayed to you.
        </p>

        @if ($pending->isEmpty())
            <div class="dispatch-empty">No pending agent session requests.</div>
        @else
            @foreach ($pending as $session)
                <div class="dispatch-agent-row" wire:key="pending-{{ $session->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <span class="dispatch-list-title">{{ $session->agent_name }}</span>
                            <span class="dispatch-badge is-info">pending</span>
                        </div>
                        @if ($session->purpose)
                            <div class="dispatch-agent-meta">{{ $session->purpose }}</div>
                        @endif
                        <div class="dispatch-agent-confirm">Did you initiate this? Confirm this matches the code the requesting agent displayed:</div>
                        <div class="dispatch-agent-code">{{ $session->user_code }}</div>
                        <div class="dispatch-agent-meta">
                            ip: {{ $session->ip ?? 'unknown' }} &middot; requested {{ $session->created_at?->diffForHumans() }}
                        </div>
                    </div>
                    <div class="dispatch-agent-actions">
                        <div class="dispatch-agent-ttl">
                            <label for="ttl-{{ $session->id }}">session length</label>
                            <select id="ttl-{{ $session->id }}" wire:model="approveTtl.{{ $session->id }}" class="dispatch-select">
                                <option value="">Default ({{ round(((int) config('dispatch.agent.session_ttl', 10800)) / 3600, 1) }}h)</option>
                                <option value="3600">1 hour</option>
                                <option value="10800">3 hours</option>
                                <option value="28800">8 hours</option>
                                <option value="86400">24 hours</option>
                            </select>
                        </div>
                        <button type="button" wire:click="approve({{ $session->id }})" wire:confirm="Approve this agent session?" class="dispatch-btn">Approve</button>
                        <button type="button" wire:click="deny({{ $session->id }})" class="dispatch-btn is-secondary">Deny</button>
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    <section class="dispatch-card" style="margin-top: 1rem;">
        <h2 style="margin:0 0 0.5rem; font-size:1rem;">Active sessions</h2>

        @if ($active->isEmpty())
            <div class="dispatch-empty">No active agent sessions.</div>
        @else
            @foreach ($active as $session)
                <div class="dispatch-agent-row" wire:key="active-{{ $session->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <span class="dispatch-list-title">{{ $session->agent_name }}</span>
                            <span class="dispatch-badge is-success">approved</span>
                        </div>
                        <div class="dispatch-agent-meta">
                            approved {{ $session->approved_at?->diffForHumans() }}
                            &middot; expires {{ $session->expires_at?->diffForHumans() }}
                        </div>
                        @php($m = $metrics[$session->id] ?? ['worked' => 0, 'with_metrics' => 0])
                        @if ($m['worked'] > 0 && $m['with_metrics'] === 0)
                            <div style="margin-top:0.4rem;">
                                <span class="dispatch-badge is-info" title="This session closed {{ $m['worked'] }} task(s) with no per-task --with-metrics yet — session totals are recorded automatically at dispatch:session:end.">metrics: pending session end</span>
                            </div>
                        @elseif ($m['with_metrics'] > 0)
                            <div style="margin-top:0.4rem;">
                                <span class="dispatch-badge is-success" title="Agent-run metrics captured on {{ $m['with_metrics'] }} of {{ $m['worked'] }} closed task(s).">metrics &check; {{ $m['with_metrics'] }}/{{ $m['worked'] }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="dispatch-agent-actions">
                        <button type="button" wire:click="revoke({{ $session->id }})" wire:confirm="Revoke this agent session?" class="dispatch-btn is-secondary">Revoke</button>
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    {{-- Recently ended — where the session-anchored metrics verdict lives. The
         run summary is stamped by dispatch:session:end onto the session row; an
         ended session that closed work but carries no metrics is the real
         "none recorded" signal (on an ACTIVE row it can only ever be pending). --}}
    <section class="dispatch-card" style="margin-top: 1rem;">
        <h2 style="margin:0 0 0.5rem; font-size:1rem;">Recently ended</h2>

        @if ($ended->isEmpty())
            <div class="dispatch-empty">No ended agent sessions yet.</div>
        @else
            @foreach ($ended as $session)
                <div class="dispatch-agent-row" wire:key="ended-{{ $session->id }}">
                    <div style="min-width:0; flex:1;">
                        <div>
                            <span class="dispatch-list-title">{{ $session->agent_name }}</span>
                            <span class="dispatch-badge">{{ $session->status === \Sgrjr\Dispatch\Models\AgentSession::STATUS_EXPIRED ? 'expired' : 'ended' }}</span>
                        </div>
                        <div class="dispatch-agent-meta">
                            approved {{ $session->approved_at?->diffForHumans() }}
                            &middot; ended {{ ($session->ended_at ?? $session->expires_at ?? $session->updated_at)?->diffForHumans() }}
                        </div>
                        @php($m = $metrics[$session->id] ?? ['worked' => 0, 'with_metrics' => 0])
                        @if (is_array($session->metrics) && $session->metrics !== [])
                            <div style="margin-top:0.4rem;">
                                <span class="dispatch-badge is-success" title="Whole-session run metrics recorded at dispatch:session:end.">session metrics &check;</span>
                                @if ($m['with_metrics'] > 0)
                                    <span class="dispatch-badge is-success" title="Agent-run metrics captured on {{ $m['with_metrics'] }} of {{ $m['worked'] }} closed task(s).">tasks &check; {{ $m['with_metrics'] }}/{{ $m['worked'] }}</span>
                                @endif
                            </div>
                            <div class="dispatch-agent-meta">{{ \Sgrjr\Dispatch\Support\AgentMetrics::summaryLine($session->metrics) }}</div>
                        @elseif ($m['worked'] > 0 && $m['with_metrics'] === 0)
                            <div style="margin-top:0.4rem;">
                                <span class="dispatch-badge is-warning" title="This session closed {{ $m['worked'] }} task(s) but recorded no run metrics — session:end ran with --no-metrics or could not locate a transcript, and no dispatch:done carried --with-metrics.">metrics: none recorded</span>
                            </div>
                        @elseif ($m['with_metrics'] > 0)
                            <div style="margin-top:0.4rem;">
                                <span class="dispatch-badge is-success" title="Agent-run metrics captured on {{ $m['with_metrics'] }} of {{ $m['worked'] }} closed task(s).">tasks &check; {{ $m['with_metrics'] }}/{{ $m['worked'] }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </section>
</div>
