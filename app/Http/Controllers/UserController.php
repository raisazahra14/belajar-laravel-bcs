<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $this->normalizeEmail($request);

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
        $this->normalizeEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $roleChanged = $data['role'] !== $user->role;
        $passwordChanged = ! blank($data['password'] ?? null);

        if (blank($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if ($roleChanged || $passwordChanged) {
            $user->tokens()->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            if (config('session.driver') === 'database') {
                $sessionTable = (string) config('session.table', 'sessions');
                if (Schema::hasTable($sessionTable)) {
                    $sessions = DB::table($sessionTable)->where('user_id', $user->id);
                    if ($request->user()?->is($user) && $request->hasSession()) {
                        $sessions->where('id', '!=', $request->session()->getId());
                    }
                    $sessions->delete();
                }
            }
        }

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

    private function normalizeEmail(Request $request): void
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
    }
}
