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
            // Create Super Admin
            $superAdmin = User::updateOrCreate(
                ['email' => 'superadmin@example.com'],
                [
                    'name' => 'Super Admin',
                    'password' => Hash::make('password'),
                    'role' => 'super_admin',
                ]
            );

            echo "✓ Super Admin created/updated: {$superAdmin->email}\n";

            //  Create Admin Futsal Center Jakarta
            $adminJakarta = User::updateOrCreate(
                ['email' => 'admin.jakarta@example.com'],
                [
                    'name' => 'Admin Futsal Jakarta',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                ]
            );

            echo "✓ Admin Jakarta created/updated: {$adminJakarta->email}\n";

            // Assign venues to owners (check if venues exist first)

            // ✅ PERBAIKAN: Menggunakan nama venue yang benar dari VenueSeeder.php
            $jakartaVenueNames = [
                'GOR Senayan Futsal',
                'Futsal Center Jakarta',
                'Arena Mini Soccer Plus'
            ];

            $jakartaVenues = Venue::whereIn('name', $jakartaVenueNames)->get();

            if ($jakartaVenues->count() > 0) {
                // Update semua venue di Jakarta agar dimiliki oleh adminJakarta
                Venue::whereIn('name', $jakartaVenueNames)
                    ->update(['owner_id' => $adminJakarta->id]);

                echo "✓ {$jakartaVenues->count()} Jakarta venues assigned to {$adminJakarta->name}\n";
            } else {
                echo "⚠ No Jakarta venues found (Pastikan VenueSeeder sudah dijalankan!)\n";
            }

            // Create a customer for testing
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
