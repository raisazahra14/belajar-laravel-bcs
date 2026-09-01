@props(['type' => 'info', 'dismissible' => false])
<div {{ $attributes->class(['alert', 'alert-'.$type, 'alert-dismissible fade show' => $dismissible]) }} role="alert">{{ $slot }}@if($dismissible)<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup notifikasi"></button>@endif</div>
