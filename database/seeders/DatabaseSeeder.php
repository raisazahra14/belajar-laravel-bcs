<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Database\Seeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = User::updateOrCreate(['email' => 'admin@logistikku.test'], [
            'name' => 'Administrator', 'role' => 'admin', 'password' => Hash::make('password'),
        ]);
        $admin->syncRoles('Admin');

        $staff = User::updateOrCreate(['email' => 'staff@logistikku.test'], [
            'name' => 'Staff Gudang', 'role' => 'staff', 'password' => Hash::make('password'),
        ]);
        $staff->syncRoles('Staff Gudang');

        $manager = User::updateOrCreate(['email' => 'manager@logistikku.test'], [
            'name' => 'Manager', 'role' => 'manager', 'password' => Hash::make('password'),
        ]);
        $manager->syncRoles('Manager');

        Barang::factory()->count(50)->create();
    }
}
