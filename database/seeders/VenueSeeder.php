<?php
// database/seeders/VenueSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Venue;
use App\Models\Field;

class VenueSeeder extends Seeder
{
    public function run()
    {
        // Sample venues data
        $venuesData = [
            [
                'name' => 'JS MiniSoccer',
                'description' => 'Lapangan MiniSoccer Modern dengan fasilitas lengkap',
                'address' => 'Jl. Sepakat  Gg.Sepakat 2 DiBelakang Indomaret Pontianak',
                'phone' => '021-12345678',
                'price_per_hour' => 150000,
                'image' => 'venues/gor-senayan.jpg',
                'facilities' => ['Parkir Luas', 'Kantin', 'Kamar Mandi'],
                'latitude' => -6.2088,
                'longitude' => 106.8456,
                'fields' => [
                    ['name' => 'Lapangan ', 'type' => 'mini_soccer']
                ]
            ],
            [
                'name' => 'Futsal Center Jakarta',
                'description' => 'Kompleks futsal terlengkap dengan 6 lapangan indoor',
                'address' => 'Jl. Gatot Subroto No. 25, Jakarta Barat',
                'phone' => '021-87654321',
                'price_per_hour' => 120000,
                'image' => 'venues/futsal-center.jpg',
                'facilities' => ['Indoor', 'AC', 'Sound System', '+2 lainnya'],
                'status' => 'active',
                'latitude' => -6.1944,
                'longitude' => 106.8229,
                'fields' => [
                    ['name' => 'Lapangan 1', 'type' => 'futsal'],
                    ['name' => 'Lapangan 2', 'type' => 'futsal']
                ]
            ],
            [
                'name' => 'Arena Mini Soccer Plus',
                'description' => 'Lapangan mini soccer outdoor dengan rumput sintetis berkualitas',
                'address' => 'Jl. Sudirman No. 15, Jakarta Selatan',
                'phone' => '021-11223344',
                'price_per_hour' => 200000,
                'image' => 'venues/mini-soccer-plus.jpg',
                'facilities' => ['Rumput Sintetis', 'Lampu Sorot', 'Parkir', '+1 lainnya'],
                'latitude' => -6.2297,
                'longitude' => 106.8106,
                'fields' => [
                    ['name' => 'Field 1', 'type' => 'mini_soccer'],
                    ['name' => 'Field 2', 'type' => 'mini_soccer']
                ]
            ],
            [
                'name' => 'Sport Complex Kemang',
                'description' => 'Kompleks olahraga dengan berbagai fasilitas modern',
                'address' => 'Jl. Kemang Raya No. 100, Jakarta Selatan',
                'phone' => '021-99887766',
                'price_per_hour' => 180000,
                'image' => 'venues/sport-complex.jpg',
                'facilities' => ['AC', 'Cafeteria', 'Locker Room', 'Shower'],
                'status' => 'active',
                'latitude' => -6.2615,
                'longitude' => 106.8106,
                'fields' => [
                    ['name' => 'Court A', 'type' => 'futsal'],
                    ['name' => 'Court B', 'type' => 'futsal'],
                    ['name' => 'Field C', 'type' => 'mini_soccer']
                ]
            ],
            [
                'name' => 'Jakarta Football Arena',
                'description' => 'Arena sepak bola mini dengan standar internasional',
                'address' => 'Jl. Thamrin No. 50, Jakarta Pusat',
                'phone' => '021-55443322',
                'price_per_hour' => 250000,
                'image' => 'venues/football-arena.jpg',
                'facilities' => ['Outdoor', 'Tribun', 'Sound System', 'Parkir Luas'],
                'status' => 'active',
                'latitude' => -6.1751,
                'longitude' => 106.8650,
                'fields' => [
                    ['name' => 'Arena 1', 'type' => 'mini_soccer'],
                    ['name' => 'Arena 2', 'type' => 'mini_soccer']
                ]
            ]
        ];

        foreach ($venuesData as $venueData) {
            $fields = $venueData['fields'];
            unset($venueData['fields']);

            $venue = Venue::create($venueData);

            // Create fields for each venue
            foreach ($fields as $fieldData) {
                $venue->fields()->create([
                    'name' => $fieldData['name'],
                    'type' => $fieldData['type'],
                    'status' => 'active'
                ]);
            }
        }

        $this->command->info('Venue seeder completed! Created ' . count($venuesData) . ' venues with fields.');
    }
}
