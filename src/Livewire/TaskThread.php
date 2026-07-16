<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AttachmentService;

/**
 * Comment thread embedded in TaskShow (`<livewire:dispatch-thread :task="$task" .../>`).
 * Staff may post internal notes (hidden from non-staff); a non-internal
 * ("public") comment is a customer-facing update and triggers TaskUpdate.
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

        if (! $internal) {
            $this->notifySubmitterOfUpdate($this->task, $comment);
        }

        $this->reset('body', 'is_internal', 'newAttachments');
        $this->dispatch('commentAdded');
    }

    /**
     * DECISION: the contract assigns "on a customer-facing update (public
     * comment or status change) send TaskUpdate" to this component's bullet,
     * but status changes are made in TaskShow/TaskBoard, not here. I
     * interpreted "customer-facing" as is_internal === false (not gated by
     * task->is_public — the submitter should hear about their own task
     * regardless of whether OTHER customers can see it), and duplicated this
     * small guarded helper in TaskShow for the status-change case rather than
     * inventing a shared trait/file outside my assigned list.
     *
     * DECISION: src/Notifications/ is an empty directory in this checkout —
     * Sgrjr\Dispatch\Notifications\TaskUpdate does not exist yet (presumably
     * another wave's file). `::class` on a non-existent class is safe (it's
     * just a string), and the class_exists guard means this component works
     * correctly whether or not that class has landed by the time the full
     * suite runs.
     */
    protected function notifySubmitterOfUpdate(Task $task, TaskComment $comment): void
    {
        if (! config('dispatch.notifications.enabled', true)) {
            return;
        }

        $notificationClass = \Sgrjr\Dispatch\Notifications\TaskUpdate::class;
        if (! class_exists($notificationClass)) {
            return;
        }

        $submitter = $task->submitter;
        if (! $submitter || $submitter->getKey() === Auth::id()) {
            return;
        }

        $submitter->notify(new $notificationClass($task, $comment));
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
