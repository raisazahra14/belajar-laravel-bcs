@extends('layouts.skydash')
@section('content')
<x-ui.page-header title="Edit User" :description="'Perbarui akun '.$user->name.'.'"><x-ui.button href="/users" variant="light">Kembali</x-ui.button></x-ui.page-header><x-ui.card>@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif<form action="/users/{{ $user->id }}" method="POST">@csrf @method('PUT') @include('users.partials.form', ['submitLabel' => 'Simpan Perubahan'])</form></x-ui.card>
@endsection
