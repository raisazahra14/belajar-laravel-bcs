@props([
    'href' => null,
    'variant' => 'primary',
    'size' => null,
    'icon' => null,
    'type' => 'button',
])
@php
    $classes = 'btn btn-'.$variant.($size ? ' btn-'.$size : '');
@endphp
@if($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }}>@if($icon)<i class="{{ $icon }}" aria-hidden="true"></i>@endif<span>{{ $slot }}</span></a>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }}>@if($icon)<i class="{{ $icon }}" aria-hidden="true"></i>@endif<span>{{ $slot }}</span></button>
@endif
