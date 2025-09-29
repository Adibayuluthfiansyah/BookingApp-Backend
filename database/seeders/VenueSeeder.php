<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        $venues = [
            [
                'name' => 'GOR Senayan Futsal',
                'slug' => 'gor-senayan-futsal',
                'description' => 'Lapangan futsal modern dengan fasilitas lengkap dan AC',
                'address' => 'Jl. Asia Afrika No. 8, Jakarta Pusat',
                'city' => 'Jakarta Pusat',
                'province' => 'DKI Jakarta',
                'latitude' => -6.2088,
                'longitude' => 106.8456,
                'image_url' => '/images/venues/gor-senayan.jpg',
                'facebook_url' => 'https://facebook.com/gorsenayan',
                'instagram_url' => 'https://instagram.com/gorsenayan',
            ],
            [
                'name' => 'Futsal Center Jakarta',
                'slug' => 'futsal-center-jakarta',
                'description' => 'Kompleks futsal terlengkap dengan 6 lapangan indoor',
                'address' => 'Jl. Gatot Subroto No. 25, Jakarta Barat',
                'city' => 'Jakarta Barat',
                'province' => 'DKI Jakarta',
                'latitude' => -6.1751,
                'longitude' => 106.8650,
                'image_url' => '/images/venues/futsal-center.jpg',
                'facebook_url' => null,
                'instagram_url' => 'https://instagram.com/futsalcenter',
            ],
            [
                'name' => 'Arena Mini Soccer Plus',
                'slug' => 'arena-mini-soccer-plus',
                'description' => 'Lapangan mini soccer outdoor dengan rumput sintetis berkualitas',
                'address' => 'Jl. Sudirman No. 15, Jakarta Selatan',
                'city' => 'Jakarta Selatan',
                'province' => 'DKI Jakarta',
                'latitude' => -6.2215,
                'longitude' => 106.8192,
                'image_url' => '/images/venues/arena-soccer.jpg',
                'facebook_url' => null,
                'instagram_url' => null,
            ],
        ];

        foreach ($venues as $venue) {
            Venue::create($venue);
        }
    }
}
