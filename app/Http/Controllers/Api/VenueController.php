<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\Field;
use App\Models\TimeSlot;
use App\Models\Booking;
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
    /**
     * Get time slots with booking status for a venue
     */
    public function getAvailableSlots(Request $request, $venueId)
    {
        try {
            Log::info('Getting slots with booking status', [
                'venue_id' => $venueId,
                'field_id' => $request->field_id,
                'date' => $request->date
            ]);

            // Validate request
            $validated = $request->validate([
                'field_id' => 'required|integer',
                'date' => 'required|date_format:Y-m-d'
            ]);

            // Check if venue exists
            $venue = Venue::find($venueId);
            if (!$venue) {
                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan',
                    'data' => []
                ], 404);
            }

            // Check if field exists and belongs to this venue
            $field = Field::where('id', $validated['field_id'])
                ->where('venue_id', $venueId)
                ->first();

            if (!$field) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lapangan tidak ditemukan',
                    'data' => []
                ], 404);
            }

            $fieldId = $validated['field_id'];
            $date = $validated['date'];

            // Get all time slots for this field
            $allSlots = TimeSlot::where('field_id', $fieldId)
                ->orderBy('start_time', 'asc')
                ->get();

            if ($allSlots->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tidak ada slot tersedia untuk lapangan ini',
                    'data' => []
                ]);
            }

            // Get booked slots for this date with booking details
            $bookedSlots = Booking::where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->with(['payment'])
                ->get()
                ->keyBy('time_slot_id');

            Log::info('Booked slots found', [
                'date' => $date,
                'count' => $bookedSlots->count(),
                'booked_ids' => $bookedSlots->keys()->toArray()
            ]);

            // Add booking status to each slot
            $slotsWithStatus = $allSlots->map(function ($slot) use ($bookedSlots) {
                $booking = $bookedSlots->get($slot->id);

                return [
                    'id' => $slot->id,
                    'field_id' => $slot->field_id,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'price' => $slot->price,
                    'is_available' => !$booking,
                    'booking_status' => $booking ? 'booked' : 'available',
                    'booking_info' => $booking ? [
                        'booking_number' => $booking->booking_number,
                        'customer_name' => $booking->customer_name,
                        'status' => $booking->status,
                        'payment_status' => $booking->payment?->payment_status ?? 'unknown'
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Slots retrieved successfully',
                'data' => $slotsWithStatus->values(),
                'meta' => [
                    'total_slots' => $allSlots->count(),
                    'available_slots' => $slotsWithStatus->where('is_available', true)->count(),
                    'booked_slots' => $slotsWithStatus->where('is_available', false)->count()
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors(),
                'data' => []
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching slots', [
                'venue_id' => $venueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil slot jadwal: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }
}
