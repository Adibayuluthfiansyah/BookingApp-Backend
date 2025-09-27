<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create Admin User
        User::updateOrCreate(
            ['email' => 'admin@bookingfield.com'],
            [
                'name' => 'Admin Kashmir',
                'email' => 'admin@bookingfield.com',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
            ]
        );

        // Create Customer Demo
        User::updateOrCreate(
            ['email' => 'customer@bookingfield.com'],
            [
                'name' => 'Customer Demo',
                'email' => 'customer@bookingfield.com',
                'password' => Hash::make('customer123'),
                'role' => 'customer',
            ]
        );

        echo "✅ Users created successfully!\n";
        echo "Admin: admin@bookingfield.com / admin123\n";
        echo "Customer: customer@bookingfield.com / customer123\n";
    }
}
