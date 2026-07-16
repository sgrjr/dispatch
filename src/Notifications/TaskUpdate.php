<?php

namespace Sgrjr\Dispatch\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Sent to a task's submitter when a customer-facing update happens: a status
 * change, or a non-internal comment. `via()` and the brand/link are entirely
 * config-driven so a consuming app doesn't need to subclass this to re-theme
 * it.
 *
 * Ported from rupkeep's Notifications\TaskUpdate, which hard-coded the mail
 * copy ("Rupkeep") and the link (`route('portal.tasks.show', ...)`). Both now
 * come from `dispatch.brand.*`.
 */
class TaskUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Task  $task  The task the update concerns.
     * @param  string|null  $message  Freeform update text (e.g. a status-change
     *         summary). Falls back to $comment->body when omitted.
     * @param  TaskComment|null  $comment  The comment that triggered this
     *         notification, if any (e.g. a non-internal reply).
     */
    public function __construct(
        public Task $task,
        public ?string $message = null,
        public ?TaskComment $comment = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return (array) config('dispatch.notifications.channels', ['mail']);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $brand = (string) config('dispatch.brand.name', config('app.name', 'Dispatch'));
        $url = $this->taskUrl();
        $statusLabel = str_replace('_', ' ', (string) $this->task->status);
        $name = $notifiable->name ?? null;
        $body = $this->message ?? $this->comment?->body;

        $mail = (new MailMessage())
            ->subject("[{$this->task->code}] {$this->task->title}")
            ->greeting($name ? "Hi {$name}," : 'Hello,')
            ->line("There's an update on your request: **{$this->task->title}** ({$this->task->code}).")
            ->line("Current status: **{$statusLabel}**");

        if (! empty($body)) {
            $mail->line('---')->line($body);
        }

        return $mail
            ->action('View request', $url)
            ->line("Thanks for using {$brand}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_code' => $this->task->code,
            'status' => $this->task->status,
            'message' => $this->message ?? $this->comment?->body,
        ];
    }

    /**
     * `dispatch.brand.task_url` is a route name, called as route($name, $task).
     * Falls back to the app root if it isn't resolvable (e.g. misconfigured).
     */
    protected function taskUrl(): string
    {
        $routeName = config('dispatch.brand.task_url', 'dispatch.show');

        if (is_string($routeName) && $routeName !== '') {
            try {
                return route($routeName, $this->task);
            } catch (\Throwable) {
                // Fall through to the app root if the route isn't registered.
            }
        }

        return url('/');
    }
}
