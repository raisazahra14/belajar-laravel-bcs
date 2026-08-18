<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@logistikku.test'], [
            'name' => 'Administrator', 'role' => 'admin', 'password' => Hash::make('password'),
        ]);
        User::updateOrCreate(['email' => 'staff@logistikku.test'], [
            'name' => 'Staff Gudang', 'role' => 'staff', 'password' => Hash::make('password'),
        ]);
    }
}
