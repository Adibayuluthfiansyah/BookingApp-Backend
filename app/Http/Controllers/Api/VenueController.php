<?php
// app/Http/Controllers/Api/VenueController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    // Get all venues with pagination and search
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['fields' => function ($query) {
            $query->where('status', 'active');
        }])->active();

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // Filter by type
        if ($request->has('type') && !empty($request->type)) {
            $query->whereHas('fields', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        // Sort by price or rating
        if ($request->has('sort')) {
            switch ($request->sort) {
                case 'price_low':
                    $query->orderBy('price_per_hour', 'asc');
                    break;
                case 'price_high':
                    $query->orderBy('price_per_hour', 'desc');
                    break;
                case 'rating':
                    $query->orderBy('rating', 'desc');
                    break;
                default:
                    $query->orderBy('name', 'asc');
            }
        } else {
            $query->orderBy('name', 'asc');
        }

        $venues = $query->paginate($request->get('per_page', 12));

        // Transform data
        $venues->getCollection()->transform(function ($venue) {
            return [
                'id' => $venue->id,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'phone' => $venue->phone,
                'price_per_hour' => $venue->price_per_hour,
                'formatted_price' => $venue->formatted_price,
                'image' => $venue->image_url,
                'facilities' => $venue->facilities ?? [],
                'status' => $venue->status,
                'fields' => $venue->fields->map(function ($field) {
                    return [
                        'id' => $field->id,
                        'name' => $field->name,
                        'type' => $field->type,
                        'status' => $field->status,
                        'price_per_hour' => $field->price,
                        'formatted_price' => $field->formatted_price
                    ];
                }),
                'fields_count' => $venue->fields->count(),
                'active_fields_count' => $venue->active_fields_count
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $venues->items(),
            'meta' => [
                'current_page' => $venues->currentPage(),
                'last_page' => $venues->lastPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
                'from' => $venues->firstItem(),
                'to' => $venues->lastItem()
            ]
        ]);
    }

    // Get single venue by ID
    public function show($id): JsonResponse
    {
        $venue = Venue::with(['fields' => function ($query) {
            $query->orderBy('name', 'asc');
        }])->find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $venue->id,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'phone' => $venue->phone,
                'price_per_hour' => $venue->price_per_hour,
                'formatted_price' => $venue->formatted_price,
                'image' => $venue->image_url,
                'facilities' => $venue->facilities ?? [],
                'status' => $venue->status,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'fields' => $venue->fields->map(function ($field) {
                    return [
                        'id' => $field->id,
                        'name' => $field->name,
                        'type' => $field->type,
                        'status' => $field->status,
                        'description' => $field->description,
                        'price_per_hour' => $field->price,
                        'formatted_price' => $field->formatted_price
                    ];
                })
            ]
        ]);
    }

    // Get venue schedule/availability with complete time slots (07:00 - 24:00)
    public function schedule($id, Request $request): JsonResponse
    {
        $venue = Venue::with('fields')->find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found'
            ], 404);
        }

        $date = $request->get('date', now()->format('Y-m-d'));
        $fieldId = $request->get('field_id');

        // Generate complete time slots from 07:00 to 24:00
        $timeSlots = [];
        for ($hour = 7; $hour < 24; $hour++) {
            $startTime = sprintf('%02d:00', $hour);
            $endTime = sprintf('%02d:00', $hour + 1);

            // Special case for 23:00 - 24:00 becomes 23:00 - 00:00
            if ($hour == 23) {
                $endTime = '00:00';
            }

            // Determine price based on peak hours
            $currentPrice = $venue->price_per_hour;

            // Peak hours (17:00 - 21:00) might have higher price
            if ($hour >= 17 && $hour <= 20) {
                $currentPrice = $venue->price_per_hour * 1.2; // 20% markup for peak hours
            }

            // Late night hours (22:00 - 06:00) might have different price
            if ($hour >= 22 || $hour <= 6) {
                $currentPrice = $venue->price_per_hour * 1.1; // 10% markup for late hours
            }

            // Mock availability - you can replace this with actual booking logic
            $isAvailable = $this->checkTimeSlotAvailability($venue->id, $fieldId, $date, $startTime);

            $timeSlots[] = [
                'time' => "{$startTime} - {$endTime}",
                'start_time' => $startTime,
                'end_time' => $endTime,
                'price' => round($currentPrice, 0),
                'formatted_price' => 'Rp ' . number_format($currentPrice, 0, ',', '.'),
                'available' => $isAvailable,
                'status' => $isAvailable ? 'available' : 'booked',
                'is_peak_hour' => $hour >= 17 && $hour <= 20,
                'is_late_hour' => $hour >= 22 || $hour <= 6
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'venue_id' => $venue->id,
                'venue_name' => $venue->name,
                'date' => $date,
                'field_id' => $fieldId,
                'fields' => $venue->fields->map(function ($field) {
                    return [
                        'id' => $field->id,
                        'name' => $field->name,
                        'type' => $field->type,
                        'status' => $field->status
                    ];
                }),
                'time_slots' => $timeSlots
            ]
        ]);
    }

    // Helper method to check time slot availability
    private function checkTimeSlotAvailability($venueId, $fieldId, $date, $startTime): bool
    {
        // For demo purposes, return random availability
        // In real implementation, check against bookings table

        // Make some time slots more likely to be booked (realistic simulation)
        $hour = intval(substr($startTime, 0, 2));

        // Peak hours more likely to be booked
        if ($hour >= 17 && $hour <= 20) {
            return rand(1, 10) > 7; // 30% chance available
        }

        // Early morning less likely to be booked
        if ($hour <= 8) {
            return rand(1, 10) > 2; // 80% chance available
        }

        // Late night less likely to be booked
        if ($hour >= 22) {
            return rand(1, 10) > 3; // 70% chance available
        }

        // Normal hours
        return rand(1, 10) > 4; // 60% chance available
    }
}
