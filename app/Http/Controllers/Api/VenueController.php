<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    // Get all venues with pagination and search
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['fields' => function ($query) {
            // jika field punya kolom status, biarkan baris ini
            $query->where('status', 'active');
        }, 'facilities', 'images']);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if ($request->has('type') && !empty($request->type)) {
            $query->whereHas('fields', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

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

        $venues->getCollection()->transform(function ($venue) {
            return [
                'id' => $venue->id,
                'slug' => $venue->slug,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'phone' => $venue->phone,
                'price_per_hour' => $venue->price_per_hour,
                'formatted_price' => $venue->formatted_price,
                'image' => $venue->image_url,
                'facilities' => $venue->facilities->pluck('name')->toArray(),
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
            ]
        ]);
    }

    // Get single venue by ID or slug
    public function show($identifier): JsonResponse
    {
        // Support both ID and slug
        $venue = Venue::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->with(['fields' => function ($query) {
                $query->orderBy('name', 'asc');
            }, 'facilities', 'images'])
            ->first();

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
                'slug' => $venue->slug,
                'name' => $venue->name,
                'description' => $venue->description,
                'address' => $venue->address,
                'city' => $venue->city,
                'province' => $venue->province,
                'phone' => $venue->phone ?? null,
                'price_per_hour' => $venue->price_per_hour ?? 0,
                'formatted_price' => $venue->formatted_price ?? 'Rp 0',
                'image' => $venue->image_url,
                'facebook_url' => $venue->facebook_url,
                'instagram_url' => $venue->instagram_url,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'status' => $venue->status,
                'facilities' => $venue->facilities->map(function ($facility) {
                    return [
                        'id' => $facility->id,
                        'name' => $facility->name,
                    ];
                }),
                'images' => $venue->images->map(function ($img) {
                    return [
                        'id' => $img->id,
                        'image_url' => $img->image_url,
                        'caption' => $img->caption
                    ];
                }),
                'fields' => $venue->fields->map(function ($field) {
                    return [
                        'id' => $field->id,
                        'name' => $field->name,
                        'type' => $field->type,
                        'status' => $field->status,
                        'description' => $field->description ?? null,
                        'price_per_hour' => $field->price ?? 0,
                        'formatted_price' => $field->formatted_price ?? 'Rp 0'
                    ];
                })
            ]
        ]);
    }

    // Get venue schedule
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

        $timeSlots = [];
        for ($hour = 7; $hour < 24; $hour++) {
            $startTime = sprintf('%02d:00:00', $hour);
            $endTime = sprintf('%02d:00:00', $hour + 1);

            if ($hour == 23) {
                $endTime = '00:00:00';
            }

            $currentPrice = $venue->price_per_hour ?? 150000;

            if ($hour >= 17 && $hour <= 20) {
                $currentPrice = $currentPrice * 1.2;
            }

            if ($hour >= 22 || $hour <= 6) {
                $currentPrice = $currentPrice * 1.1;
            }

            $isAvailable = $this->checkTimeSlotAvailability($venue->id, $fieldId, $date, $startTime);

            $timeSlots[] = [
                'id' => $hour,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'price' => round($currentPrice, 0),
                'available' => $isAvailable,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $timeSlots
        ]);
    }

    private function checkTimeSlotAvailability($venueId, $fieldId, $date, $startTime): bool
    {
        $hour = intval(substr($startTime, 0, 2));

        if ($hour >= 17 && $hour <= 20) {
            return rand(1, 10) > 7;
        }

        if ($hour <= 8) {
            return rand(1, 10) > 2;
        }

        if ($hour >= 22) {
            return rand(1, 10) > 3;
        }

        return rand(1, 10) > 4;
    }
}
