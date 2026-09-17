@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Tambah User" description="Buat akun baru untuk admin atau staff."><x-ui.button href="/users" variant="light">Kembali</x-ui.button></x-ui.page-header><x-ui.card>@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif<form action="/users" method="POST">@csrf @include('users.partials.form', ['submitLabel' => 'Simpan User'])</form></x-ui.card>
@endsection
