{{--
    Facet-ordered label chips. Params:
      $labels   iterable<Label>
      $context  'card' | 'row' | 'detail'   (default 'row')

    Elevated chips always lead. Plain chips follow in 'row'/'detail'. Meta chips
    show ONLY in 'detail'. 'card' is the tightest surface — elevated only.
    Bucketing is LabelFacets::split(); surface workers add their own CSS for
    .dispatch-label-elevated / .dispatch-label-meta on top of the base badge.
--}}
@php($dispatchChips = \Sgrjr\Dispatch\Support\LabelFacets::split($labels))
@php($dispatchCtx = $context ?? 'row')

@foreach ($dispatchChips['elevated'] as $label)
    <span class="dispatch-badge dispatch-label-elevated" style="background-color: {{ $label->color ?: '#94a3b8' }}; color:#fff; font-weight:700;">{{ $label->name }}</span>
@endforeach

@if ($dispatchCtx !== 'card')
    @foreach ($dispatchChips['plain'] as $label)
        <span class="dispatch-badge" style="background-color: {{ $label->color ?: '#94a3b8' }}; color:#fff;">{{ $label->name }}</span>
    @endforeach
@endif

@if ($dispatchCtx === 'detail')
    @foreach ($dispatchChips['meta'] as $label)
        <span class="dispatch-badge dispatch-label-meta" style="background:transparent; border:1px solid var(--dispatch-border); color: var(--dispatch-text-muted);">{{ $label->name }}</span>
    @endforeach
@endif
