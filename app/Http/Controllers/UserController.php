<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::latest()->paginate(10)]);
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['Admin', 'Staff Gudang', 'Manager'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $role = $data['role'];
        $data['role'] = match ($role) {
            'Admin' => 'admin',
            'Manager' => 'manager',
            default => 'staff',
        };

        $user = User::create($data);
        $user->syncRoles($role);

        return redirect('/users')->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['Admin', 'Staff Gudang', 'Manager'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $role = $data['role'];
        $data['role'] = match ($role) {
            'Admin' => 'admin',
            'Manager' => 'manager',
            default => 'staff',
        };

        if (blank($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        $user->syncRoles($role);

        return redirect('/users')->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        $user->delete();

        return redirect('/users')->with('success', 'User berhasil dihapus.');
    }
}
