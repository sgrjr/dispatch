{{--
    Due-date badge for a board card / list row. Expects: $task. No badge when
    due_at is null. Tier comes from Task::dueBucket() — the same classifier the
    due filter queries — except an inactive (backburner/done/declined) task
    always renders the muted default badge: closed/parked work never wears an
    urgency tier, whatever its date.

    The explicit signed (false) arg to diffInDays plus the (int) cast is
    deliberate: Carbon 2 defaults to absolute, Carbon 3 to signed float —
    explicit signed + cast reads the same on both.
--}}
@if ($task->due_at)
    @php
        $dueDiffDays = (int) now()->startOfDay()->diffInDays($task->due_at->copy()->startOfDay(), false);
        $dueText = match (true) {
            $dueDiffDays === 0 => 'due today',
            $dueDiffDays === 1 => 'due tomorrow',
            $dueDiffDays < 0 => 'due '.abs($dueDiffDays).'d ago',
            default => 'due in '.$dueDiffDays.'d',
        };
        $dueTier = $task->isInactive() ? '' : match ($task->dueBucket()) {
            'overdue' => ' is-danger',
            'today' => ' is-warning',
            'week' => ' is-due-week',
            default => '',
        };
    @endphp
    <span class="dispatch-badge{{ $dueTier }}" title="Due {{ $task->due_at->format('M j, Y') }}">{{ $dueText }}</span>
@endif
