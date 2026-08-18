@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Kelola User</h3><p>Atur akun dan hak akses pengguna aplikasi.</p></div><a href="/users/create" class="btn btn-primary">Tambah User</a></div>
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>@forelse($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td><span class="badge {{ $user->role === 'admin' ? 'badge-primary' : 'badge-info' }}">{{ ucfirst($user->role) }}</span></td><td>{{ $user->created_at->format('d-m-Y') }}</td><td><a class="btn btn-sm btn-inverse-warning me-1" href="/users/{{ $user->id }}/edit">Edit</a><form action="/users/{{ $user->id }}" method="POST" class="d-inline" onsubmit="return confirm('Hapus user ini?')">@csrf @method('DELETE')<button class="btn btn-sm btn-inverse-danger">Hapus</button></form></td></tr>@empty<tr><td colspan="5"><div class="empty-state">Belum ada user.</div></td></tr>@endforelse</tbody></table></div><div class="d-flex justify-content-center mt-3">{{ $users->links() }}</div></div></div>
@endsection
