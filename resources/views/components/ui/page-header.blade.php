@props(['title', 'description'])
<div {{ $attributes->class('page-header') }}><div><h1>{{ $title }}</h1><p>{{ $description }}</p></div>{{ $slot }}</div>
