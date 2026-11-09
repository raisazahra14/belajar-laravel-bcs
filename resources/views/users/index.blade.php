@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Kelola User" description="Atur akun dan hak akses pengguna aplikasi."><x-ui.button href="/users/create">Tambah User</x-ui.button></x-ui.page-header>
@if(session('success'))
    <x-ui.alert type="success">{{ session('success') }}</x-ui.alert>
@endif

@if(session('error'))
    <x-ui.alert type="danger">{{ session('error') }}</x-ui.alert>
@endif
<x-ui.card><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td><x-ui.badge :variant="$user->role === 'admin' ? 'primary' : 'info'">{{ ucfirst($user->role) }}</x-ui.badge></td><td>{{ $user->created_at->format('d-m-Y') }}</td><td><x-ui.button href="/users/{{ $user->id }}/edit" size="sm" variant="inverse-warning" class="me-1">Edit</x-ui.button><form action="/users/{{ $user->id }}" method="POST" class="d-inline" onsubmit="return confirm('Hapus user ini?')">@csrf @method('DELETE')<x-ui.button type="submit" size="sm" variant="inverse-danger">Hapus</x-ui.button></form></td></tr>@empty<tr><td colspan="5"><x-ui.empty-state icon="ti-user" title="Belum ada user" description="Akun pengguna yang ditambahkan akan tampil di sini." /></td></tr>@endforelse</tbody></table></div><div class="d-flex justify-content-center mt-3">{{ $users->links() }}</div></x-ui.card>
@endsection
