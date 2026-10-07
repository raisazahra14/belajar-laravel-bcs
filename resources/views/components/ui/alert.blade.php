@props(['type' => 'info', 'dismissible' => false, 'role' => 'alert'])
<div {{ $attributes->class(['alert', 'alert-'.$type, 'alert-dismissible fade show' => $dismissible]) }} role="{{ $role }}" aria-live="polite">{{ $slot }}@if($dismissible)<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup notifikasi"></button>@endif</div>
