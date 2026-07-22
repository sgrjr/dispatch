<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('dispatch.brand.name', 'Dispatch') }}</title>

    {{--
        Plain CSS driven by --dispatch-* variables (no Tailwind assumption —
        a host app re-themes this by overriding the :root block, e.g. in its
        own stylesheet loaded after this one, or by publishing+editing this
        view via `php artisan vendor:publish --tag=dispatch-views`).
    --}}
    <style>
        :root {
            --dispatch-bg: #c9c9c9;
            --dispatch-surface: #ffffff;
            --dispatch-surface-muted: #f8fafc;
            --dispatch-border: #a4a5a5;
            --dispatch-text: #0f172a;
            --dispatch-text-muted: #64748b;
            --dispatch-text-faint: #94a3b8;
            --dispatch-accent: #2a7f2e;
            --dispatch-accent-contrast: #ffffff;
            --dispatch-accent-hover: #59755a;
            --dispatch-danger: #dc2626;
            --dispatch-danger-bg: #fef2f2;
            --dispatch-warning: #d97706;
            --dispatch-warning-bg: #fffbeb;
            --dispatch-success: #059669;
            --dispatch-success-bg: #ecfdf5;
            --dispatch-info: #2563eb;
            --dispatch-info-bg: #eff6ff;
            --dispatch-radius-sm: 0.5rem;
            --dispatch-radius-md: 0.9rem;
            --dispatch-radius-lg: 1.25rem;
            --dispatch-radius-pill: 999px;
            --dispatch-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 1px rgba(15, 23, 42, 0.04);
            --dispatch-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --dispatch-bg: #0f172a;
                --dispatch-surface: #1e293b;
                --dispatch-surface-muted: #172033;
                --dispatch-border: #334155;
                --dispatch-text: #f1f5f9;
                --dispatch-text-muted: #94a3b8;
                --dispatch-text-faint: #64748b;
                --dispatch-accent: #fb923c;
                --dispatch-accent-contrast: #1c1917;
                --dispatch-accent-hover: #2a7f2e;
                --dispatch-danger: #f87171;
                --dispatch-danger-bg: #3f1d1d;
                --dispatch-warning: #fbbf24;
                --dispatch-warning-bg: #3f2f0f;
                --dispatch-success: #34d399;
                --dispatch-success-bg: #0f2e22;
                --dispatch-info: #60a5fa;
                --dispatch-info-bg: #0f2138;
            }
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--dispatch-bg);
            color: var(--dispatch-text);
            font-family: var(--dispatch-font);
            font-size: 14px;
            line-height: 1.5;
        }
        a { color: var(--dispatch-accent); text-decoration: none; }
        a:hover { color: var(--dispatch-accent-hover); text-decoration: underline; }

        .dispatch-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem 1rem 4rem;
        }
        .dispatch-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .dispatch-topbar h1 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.01em;
        }
        .dispatch-nav { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .dispatch-nav a {
            color: var(--dispatch-text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 0.4rem 0.75rem;
            border-radius: var(--dispatch-radius-pill);
        }
        .dispatch-nav a:hover, .dispatch-nav a.is-active {
            background: var(--dispatch-surface);
            color: var(--dispatch-accent);
            text-decoration: none;
            box-shadow: var(--dispatch-shadow);
        }
        /* Approval-queue pending-request counter — amber to read as
           attention-needed without the alarm of danger red. */
        .dispatch-nav-badge {
            display: inline-block;
            margin-left: 0.35rem;
            min-width: 1.15rem;
            padding: 0 0.35rem;
            font-size: 0.7rem;
            line-height: 1.5;
            text-align: center;
            font-weight: 700;
            border-radius: var(--dispatch-radius-pill);
            background: var(--dispatch-warning);
            color: var(--dispatch-accent-contrast);
        }

        /* Shared primitives used by the Livewire component views. */
        .dispatch-card {
            background: var(--dispatch-surface);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-lg);
            box-shadow: var(--dispatch-shadow);
            padding: 1.25rem;
        }
        .dispatch-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid transparent;
            border-radius: var(--dispatch-radius-pill);
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            cursor: pointer;
            background: var(--dispatch-accent);
            color: var(--dispatch-accent-contrast);
        }
        .dispatch-btn:hover { background: var(--dispatch-accent-hover); }
        .dispatch-btn.is-secondary {
            background: var(--dispatch-surface);
            color: var(--dispatch-text);
            border-color: var(--dispatch-border);
        }
        .dispatch-btn.is-secondary:hover { background: var(--dispatch-surface-muted); }
        .dispatch-btn:disabled { opacity: 0.6; cursor: not-allowed; }

        .dispatch-input, .dispatch-select, .dispatch-textarea {
            width: 100%;
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-sm);
            background: var(--dispatch-surface);
            color: var(--dispatch-text);
            padding: 0.5rem 0.65rem;
            font-size: 0.85rem;
            font-family: inherit;
        }
        .dispatch-input:focus, .dispatch-select:focus, .dispatch-textarea:focus {
            outline: 2px solid var(--dispatch-accent);
            outline-offset: 1px;
        }
        .dispatch-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--dispatch-text-muted);
            margin-bottom: 0.25rem;
        }

        .dispatch-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: var(--dispatch-radius-pill);
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 0.15rem 0.55rem;
            background: var(--dispatch-surface-muted);
            color: var(--dispatch-text-muted);
        }
        .dispatch-badge.is-blocker, .dispatch-badge.is-danger { background: var(--dispatch-danger-bg); color: var(--dispatch-danger); }
        .dispatch-badge.is-high, .dispatch-badge.is-warning { background: var(--dispatch-warning-bg); color: var(--dispatch-warning); }
        .dispatch-badge.is-medium { background: var(--dispatch-warning-bg); color: var(--dispatch-warning); }
        .dispatch-badge.is-low, .dispatch-badge.is-success { background: var(--dispatch-success-bg); color: var(--dispatch-success); }
        .dispatch-badge.is-info { background: var(--dispatch-info-bg); color: var(--dispatch-info); }
        /* Due-badge 'week' tier — warm attention, softer than is-warning
           (which the 'today' tier uses): warning wash with muted text. */
        .dispatch-badge.is-due-week { background: var(--dispatch-warning-bg); color: var(--dispatch-text-muted); }

        /* Checkbox multi-filter popovers (board + list filter bars — see
           livewire/partials/filter-group.blade.php). */
        .dispatch-filter-group { position: relative; }
        .dispatch-filter-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            cursor: pointer;
            list-style: none;
            user-select: none;
        }
        .dispatch-filter-summary::-webkit-details-marker { display: none; }
        .dispatch-filter-caret { font-size: 0.6rem; color: var(--dispatch-text-muted); }
        .dispatch-filter-panel {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            z-index: 30;
            min-width: 13rem;
            max-height: 16rem;
            overflow-y: auto;
            background: var(--dispatch-surface);
            border: 1px solid var(--dispatch-border);
            border-radius: var(--dispatch-radius-md);
            box-shadow: var(--dispatch-shadow);
            padding: 0.5rem 0.65rem;
        }
        .dispatch-filter-actions {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding-bottom: 0.35rem;
            margin-bottom: 0.35rem;
            border-bottom: 1px solid var(--dispatch-border);
            color: var(--dispatch-text-muted);
            font-size: 0.72rem;
        }
        .dispatch-filter-actions button {
            background: none;
            border: none;
            padding: 0;
            color: var(--dispatch-accent);
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
        }
        .dispatch-filter-option {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.78rem;
            padding: 0.18rem 0;
            cursor: pointer;
        }
        .dispatch-filter-empty { font-size: 0.75rem; color: var(--dispatch-text-muted); }

        .dispatch-error { color: var(--dispatch-danger); font-size: 0.75rem; font-weight: 600; margin-top: 0.25rem; }

        .dispatch-empty {
            text-align: center;
            color: var(--dispatch-text-muted);
            padding: 2.5rem 1rem;
            border: 1px dashed var(--dispatch-border);
            border-radius: var(--dispatch-radius-lg);
        }
    </style>
</head>
<body>
    <div class="dispatch-shell">
        <header class="dispatch-topbar">
            <h1>{{ config('dispatch.brand.name', 'Dispatch') }}</h1>
            <nav class="dispatch-nav">
                @php
                    // Show the approval-queue link only to staff, and only when the
                    // agent API is enabled and its route is registered. The badge
                    // surfaces pending requests so staff know one is waiting without
                    // having to hunt for the page.
                    $dispatchShowAgent = (bool) config('dispatch.agent.enabled', false)
                        && \Illuminate\Support\Facades\Route::has('dispatch.agent-sessions')
                        && app(\Sgrjr\Dispatch\Contracts\DispatchGate::class)->isStaff(auth()->user());
                    $dispatchAgentPending = $dispatchShowAgent ? \Sgrjr\Dispatch\Models\AgentSession::pendingCount() : 0;
                @endphp
                <a href="{{ route('dispatch.board') }}" @class(['is-active' => request()->routeIs('dispatch.board')])>Board</a>
                <a href="{{ route('dispatch.index') }}" @class(['is-active' => request()->routeIs('dispatch.index')])>List</a>
                <a href="{{ route('dispatch.create') }}" @class(['is-active' => request()->routeIs('dispatch.create')])>New</a>
                <a href="{{ route('dispatch.portal') }}" @class(['is-active' => request()->routeIs('dispatch.portal')])>My Submissions</a>
                @if ($dispatchShowAgent)
                    <a href="{{ route('dispatch.agent-sessions') }}" @class(['is-active' => request()->routeIs('dispatch.agent-sessions')])>
                        Agent Sessions
                        @if ($dispatchAgentPending > 0)
                            <span class="dispatch-nav-badge" title="{{ $dispatchAgentPending }} pending agent session request(s)">{{ $dispatchAgentPending }}</span>
                        @endif
                    </a>
                @endif
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>
    </div>

    {{--
        DECISION: DispatchWidget is documented as something a HOST app drops
        into ITS OWN layout, but since this package's own board/list/show/
        create/portal pages already render through this layout, including it
        here too means dispatch's own staff/submitters can report a bug about
        Dispatch itself without leaving the page. Gated by the same master
        switch a host app would use to disable it.
    --}}
    @auth
        @if (config('dispatch.widget.enabled', true))
            <livewire:dispatch-widget />
        @endif
    @endauth

    @livewireStyles
    @livewireScripts
    <script src="{{ asset('vendor/dispatch/dispatch.js') }}" defer></script>
</body>
</html>
