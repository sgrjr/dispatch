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
        $this->app->singleton(DispatchGate::class, fn ($app) => $app->make(config('dispatch.contracts.gate')));
        $this->app->singleton(TenantResolver::class, fn ($app) => $app->make(config('dispatch.contracts.tenant')));
        $this->app->singleton(SubmitterResolver::class, fn ($app) => $app->make(config('dispatch.contracts.submitter')));
        $this->app->singleton(DispatchNotifier::class, fn ($app) => $app->make(config('dispatch.contracts.notifier')));

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
    }
}
