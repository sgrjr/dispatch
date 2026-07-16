<?php

namespace Sgrjr\Dispatch\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Sgrjr\Dispatch\DispatchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            DispatchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Needed once tests exercise HTTP routes through the `web` middleware
        // group (session/cookie encryption). Fixed key — tests only.
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use the in-memory disk for attachment tests by default.
        $app['config']->set('dispatch.attachments.disk', 'local');
    }
}
