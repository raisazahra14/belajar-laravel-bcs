@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Edit User</h3><p>Perbarui akun {{ $user->name }}.</p></div><a href="/users" class="btn btn-light">Kembali</a></div><div class="card"><div class="card-body">@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif<form action="/users/{{ $user->id }}" method="POST">@csrf @method('PUT') @include('users.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></div></div>
@endsection
