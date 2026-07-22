<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;

/**
 * Staff "Focuses" management surface (roadmap W8-2). A Focus is a saved
 * steering lens over the backlog — the top-ranked ACTIVE focus wins when
 * `dispatch:next`/`claim` decide what to surface first (`--no-focus`
 * bypasses steering; an exhausted focus falls through to the next).
 *
 * Focuses are CREATED from the board/list filter bars ("save current filters
 * as focus"); this page is PURE MANAGEMENT — reorder (rank), (de)activate,
 * rename, and delete. It lists active AND inactive focuses so management sees
 * everything. Same staff-only gate as TaskBoard/TaskList: a non-staff user is
 * redirected to the submitter portal rather than 403'd.
 */
class FocusPanel extends Component
{
    /**
     * Per-row rename buffers, keyed by focus id and bound via wire:model from
     * the inline edit input. Seeded from each focus's current name in render()
     * (with ??= so an in-progress edit is preserved across re-renders).
     *
     * @var array<int,string>
     */
    public array $names = [];

    public function mount(): void
    {
        // Staff-only surface — mirror TaskBoard: redirect non-staff to their
        // own submissions (the portal) instead of a 403.
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));

            return;
        }
    }

    public function moveUp(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $this->swapWithNeighbor($id, -1);
    }

    public function moveDown(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $this->swapWithNeighbor($id, +1);
    }

    public function toggleActive(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $model = $this->focusModel();
        $focus = $model::query()->find($id);

        if ($focus === null) {
            return;
        }

        $focus->is_active = ! $focus->is_active;
        $focus->save();
    }

    public function delete(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $model = $this->focusModel();
        $model::query()->whereKey($id)->delete();

        unset($this->names[$id]);
    }

    public function rename(int $id, string $name): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $name = trim($name);

        if ($name === '') {
            return; // blank is a no-op — the row keeps its current name
        }

        $model = $this->focusModel();
        $focus = $model::query()->find($id);

        if ($focus === null) {
            return;
        }

        $focus->name = $name;
        $focus->save();

        $this->names[$id] = $name;
    }

    /**
     * Swap $id's rank with its adjacent focus in ranked order. Dead simple per
     * the spec: pull the ranked list, find the neighbor one step in $direction,
     * swap the two rank values, save both. No-op at the edges (top can't move
     * up, bottom can't move down) and when $id isn't found.
     */
    protected function swapWithNeighbor(int $id, int $direction): void
    {
        $model = $this->focusModel();
        $ranked = $model::query()->ranked()->get()->values();

        $index = $ranked->search(fn ($f) => (int) $f->getKey() === $id);
        if ($index === false) {
            return;
        }

        $neighborIndex = $index + $direction;
        if ($neighborIndex < 0 || $neighborIndex >= $ranked->count()) {
            return; // edge — nothing to swap with
        }

        $current = $ranked[$index];
        $neighbor = $ranked[$neighborIndex];

        [$current->rank, $neighbor->rank] = [$neighbor->rank, $current->rank];

        $current->save();
        $neighbor->save();
    }

    /**
     * Compact human summary of a focus's constrained axes for the management
     * table, e.g. "labels: area:accounts, api · priorities: high". Each present
     * axis is rendered and comma-joined; an axes-less focus (the storage rule's
     * "all") reads "everything".
     *
     * @param  mixed  $filters  the focus's `filters` cast (array|null)
     */
    public function summarize($filters): string
    {
        $filters = (array) $filters;
        $parts = [];

        foreach (['labels', 'types', 'priorities'] as $axis) {
            $values = array_values(array_filter(
                (array) ($filters[$axis] ?? []),
                fn ($v) => $v !== null && $v !== ''
            ));

            if ($values !== []) {
                $parts[] = $axis.': '.implode(', ', $values);
            }
        }

        return $parts === [] ? 'everything' : implode(' · ', $parts);
    }

    public function render()
    {
        $model = $this->focusModel();

        // Management sees everything — active AND inactive — in ranked order.
        $focuses = $model::query()->ranked()->get();

        foreach ($focuses as $focus) {
            $this->names[$focus->getKey()] ??= $focus->name;
        }

        return view('dispatch::livewire.focus-panel', [
            'focuses' => $focuses,
        ])->layout('dispatch::components.layout');
    }

    /**
     * The configured Focus model class-string (host apps may swap the model).
     *
     * @return class-string<\Sgrjr\Dispatch\Models\Focus>
     */
    protected function focusModel(): string
    {
        return config('dispatch.models.focus', \Sgrjr\Dispatch\Models\Focus::class);
    }
}
