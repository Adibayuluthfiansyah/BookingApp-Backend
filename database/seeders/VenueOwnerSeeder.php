<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class VenueOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // 1. Create Super Admin
            $superAdmin = User::updateOrCreate(
                ['email' => 'superadmin@example.com'],
                [
                    'name' => 'Super Admin',
                    'password' => Hash::make('password'),
                    'role' => 'super_admin',
                ]
            );

            echo "✓ Super Admin created/updated: {$superAdmin->email}\n";

            // 2. Create Admin Futsal Center Jakarta
            $adminJakarta = User::updateOrCreate(
                ['email' => 'admin.jakarta@example.com'],
                [
                    'name' => 'Admin Futsal Jakarta',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                ]
            );

            echo "✓ Admin Jakarta created/updated: {$adminJakarta->email}\n";

            // 3. Create Admin Futsal Center Bandung
            $adminBandung = User::updateOrCreate(
                ['email' => 'admin.bandung@example.com'],
                [
                    'name' => 'Admin Futsal Bandung',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                ]
            );

            echo "✓ Admin Bandung created/updated: {$adminBandung->email}\n";

            // 4. Create Admin Futsal Center Surabaya
            $adminSurabaya = User::updateOrCreate(
                ['email' => 'admin.surabaya@example.com'],
                [
                    'name' => 'Admin Futsal Surabaya',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                ]
            );

            echo "✓ Admin Surabaya created/updated: {$adminSurabaya->email}\n";

            // 5. Assign venues to owners (check if venues exist first)
            // Jakarta venues
            $jakartaVenues = Venue::whereIn('name', ['Futsal Arena Jakarta', 'Sport Center Jakarta'])->get();
            if ($jakartaVenues->count() > 0) {
                Venue::whereIn('name', ['Futsal Arena Jakarta', 'Sport Center Jakarta'])
                    ->update(['owner_id' => $adminJakarta->id]);
                echo "✓ Jakarta venues ({$jakartaVenues->count()}) assigned to {$adminJakarta->name}\n";
            } else {
                echo "⚠ No Jakarta venues found\n";
            }

            // Bandung venues
            $bandungVenues = Venue::whereIn('name', ['Futsal Center Bandung', 'Bandung Sport Complex'])->get();
            if ($bandungVenues->count() > 0) {
                Venue::whereIn('name', ['Futsal Center Bandung', 'Bandung Sport Complex'])
                    ->update(['owner_id' => $adminBandung->id]);
                echo "✓ Bandung venues ({$bandungVenues->count()}) assigned to {$adminBandung->name}\n";
            } else {
                echo "⚠ No Bandung venues found\n";
            }

            // Surabaya venues
            $surabayaVenues = Venue::whereIn('name', ['Futsal Arena Surabaya', 'Surabaya Sport Hub'])->get();
            if ($surabayaVenues->count() > 0) {
                Venue::whereIn('name', ['Futsal Arena Surabaya', 'Surabaya Sport Hub'])
                    ->update(['owner_id' => $adminSurabaya->id]);
                echo "✓ Surabaya venues ({$surabayaVenues->count()}) assigned to {$adminSurabaya->name}\n";
            } else {
                echo "⚠ No Surabaya venues found\n";
            }

            // 6. Create a customer for testing
            $customer = User::updateOrCreate(
                ['email' => 'customer@example.com'],
                [
                    'name' => 'Customer Test',
                    'password' => Hash::make('password'),
                    'role' => 'customer',
                ]
            );

            echo "✓ Customer created/updated: {$customer->email}\n";

            DB::commit();

            echo "\n=== SEEDING COMPLETE ===\n";
            echo "Login credentials:\n";
            echo "Super Admin: superadmin@example.com / password\n";
            echo "Admin Jakarta: admin.jakarta@example.com / password\n";
            echo "Admin Bandung: admin.bandung@example.com / password\n";
            echo "Admin Surabaya: admin.surabaya@example.com / password\n";
            echo "Customer: customer@example.com / password\n";
        } catch (\Exception $e) {
            DB::rollBack();
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . "\n";
            echo "Line: " . $e->getLine() . "\n";
            throw $e;
        }
    }
}
