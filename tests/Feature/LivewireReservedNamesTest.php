<?php

use Livewire\Component;

/*
 * Livewire 3's `$wire` proxy resolves an `aliases` map BEFORE it falls back to
 * looking for a component method (livewire/livewire 3.7.3,
 * dist/livewire.esm.js:8209-8240). A component action sharing one of those
 * names is therefore UNREACHABLE from the browser: `wire:click="watch"`
 * resolved to Livewire's own `$watch(path, callback)`, Alpine invoked it with
 * no arguments, and `dataGet(reactive, undefined)` threw
 *
 *     Cannot read properties of undefined (reading 'split')
 *
 * The trap is that this is invisible to the PHP suite — `Livewire::test()
 * ->call('watch')` calls the method directly and passes, which is exactly how
 * the Watch button shipped broken. So this guard is static: it reads method
 * names and blade bindings rather than exercising behavior.
 *
 * Not reserved and safe to use: unwatch, refresh, and anything `$`-prefixed
 * only (e.g. `$refresh` has no bare alias).
 */

const DISPATCH_WIRE_RESERVED = [
    'on', 'el', 'id', 'js', 'get', 'set', 'call', 'hook', 'commit', 'watch',
    'entangle', 'dispatch', 'dispatchTo', 'dispatchSelf', 'upload',
    'uploadMultiple', 'removeUpload', 'cancelUpload',
];

test('no Livewire component action collides with a reserved $wire alias', function () {
    $offenders = [];

    foreach (glob(__DIR__.'/../../src/Livewire/*.php') as $file) {
        $class = 'Sgrjr\\Dispatch\\Livewire\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Only methods the component itself declares are callable actions;
            // inherited Livewire internals are not ours to rename.
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($method->getName(), DISPATCH_WIRE_RESERVED, true)) {
                $offenders[] = $class.'::'.$method->getName().'()';
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('no blade wire: binding targets a reserved $wire alias', function () {
    $offenders = [];
    $pattern = '/wire:(?:click|submit|change|keydown|keyup|input|blur|focus|mouseenter|mouseleave|target)'
        .'(?:\.[a-z.]+)?="([a-zA-Z_][a-zA-Z0-9_]*)(?:\(|")/';

    $views = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
    );

    foreach ($views as $view) {
        if ($view->getExtension() !== 'php') {
            continue;
        }

        preg_match_all($pattern, file_get_contents($view->getPathname()), $matches);

        foreach ($matches[1] as $action) {
            if (in_array($action, DISPATCH_WIRE_RESERVED, true)) {
                $offenders[] = basename($view->getPathname()).' → '.$action;
            }
        }
    }

    expect($offenders)->toBe([]);
});
