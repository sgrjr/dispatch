<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Full-page task creation form: title, type, priority, description, labels,
 * public toggle, plus optional image/file attachments (paste/drag wired by
 * dispatch.js against the `newAttachments` property).
 */
class TaskCreate extends Component
{
    use WithFileUploads;

    public string $title = '';
    public string $description = '';
    public string $type = 'feature';
    public string $priority = 'medium';
    public bool $is_public = false;

    /** @var array<int,string> Label names attached on create (existing or new). */
    public array $labelNames = [];
    public string $labelInput = '';

    /** @var array<int,\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    public function mount(): void
    {
        Gate::authorize('create', config('dispatch.models.task'));
    }

    protected function rules(): array
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        return [
            'title' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:20000',
            'type' => 'required|in:'.implode(',', $taskClass::types()),
            'priority' => 'required|in:'.implode(',', $taskClass::priorities()),
            'is_public' => 'boolean',
            'newAttachments.*' => 'nullable|file',
        ];
    }

    /**
     * Toggle a label name in/out of the selection — used both for existing
     * labels (rendered as clickable chips) and for the freeform add box.
     */
    public function toggleLabel(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        if (in_array($name, $this->labelNames, true)) {
            $this->labelNames = array_values(array_filter($this->labelNames, fn ($n) => $n !== $name));
        } else {
            $this->labelNames[] = $name;
        }
    }

    public function addLabelFromInput(): void
    {
        $name = trim($this->labelInput);
        $this->labelInput = '';

        if ($name === '' || in_array($name, $this->labelNames, true)) {
            return;
        }

        $this->labelNames[] = $name;
    }

    public function removeAttachment(int $index): void
    {
        unset($this->newAttachments[$index]);
        $this->newAttachments = array_values($this->newAttachments);
    }

    public function save(): void
    {
        Gate::authorize('create', config('dispatch.models.task'));
        $this->validate();

        $attachmentService = app(AttachmentService::class);

        // Validate every staged file BEFORE creating the task so a bad
        // upload never leaves behind a task with no attachments attached.
        foreach ($this->newAttachments as $file) {
            $attachmentService->validate($file);
        }

        $task = app(DispatchTaskService::class)->create([
            'title' => $this->title,
            'description' => $this->description !== '' ? $this->description : null,
            'type' => $this->type,
            'priority' => $this->priority,
            'is_public' => $this->is_public,
        ], $this->labelNames);

        foreach ($this->newAttachments as $file) {
            $attachmentService->store($file, $task, Auth::id());
        }

        $this->redirect(route('dispatch.show', $task), navigate: false);
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');

        return view('dispatch::livewire.task-create', [
            'types' => $taskClass::types(),
            'priorities' => $taskClass::priorities(),
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
            'existingLabels' => $labelClass::orderBy('name')->get(),
        ])->layout('dispatch::components.layout');
    }
}
