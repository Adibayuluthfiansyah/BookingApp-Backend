<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Hash;

class VenueOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Super Admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
            ]
        );

        echo "✓ Super Admin created: {$superAdmin->email}\n";

        // 2. Create Admin Futsal Center Jakarta
        $adminJakarta = User::firstOrCreate(
            ['email' => 'admin.jakarta@example.com'],
            [
                'name' => 'Admin Futsal Jakarta',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        echo "✓ Admin Jakarta created: {$adminJakarta->email}\n";

        // 3. Create Admin Futsal Center Bandung
        $adminBandung = User::firstOrCreate(
            ['email' => 'admin.bandung@example.com'],
            [
                'name' => 'Admin Futsal Bandung',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        echo "✓ Admin Bandung created: {$adminBandung->email}\n";

        // 4. Create Admin Futsal Center Surabaya
        $adminSurabaya = User::firstOrCreate(
            ['email' => 'admin.surabaya@example.com'],
            [
                'name' => 'Admin Futsal Surabaya',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        echo "✓ Admin Surabaya created: {$adminSurabaya->email}\n";

        // 5. Assign venues to owners
        // Jakarta venues
        Venue::whereIn('name', ['Futsal Arena Jakarta', 'Sport Center Jakarta'])
            ->update(['owner_id' => $adminJakarta->id]);

        echo "✓ Jakarta venues assigned to {$adminJakarta->name}\n";

        // Bandung venues
        Venue::whereIn('name', ['Futsal Center Bandung', 'Bandung Sport Complex'])
            ->update(['owner_id' => $adminBandung->id]);

        echo "✓ Bandung venues assigned to {$adminBandung->name}\n";

        // Surabaya venues
        Venue::whereIn('name', ['Futsal Arena Surabaya', 'Surabaya Sport Hub'])
            ->update(['owner_id' => $adminSurabaya->id]);

        echo "✓ Surabaya venues assigned to {$adminSurabaya->name}\n";

        // 6. Create a customer for testing
        $customer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'Customer Test',
                'password' => Hash::make('password'),
                'role' => 'customer',
            ]
        );

        echo "✓ Customer created: {$customer->email}\n";

        echo "\n=== SEEDING COMPLETE ===\n";
        echo "Login credentials:\n";
        echo "Super Admin: superadmin@example.com / password\n";
        echo "Admin Jakarta: admin.jakarta@example.com / password\n";
        echo "Admin Bandung: admin.bandung@example.com / password\n";
        echo "Admin Surabaya: admin.surabaya@example.com / password\n";
        echo "Customer: customer@example.com / password\n";
    }
}
