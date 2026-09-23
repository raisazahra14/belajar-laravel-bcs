@props(['variant' => 'primary'])
<span {{ $attributes->class(['badge', 'badge-'.$variant]) }}>{{ $slot }}</span>
