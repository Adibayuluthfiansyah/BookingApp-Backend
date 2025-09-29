<?php

namespace Database\Seeders;

use App\Models\Field;
use Illuminate\Database\Seeder;

class FieldSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            // GOR Senayan Futsal (venue_id: 1)
            [
                'venue_id' => 1,
                'name' => 'Lapangan A',
                'field_type' => 'futsal',
                'description' => 'Lapangan utama dengan fasilitas terlengkap',
            ],
            [
                'venue_id' => 1,
                'name' => 'Lapangan B',
                'field_type' => 'futsal',
                'description' => 'Lapangan standar dengan AC',
            ],

            // Futsal Center Jakarta (venue_id: 2)
            [
                'venue_id' => 2,
                'name' => 'Lapangan A',
                'field_type' => 'futsal',
                'description' => 'Lapangan indoor premium',
            ],
            [
                'venue_id' => 2,
                'name' => 'Lapangan B',
                'field_type' => 'futsal',
                'description' => 'Lapangan indoor standar',
            ],
            [
                'venue_id' => 2,
                'name' => 'Lapangan C',
                'field_type' => 'futsal',
                'description' => 'Lapangan indoor standar',
            ],

            // Arena Mini Soccer Plus (venue_id: 3)
            [
                'venue_id' => 3,
                'name' => 'Lapangan 1',
                'field_type' => 'minisoccer',
                'description' => 'Lapangan outdoor dengan rumput sintetis berkualitas',
            ],
        ];

        foreach ($fields as $field) {
            Field::create($field);
        }
    }
}
