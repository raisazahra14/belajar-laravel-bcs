@props(['icon', 'title', 'description'])
<div {{ $attributes->class('empty-state') }}><i class="{{ $icon }}"></i><h3>{{ $title }}</h3><p>{{ $description }}</p>{{ $slot }}</div>
