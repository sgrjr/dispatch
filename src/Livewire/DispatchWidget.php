<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Embedded floating "report a bug / suggest a feature" widget:
 * `<livewire:dispatch-widget />` dropped into a HOST app's layout. Any
 * authenticated user may open it. Creates tasks via DispatchTaskService with
 * the `source:widget` label, in `triage` for staff to sort.
 *
 * DECISION: this is embedded generically with no host-passed props, so the
 * current page URL can't arrive via a mount() parameter — the contract says
 * to "pass it in from the browser via the view". The Blade view captures
 * `window.location.href` client-side (Alpine `x-init`, bundled with
 * Livewire 3) into the public $pageUrl property on open, which this class
 * then folds into the created task's description.
 */
class DispatchWidget extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public string $title = '';
    public string $type = 'bug';
    public string $description = '';
    public string $pageUrl = '';

    public ?string $createdCode = null;

    /** @var array<int,\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $screenshots = [];

    protected function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
            'type' => 'required|in:bug,feature',
            'description' => 'nullable|string|max:20000',
            'screenshots.*' => 'nullable|file',
        ];
    }

    public function openModal(): void
    {
        if (! Auth::check()) {
            return;
        }

        $this->resetForm();
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'description', 'screenshots', 'createdCode']);
        $this->type = 'bug';
        $this->resetErrorBag();
    }

    public function removeScreenshot(int $index): void
    {
        unset($this->screenshots[$index]);
        $this->screenshots = array_values($this->screenshots);
    }

    public function submit(): void
    {
        Gate::authorize('create', config('dispatch.models.task'));
        $this->validate();

        $attachmentService = app(AttachmentService::class);
        foreach ($this->screenshots as $file) {
            $attachmentService->validate($file);
        }

        $description = trim($this->description);
        if ($this->pageUrl !== '') {
            $description = trim($description."\n\n---\nReported from: {$this->pageUrl}");
        }

        $task = app(DispatchTaskService::class)->create([
            'title' => $this->title,
            'description' => $description !== '' ? $description : null,
            'type' => $this->type,
            'priority' => 'medium',
            'status' => 'triage',
        ], ['source:widget']);

        foreach ($this->screenshots as $file) {
            $attachmentService->store($file, $task, Auth::id());
        }

        $this->createdCode = $task->code;
        $this->reset(['title', 'description', 'screenshots']);
        $this->dispatch('dispatch-widget-submitted', code: $task->code);
    }

    public function render()
    {
        return view('dispatch::livewire.dispatch-widget');
    }
}
