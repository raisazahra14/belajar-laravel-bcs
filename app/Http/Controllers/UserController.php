<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
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
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        User::create($data);

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
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (blank($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect('/users')->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        if ($user->createdPurchaseOrders()->exists()) {
            return back()->with('error', 'User tidak dapat dihapus karena tercatat memiliki riwayat pembuatan Purchase Order.');
        }

        if (GoodsReceipt::where('received_by', $user->id)->exists()) {
            return back()->with('error', 'User tidak dapat dihapus karena tercatat sebagai penerima barang fisik (Goods Receipt).');
        }

        try {
            $user->delete();
        } catch (QueryException) {
            return back()->with('error', 'User tidak dapat dihapus karena masih terhubung dengan data operasional.');
        }

        return redirect('/users')->with('success', 'User berhasil dihapus.');
    }
}
