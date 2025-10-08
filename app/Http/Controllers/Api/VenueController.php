<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;



class VenueController extends Controller
{
    /**
     * Get all venues
     */
    public function index(Request $request)
    {
        try {
            $query = Venue::with(['fields.timeSlots', 'facilities', 'images']);

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // City filter
            if ($request->has('city')) {
                $query->where('address', 'like', "%{$request->city}%");
            }

            // Sort
            if ($request->has('sort')) {
                switch ($request->sort) {
                    case 'price':
                        // Sort by minimum price (requires subquery)
                        break;
                    case 'name':
                        $query->orderBy('name', 'asc');
                        break;
                    default:
                        $query->orderBy('created_at', 'desc');
                }
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $venues = $query->get();

            // Log untuk debugging
            Log::info('Venues fetched', ['count' => $venues->count()]);

            return response()->json([
                'success' => true,
                'message' => 'Venues retrieved successfully',
                'data' => $venues,
                'meta' => [
                    'total' => $venues->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching venues', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch venues: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Get venue by ID or slug
     */
    public function show($identifier)
    {
        try {
            Log::info('Fetching venue', ['identifier' => $identifier]);

            // Try to find by ID first, then by slug
            $venue = Venue::with([
                'fields.timeSlots',
                'facilities',
                'images'
            ])
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->first();

            if (!$venue) {
                Log::warning('Venue not found', ['identifier' => $identifier]);

                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan',
                    'data' => null
                ], 404);
            }

            Log::info('Venue found', ['id' => $venue->id, 'name' => $venue->name]);

            return response()->json([
                'success' => true,
                'message' => 'Venue retrieved successfully',
                'data' => $venue
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching venue', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data venue: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get available time slots for a venue
     */
    public function getAvailableSlots(Request $request, $venueId)
    {
        try {
            $request->validate([
                'field_id' => 'required|exists:fields,id',
                'date' => 'required|date|after_or_equal:today'
            ]);

            $venue = Venue::findOrFail($venueId);
            $fieldId = $request->field_id;
            $date = $request->date;

            // Get all time slots for this field
            $allSlots = \App\Models\TimeSlot::where('field_id', $fieldId)->get();

            // Get booked slots for this date
            $bookedSlotIds = \App\Models\Booking::where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->pluck('time_slot_id')
                ->toArray();

            // Filter available slots
            $availableSlots = $allSlots->filter(function ($slot) use ($bookedSlotIds) {
                return !in_array($slot->id, $bookedSlotIds);
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Available slots retrieved successfully',
                'data' => $availableSlots
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching available slots', [
                'venue_id' => $venueId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil slot jadwal: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }
}
