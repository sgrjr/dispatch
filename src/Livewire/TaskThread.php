<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AttachmentService;

/**
 * Comment thread embedded in TaskShow (`<livewire:dispatch-thread :task="$task" .../>`).
 * Staff may post internal notes (hidden from non-staff); every saved comment
 * (internal or public) is handed to DispatchNotifier::taskCommented(), which
 * decides recipients off $comment->is_internal. A staff commenter is also
 * auto-added as a watcher (see DispatchGate::isStaff()).
 */
class TaskThread extends Component
{
    use WithFileUploads;

    public Task $task;

    public string $body = '';
    public bool $is_internal = false;

    /** @var array<int,\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newAttachments = [];

    protected $listeners = ['task-saved' => '$refresh'];

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);
        $this->task = $task;
    }

    protected function rules(): array
    {
        return [
            'body' => 'required|string|min:1|max:5000',
            'is_internal' => 'boolean',
            'newAttachments.*' => 'nullable|file',
        ];
    }

    public function removeAttachment(int $index): void
    {
        unset($this->newAttachments[$index]);
        $this->newAttachments = array_values($this->newAttachments);
    }

    public function save(): void
    {
        Gate::authorize('comment', $this->task);
        $this->validate();

        // Silently downgrade an internal-note request from a non-staff user
        // rather than erroring — mirrors rupkeep's defensive behavior.
        $internal = $this->is_internal && Gate::allows('commentInternal', $this->task);

        $attachmentService = app(AttachmentService::class);
        foreach ($this->newAttachments as $file) {
            $attachmentService->validate($file);
        }

        /** @var TaskComment $comment */
        $comment = $this->task->comments()->create([
            'user_id' => Auth::id(),
            'body' => trim($this->body),
            'is_internal' => $internal,
            'event_type' => TaskComment::EVENT_COMMENT,
        ]);

        foreach ($this->newAttachments as $file) {
            $attachmentService->store($file, $comment, Auth::id());
        }

        // DispatchNotifier is the single notification seam (see the contract's
        // docblock) — call it directly, no try/catch, for every saved comment
        // (internal or public). The bound implementation decides recipients
        // off $comment->is_internal (e.g. MailNotifier routes an internal note
        // to watchers only, a public comment to submitter + watchers).
        app(DispatchNotifier::class)->taskCommented($this->task, $comment);

        // A staff member who engages with a task is implicitly invested in its
        // outcome — auto-add them as a watcher so future updates reach them
        // without a separate opt-in action.
        if (app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->task->watch(Auth::id());
        }

        $this->reset('body', 'is_internal', 'newAttachments');
        $this->dispatch('commentAdded');
    }

    public function render()
    {
        $canCommentInternal = Gate::allows('commentInternal', $this->task);

        $query = $this->task->comments()->with(['user', 'attachments'])->orderBy('created_at');
        if (! $canCommentInternal) {
            $query->where('is_internal', false);
        }

        return view('dispatch::livewire.task-thread', [
            'comments' => $query->get(),
            'canComment' => Gate::allows('comment', $this->task),
            'canCommentInternal' => $canCommentInternal,
        ]);
    }
}
