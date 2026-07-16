<?php

namespace Sgrjr\Dispatch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * The unit of work behind the DispatchTask facade. It is ShouldQueue +
 * Dispatchable, so the manager runs it via dispatchSync() (immediate, returns
 * the Task) or dispatch() (queued, fire-and-forget) purely on config — the
 * canonical "always queueable, not always queued" pattern.
 *
 * All the domain logic (submitter/tenant/mint/labels/context, and signature
 * dedupe) lives in DispatchTaskService; this job is a thin, serializable shell.
 */
class CreateDispatchTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<int,string>  $labels
     */
    public function __construct(
        public array $attributes,
        public array $labels = [],
        public ?string $signature = null,
    ) {}

    public function handle(DispatchTaskService $service): ?Task
    {
        // A signature routes through capture() (dedupe + occurrence tracking);
        // otherwise it's a plain create.
        if ($this->signature !== null && $this->signature !== '') {
            return $service->capture($this->signature, $this->attributes, $this->labels);
        }

        return $service->create($this->attributes, $this->labels);
    }
}
