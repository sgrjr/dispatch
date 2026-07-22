<?php

namespace Sgrjr\Dispatch;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Contracts\TenantResolver;
use Sgrjr\Dispatch\Policies\TaskPolicy;

class DispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dispatch.php', 'dispatch');

        // Bind the four portability seams to the app-configured implementations.
        // Each read carries its shipped default as an in-code fallback: a host
        // that published config/dispatch.php BEFORE a seam existed has no key for
        // it (mergeConfigFrom shallow-merges, so the host's `contracts` array
        // wins wholesale). Without the fallback, config() returns null and
        // app->make(null) throws "Target class [] does not exist" — the exact
        // failure the `notifier` seam hit on hosts predating it.
        $this->app->singleton(DispatchGate::class, fn ($app) => $app->make(config('dispatch.contracts.gate', \Sgrjr\Dispatch\Support\DefaultGate::class)));
        $this->app->singleton(TenantResolver::class, fn ($app) => $app->make(config('dispatch.contracts.tenant', \Sgrjr\Dispatch\Support\NullTenantResolver::class)));
        $this->app->singleton(SubmitterResolver::class, fn ($app) => $app->make(config('dispatch.contracts.submitter', \Sgrjr\Dispatch\Support\AuthSubmitterResolver::class)));
        $this->app->singleton(DispatchNotifier::class, fn ($app) => $app->make(config('dispatch.contracts.notifier', \Sgrjr\Dispatch\Support\MailNotifier::class)));

        // Backs the DispatchTask facade (programmatic reporting).
        $this->app->singleton(DispatchManager::class);
    }

    public function boot(): void
    {
        // Stable morph aliases so polymorphic attachments survive an app
        // subclassing Task/TaskComment (the config points the alias at whatever
        // concrete class the app uses). Use morphMap (merge, non-enforcing) NOT
        // enforceMorphMap — a package must never flip requireMorphMap on for its
        // host app, which would break the host's own unmapped polymorphic models.
        Relation::morphMap([
            'dispatch_task' => config('dispatch.models.task'),
            'dispatch_comment' => config('dispatch.models.task_comment'),
        ]);

        Gate::policy(config('dispatch.models.task'), TaskPolicy::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dispatch');

        $this->registerRateLimiters();
        $this->registerAgentMiddleware();
        $this->registerRoutes();
        $this->registerLivewireComponents();
        $this->registerCommands();
        $this->registerPublishing();
    }

    /**
     * A named 'dispatch-capture' rate limiter for the capture + attachment-upload
     * endpoints. Reads `dispatch.capture.throttle` at REQUEST time (not route-
     * registration time) so a host — or a test — can flip it without re-booting
     * routes: null/false/'' = unlimited; a limiter string like '60,1' (60/min)
     * or ['max'=>60,'per'=>1]. Keyed per authenticated user, falling back to IP.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('dispatch-capture', function ($request) {
            $cfg = config('dispatch.capture.throttle', '60,1');

            if ($cfg === null || $cfg === false || $cfg === '') {
                return Limit::none();
            }

            if (is_array($cfg)) {
                $max = (int) ($cfg['max'] ?? 60);
                $per = (int) ($cfg['per'] ?? 1);
            } else {
                $parts = explode(',', (string) $cfg);
                $max = (int) ($parts[0] ?? 60);
                $per = (int) ($parts[1] ?? 1);
            }

            $key = optional($request->user())->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinutes(max(1, $per), max(1, $max))->by('dispatch-capture:'.$key);
        });

        // Agent API limiters (§20). Config read at request time (same as above).
        // The unauthenticated request endpoint is keyed by IP; the verb endpoints
        // by the session's public_id (agents may share an IP), falling back to IP
        // before the session is bound.
        RateLimiter::for('dispatch-agent-request', function ($request) {
            return $this->configuredLimit(config('dispatch.agent.request_throttle', '10,1'), 'dispatch-agent-request:'.$request->ip());
        });

        RateLimiter::for('dispatch-agent-verb', function ($request) {
            $session = $request->attributes->get(\Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession::ATTRIBUTE);
            $key = ($session?->public_id) ?: $request->ip();

            return $this->configuredLimit(config('dispatch.agent.verb_throttle', '120,1'), 'dispatch-agent-verb:'.$key);
        });
    }

    /**
     * Parse a limiter config value (null/false/'' = unlimited; 'max,per' string;
     * or ['max'=>, 'per'=>]) into a Limit keyed by $key.
     */
    protected function configuredLimit(mixed $cfg, string $key): Limit
    {
        if ($cfg === null || $cfg === false || $cfg === '') {
            return Limit::none();
        }

        if (is_array($cfg)) {
            $max = (int) ($cfg['max'] ?? 60);
            $per = (int) ($cfg['per'] ?? 1);
        } else {
            $parts = explode(',', (string) $cfg);
            $max = (int) ($parts[0] ?? 60);
            $per = (int) ($parts[1] ?? 1);
        }

        return Limit::perMinutes(max(1, $per), max(1, $max))->by($key);
    }

    /**
     * Alias the agent-surface middleware BEFORE routes are registered so
     * routes/agent.php can reference them by name.
     */
    protected function registerAgentMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('dispatch.agent', \Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession::class);
        $router->aliasMiddleware('dispatch.agent.scope', \Sgrjr\Dispatch\Http\Middleware\EnsureAgentScope::class);
        $router->aliasMiddleware('dispatch.agent.bootstrap', \Sgrjr\Dispatch\Http\Middleware\VerifyBootstrapSecret::class);
    }

    protected function registerRoutes(): void
    {
        if (! config('dispatch.routes.enabled', true)) {
            return;
        }

        // Web routes point at the Livewire UI; only register them once that UI
        // exists (keeps the package bootable before the UI lands, and lets an
        // app run headless — CLI + sync only — without the components).
        $web = __DIR__.'/../routes/web.php';
        if (file_exists($web) && class_exists(\Sgrjr\Dispatch\Livewire\TaskList::class)) {
            $this->app['router']
                ->prefix(config('dispatch.routes.prefix', 'dispatch'))
                ->middleware(config('dispatch.routes.middleware', ['web', 'auth']))
                ->name(config('dispatch.routes.name_prefix', 'dispatch.'))
                ->group($web);
        }

        $api = __DIR__.'/../routes/api.php';
        if (file_exists($api) && class_exists(\Sgrjr\Dispatch\Http\Controllers\SyncController::class)) {
            $this->app['router']
                ->prefix('api/'.config('dispatch.routes.prefix', 'dispatch'))
                ->middleware(config('dispatch.routes.api_middleware', ['api', 'auth:sanctum']))
                ->name(config('dispatch.routes.name_prefix', 'dispatch.').'api.')
                ->group($api);
        }

        // Dedicated agent API (§20) — a SEPARATE group with its own posture.
        // Off unless explicitly enabled AND its controllers exist (they land in
        // Wave 1); per-route security middleware is declared in routes/agent.php.
        $agent = __DIR__.'/../routes/agent.php';
        // env() fallback so the master switch survives a stale published config
        // that predates the `agent` block (GAP-3): a host with DISPATCH_AGENT=true
        // shouldn't silently lose the whole agent surface for not re-publishing.
        if (config('dispatch.agent.enabled', env('DISPATCH_AGENT', false))
            && file_exists($agent)
            && class_exists(\Sgrjr\Dispatch\Http\Controllers\AgentSessionController::class)) {
            $this->app['router']
                ->prefix('api/'.config('dispatch.routes.prefix', 'dispatch').'/agent')
                ->middleware(config('dispatch.agent.middleware', ['api']))
                ->name(config('dispatch.routes.name_prefix', 'dispatch.').'api.agent.')
                ->group($agent);
        }
    }

    /**
     * Register Livewire components. Guarded so the package boots before the
     * Wave-1 UI classes land, and skipped entirely if Livewire is absent.
     */
    protected function registerLivewireComponents(): void
    {
        if (! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        $components = [
            'dispatch-board' => \Sgrjr\Dispatch\Livewire\TaskBoard::class,
            'dispatch-list' => \Sgrjr\Dispatch\Livewire\TaskList::class,
            'dispatch-show' => \Sgrjr\Dispatch\Livewire\TaskShow::class,
            'dispatch-create' => \Sgrjr\Dispatch\Livewire\TaskCreate::class,
            'dispatch-thread' => \Sgrjr\Dispatch\Livewire\TaskThread::class,
            'dispatch-widget' => \Sgrjr\Dispatch\Livewire\DispatchWidget::class,
            'dispatch-my-submissions' => \Sgrjr\Dispatch\Livewire\MySubmissions::class,
            // §20 Phase 3 — the staff "Agent Sessions" approval queue (Wave 1).
            'dispatch-agent-sessions' => \Sgrjr\Dispatch\Livewire\AgentSessions::class,
            // Focus steering panel (roadmap W8-2) — ships in a later wave; the
            // foreach's class_exists guard keeps the provider booting until then.
            'dispatch-focus-panel' => \Sgrjr\Dispatch\Livewire\FocusPanel::class,
        ];

        foreach ($components as $alias => $class) {
            if (class_exists($class)) {
                \Livewire\Livewire::component($alias, $class);
            }
        }
    }

    /**
     * Register console commands. Guarded by class_exists so the foundation boots
     * before the Wave-1 command classes exist; they auto-register once added.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $commands = [
            \Sgrjr\Dispatch\Console\Commands\DispatchAdd::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchNext::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchQueue::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchShow::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchNote::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchDone::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchPull::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchPush::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchExport::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchImport::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchEdit::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchMerge::class,
            // Agent layer (Wave 1) — auto-register once the classes land.
            \Sgrjr\Dispatch\Console\Commands\DispatchClaim::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchBatch::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSchema::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSessionRequest::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSessionStatus::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSessionRefresh::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSessionEnd::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchSessionsPrune::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchMetrics::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchMetricsCapture::class,
            \Sgrjr\Dispatch\Console\Commands\DispatchDoctor::class,
        ];

        $this->commands(array_filter($commands, 'class_exists'));
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dispatch.php' => config_path('dispatch.php'),
        ], 'dispatch-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'dispatch-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dispatch'),
        ], 'dispatch-views');

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/dispatch'),
        ], 'dispatch-assets');

        // The publishable Vue capture widget for Inertia/Vue (or any JS) hosts.
        // The app copies it and places it in a layout present on every page.
        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/dispatch'),
        ], 'dispatch-vue');

        // Claude Code skills for the dispatch/agent verb loops. Claude Code
        // discovers skills from the PROJECT's own .claude/skills — never from
        // vendor/ — so a host must copy them in. `vendor:publish --tag=dispatch-skills`
        // does the copy; re-run with --force to re-sync after a package upgrade.
        // Without --force, existing files are skipped, so a host that has already
        // customized the agent-session skill (e.g. with its own prod host/paths)
        // keeps its version while still picking up dispatch-track.
        $this->publishes([
            __DIR__.'/../.claude/skills/dispatch-track' => base_path('.claude/skills/dispatch-track'),
            __DIR__.'/../.claude/skills/dispatch-agent-session' => base_path('.claude/skills/dispatch-agent-session'),
            __DIR__.'/../.claude/skills/dispatch-batch-migrate' => base_path('.claude/skills/dispatch-batch-migrate'),
        ], 'dispatch-skills');
    }
}
