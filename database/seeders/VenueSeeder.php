<?php

namespace Database\Seeders;

use App\Models\Venue;
use App\Models\Field;
use App\Models\TimeSlot;
use App\Models\Facility;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        // Create facilities first
        $toiletFacility = Facility::firstOrCreate(['name' => 'Toilet']);
        $mushollaFacility = Facility::firstOrCreate(['name' => 'Musholla']);
        $kantinFacility = Facility::firstOrCreate(['name' => 'Kantin']);
        $parkirFacility = Facility::firstOrCreate(['name' => 'Parkir Luas']);
        $acFacility = Facility::firstOrCreate(['name' => 'AC/Pendingin']);

        // Venue 1: GOR Senayan
        $venue1 = Venue::create([
            'name' => 'GOR Senayan Futsal',
            'slug' => 'gor-senayan-futsal',
            'description' => 'Lapangan futsal modern dengan fasilitas lengkap dan AC',
            'address' => 'Jl. Asia Afrika No. 8, Jakarta Pusat',
            'city' => 'Jakarta Pusat',
            'province' => 'DKI Jakarta',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'image_url' => 'https://images.unsplash.com/photo-1529900748604-07564a03e7a6?w=800&h=600&fit=crop',
            'facebook_url' => 'https://facebook.com/gorsenayan',
            'instagram_url' => 'https://instagram.com/gorsenayan',
        ]);

        // Attach facilities to venue 1
        $venue1->facilities()->attach([
            $toiletFacility->id,
            $mushollaFacility->id,
            $kantinFacility->id,
            $parkirFacility->id,
            $acFacility->id
        ]);

        // Create fields for venue 1
        $field1 = Field::create([
            'venue_id' => $venue1->id,
            'name' => 'Lapangan A',
            'field_type' => 'futsal'
        ]);

        $field2 = Field::create([
            'venue_id' => $venue1->id,
            'name' => 'Lapangan B',
            'field_type' => 'futsal'
        ]);

        // Create time slots for field 1
        $this->createTimeSlots($field1->id, [
            ['08:00:00', '09:00:00', 150000],
            ['09:00:00', '10:00:00', 150000],
            ['10:00:00', '11:00:00', 150000],
            ['11:00:00', '12:00:00', 150000],
            ['12:00:00', '13:00:00', 150000],
            ['13:00:00', '14:00:00', 150000],
            ['14:00:00', '15:00:00', 180000],
            ['15:00:00', '16:00:00', 180000],
            ['16:00:00', '17:00:00', 200000],
            ['17:00:00', '18:00:00', 250000],
            ['18:00:00', '19:00:00', 250000],
            ['19:00:00', '20:00:00', 250000],
            ['20:00:00', '21:00:00', 250000],
            ['21:00:00', '22:00:00', 250000],
            ['22:00:00', '23:00:00', 250000],
            ['23:00:00', '24:00:00', 250000],

        ]);

        // Create time slots for field 2
        $this->createTimeSlots($field2->id, [
            ['08:00:00', '09:00:00', 150000],
            ['09:00:00', '10:00:00', 150000],
            ['10:00:00', '11:00:00', 150000],
            ['11:00:00', '12:00:00', 150000],
            ['12:00:00', '13:00:00', 150000],
            ['13:00:00', '14:00:00', 150000],
            ['14:00:00', '15:00:00', 180000],
            ['15:00:00', '16:00:00', 180000],
            ['16:00:00', '17:00:00', 200000],
            ['17:00:00', '18:00:00', 250000],
            ['18:00:00', '19:00:00', 250000],
            ['19:00:00', '20:00:00', 250000],
            ['20:00:00', '21:00:00', 250000],
            ['21:00:00', '22:00:00', 250000],
            ['22:00:00', '23:00:00', 250000],
            ['23:00:00', '24:00:00', 250000],
        ]);

        // Venue 2: Futsal Center
        $venue2 = Venue::create([
            'name' => 'Futsal Center Jakarta',
            'slug' => 'futsal-center-jakarta',
            'description' => 'Kompleks futsal terlengkap dengan 6 lapangan indoor',
            'address' => 'Jl. Gatot Subroto No. 25, Jakarta Barat',
            'city' => 'Jakarta Barat',
            'province' => 'DKI Jakarta',
            'latitude' => -6.1751,
            'longitude' => 106.8650,
            'image_url' => 'https://images.unsplash.com/photo-1551958219-acbc608c6377?w=800&h=600&fit=crop',
            'facebook_url' => null,
            'instagram_url' => 'https://instagram.com/futsalcenter',
        ]);

        $venue2->facilities()->attach([
            $toiletFacility->id,
            $kantinFacility->id,
            $parkirFacility->id,
        ]);

        $field3 = Field::create([
            'venue_id' => $venue2->id,
            'name' => 'Lapangan 1',
            'field_type' => 'futsal'
        ]);

        $this->createTimeSlots($field3->id, [
            ['08:00:00', '09:00:00', 150000],
            ['09:00:00', '10:00:00', 150000],
            ['10:00:00', '11:00:00', 150000],
            ['11:00:00', '12:00:00', 150000],
            ['12:00:00', '13:00:00', 150000],
            ['13:00:00', '14:00:00', 150000],
            ['14:00:00', '15:00:00', 180000],
            ['15:00:00', '16:00:00', 180000],
            ['16:00:00', '17:00:00', 200000],
            ['17:00:00', '18:00:00', 250000],
            ['18:00:00', '19:00:00', 250000],
            ['19:00:00', '20:00:00', 250000],
            ['20:00:00', '21:00:00', 250000],
            ['21:00:00', '22:00:00', 250000],
            ['22:00:00', '23:00:00', 250000],
            ['23:00:00', '24:00:00', 250000],
        ]);

        // Venue 3: Arena Mini Soccer
        $venue3 = Venue::create([
            'name' => 'Arena Mini Soccer Plus',
            'slug' => 'arena-mini-soccer-plus',
            'description' => 'Lapangan mini soccer outdoor dengan rumput sintetis berkualitas',
            'address' => 'Jl. Sudirman No. 15, Jakarta Selatan',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'latitude' => -6.2215,
            'longitude' => 106.8192,
            'image_url' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?w=800&h=600&fit=crop',
            'facebook_url' => null,
            'instagram_url' => null,
        ]);

        $venue3->facilities()->attach([
            $toiletFacility->id,
            $parkirFacility->id,
        ]);

        $field4 = Field::create([
            'venue_id' => $venue3->id,
            'name' => 'Lapangan Utama',
            'field_type' => 'minisoccer'
        ]);

        $this->createTimeSlots($field4->id, [
            ['08:00:00', '09:00:00', 150000],
            ['09:00:00', '10:00:00', 150000],
            ['10:00:00', '11:00:00', 150000],
            ['11:00:00', '12:00:00', 150000],
            ['12:00:00', '13:00:00', 150000],
            ['13:00:00', '14:00:00', 150000],
            ['14:00:00', '15:00:00', 180000],
            ['15:00:00', '16:00:00', 180000],
            ['16:00:00', '17:00:00', 200000],
            ['17:00:00', '18:00:00', 250000],
            ['18:00:00', '19:00:00', 250000],
            ['19:00:00', '20:00:00', 250000],
            ['20:00:00', '21:00:00', 250000],
            ['21:00:00', '22:00:00', 250000],
            ['22:00:00', '23:00:00', 250000],
            ['23:00:00', '24:00:00', 250000],
        ]);

        $this->command->info('✓ Venues seeded successfully!');
        $this->command->info('✓ Created 3 venues with fields and time slots');
    }

    private function createTimeSlots($fieldId, array $slots)
    {
        foreach ($slots as $slot) {
            TimeSlot::create([
                'field_id' => $fieldId,
                'start_time' => $slot[0],
                'end_time' => $slot[1],
                'price' => $slot[2],
            ]);
        }
    }
}
