{{--
    Focus steering switcher. Params:
      $focuses  iterable<Focus>   (active + ranked)

    The selected value rides the parent component's `focusFilter` property.
    Selection only — no create/manage UI here.
--}}
<select wire:model.live="focusFilter" class="dispatch-select">
    <option value="">All tasks</option>
    @foreach ($focuses as $focus)
        <option value="{{ $focus->id }}">{{ $focus->name }}</option>
    @endforeach
</select>
