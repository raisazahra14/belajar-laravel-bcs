@props(['title' => null])
<section {{ $attributes->class('card app-card') }}><div class="card-body">@if($title)<h2 class="card-title">{{ $title }}</h2>@endif{{ $slot }}</div></section>
