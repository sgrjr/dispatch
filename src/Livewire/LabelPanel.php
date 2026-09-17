<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Staff "Labels" cleanup surface. Every label with how many tasks carry it,
 * filterable down to the noise (unused / used once / rarely used), with a
 * multi-select that REPLACES the selection with a canonical label (existing or
 * new) or RETIRES it outright. The rules — aliases, focus rewrites, timeline
 * events — live in LabelCleanupService; this page only selects and previews.
 *
 * Same staff-only gate as FocusPanel: labels are shared vocabulary, like
 * focuses, and a non-staff user is redirected to the portal rather than 403'd.
 */
class LabelPanel extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = any usage; otherwise the max task count to show ('0' = unused). */
    #[Url(as: 'uses', except: '')]
    public string $maxUses = '';

    /** 'usage' (fewest tasks first), 'usage_desc', or 'name'. */
    #[Url(as: 'sort', except: 'usage')]
    public string $sort = 'usage';

    /** @var array<int,int|string> Selected label ids. */
    public array $selected = [];

    public string $replaceWith = '';

    public function mount(): void
    {
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));
        }
    }

    /**
     * Select every label the current filters show (on top of any selection).
     */
    public function selectShown(): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $this->selected = array_values(array_unique(array_merge(
            $this->selectedIds(),
            $this->shown(app(LabelCleanupService::class)->usage())->modelKeys(),
        )));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function replaceSelected(): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);
        $labels = app(LabelCleanupService::class);

        if ($this->selectedIds() === [] || trim($this->replaceWith) === '') {
            return;
        }

        try {
            $result = $labels->replace($this->selectedIds(), $this->replaceWith, Auth::id());
        } catch (InvalidArgumentException $e) {
            session()->flash('dispatch-label-error', $e->getMessage());

            return;
        }

        $count = count($result['replaced']);
        $message = $count === 0
            ? "Nothing to replace — “{$result['target']}” was the only label selected."
            : sprintf(
                'Replaced %s with “%s” on %d task(s)%s. The old name%s now redirect%s to it.%s',
                $count === 1 ? '“'.$result['replaced'][0].'”' : "{$count} labels",
                $result['target'],
                $result['tasks'],
                $result['already_on_target'] > 0 ? " ({$result['already_on_target']} already had it)" : '',
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : '',
                $result['focuses_updated'] > 0 ? " Updated {$result['focuses_updated']} focus(es)." : '',
            );

        session()->flash('dispatch-status', $message);
        $this->reset(['selected', 'replaceWith']);
    }

    public function retireSelected(): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);
        $labels = app(LabelCleanupService::class);

        if ($this->selectedIds() === []) {
            return;
        }

        try {
            $result = $labels->retire($this->selectedIds(), Auth::id());
        } catch (InvalidArgumentException $e) {
            session()->flash('dispatch-label-error', $e->getMessage());

            return;
        }

        $count = count($result['retired']);
        $message = sprintf(
            'Retired %s from %d task(s).',
            $count === 1 ? '“'.$result['retired'][0].'”' : "{$count} labels",
            $result['tasks'],
        );
        if ($result['focuses_deactivated'] !== []) {
            $message .= ' Deactivated focus(es) left with no labels: '.implode(', ', $result['focuses_deactivated']).'.';
        } elseif ($result['focuses_updated'] > 0) {
            $message .= " Updated {$result['focuses_updated']} focus(es).";
        }

        session()->flash('dispatch-status', $message);
        $this->reset(['selected', 'replaceWith']);
    }

    public function removeAlias(int $aliasId): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        app(LabelCleanupService::class)->removeAlias($aliasId);
    }

    /**
     * @return array<int,int>
     */
    protected function selectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selected)));
    }

    /**
     * The usage rows the search / max-uses filters and sort leave showing.
     *
     * @param  Collection<int,\Sgrjr\Dispatch\Models\Label>  $all
     * @return Collection<int,\Sgrjr\Dispatch\Models\Label>
     */
    protected function shown(Collection $all): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        $rows = $all->filter(function ($label) use ($needle) {
            if ($needle !== '') {
                $haystack = mb_strtolower($label->name.' '.$label->aliases->pluck('name')->implode(' '));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return $this->maxUses === '' || (int) $label->tasks_count <= (int) $this->maxUses;
        });

        $rows = match ($this->sort) {
            'name' => $rows->sortBy(fn ($l) => mb_strtolower($l->name)),
            'usage_desc' => $rows->sortBy([['tasks_count', 'desc'], fn ($a, $b) => strcasecmp($a->name, $b->name)]),
            default => $rows->sortBy([['tasks_count', 'asc'], fn ($a, $b) => strcasecmp($a->name, $b->name)]),
        };

        return $rows->values();
    }

    public function render()
    {
        $labels = app(LabelCleanupService::class);
        $all = $labels->usage();
        $selectedIds = $this->selectedIds();

        // Drop selections whose label no longer exists (another tab cleaned it).
        $existing = $all->modelKeys();
        $selectedIds = array_values(array_intersect($selectedIds, $existing));

        $replacePreview = $selectedIds !== [] && trim($this->replaceWith) !== ''
            ? $labels->preview($selectedIds, $this->replaceWith)
            : null;
        $retirePreview = $selectedIds !== [] ? $labels->preview($selectedIds) : null;

        return view('dispatch::livewire.label-panel', [
            'labels' => $this->shown($all),
            'allNames' => $all->pluck('name')->all(),
            'selectedIds' => $selectedIds,
            'totals' => [
                'labels' => $all->count(),
                'unused' => $all->where('tasks_count', 0)->count(),
                'single' => $all->where('tasks_count', 1)->count(),
            ],
            'replacePreview' => $replacePreview,
            'retirePreview' => $retirePreview,
            'listRoute' => config('dispatch.routes.name_prefix', 'dispatch.').'index',
        ])->layout('dispatch::components.layout');
    }
}
