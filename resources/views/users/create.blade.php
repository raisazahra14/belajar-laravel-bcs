@extends('layouts.skydash')
@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Tambah User</h3><p>Buat akun baru untuk admin atau staff.</p></div><a href="/users" class="btn btn-light">Kembali</a></div><div class="card"><div class="card-body">@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif<form action="/users" method="POST">@csrf @include('users.partials.form', ['submitLabel' => 'Simpan User'])</form></div></div>
@endsection
