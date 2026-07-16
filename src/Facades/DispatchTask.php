<?php

namespace Sgrjr\Dispatch\Facades;

use Illuminate\Support\Facades\Facade;
use Sgrjr\Dispatch\DispatchManager;

/**
 * Programmatic entry point for creating Dispatch tasks from code.
 *
 * @method static \Sgrjr\Dispatch\Models\Task|null report(string $title, array $options = [])
 * @method static \Sgrjr\Dispatch\Models\Task|null bug(string $title, array $options = [])
 * @method static \Sgrjr\Dispatch\Models\Task|null feature(string $title, array $options = [])
 * @method static \Sgrjr\Dispatch\Models\Task|null fromException(\Throwable $e, array $options = [])
 *
 * @see \Sgrjr\Dispatch\DispatchManager
 */
class DispatchTask extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DispatchManager::class;
    }
}
