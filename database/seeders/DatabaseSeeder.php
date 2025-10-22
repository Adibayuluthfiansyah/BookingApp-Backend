<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use Database\Seeders\VenueSeeder;
use Database\Seeders\VenueOwnerSeeder;


class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // UserSeeder::class,
            VenueSeeder::class,       // 1. Buat Venues, Fields, dan TimeSlots
            VenueOwnerSeeder::class,
        ]);
    }
}
