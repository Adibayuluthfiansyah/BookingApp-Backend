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
     * Get available slots (OPTIMIZED dengan auto-release past slots)
     */
    public function getAvailableSlots(Request $request, $venueId)
    {
        try {
            $validated = $request->validate([
                'field_id' => 'required|integer',
                'date' => 'required|date_format:Y-m-d'
            ]);

            $fieldId = $validated['field_id'];
            $date = $validated['date'];

            // Get current date and time
            $now = now();
            $today = $now->format('Y-m-d');
            $currentTime = $now->format('H:i:s');

            Log::info('Getting available slots', [
                'venue_id' => $venueId,
                'field_id' => $fieldId,
                'date' => $date,
                'current_time' => $currentTime,
                'is_today' => $date === $today
            ]);

            // Check venue exists
            $venue = Venue::find($venueId);
            if (!$venue) {
                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan',
                ], 404);
            }

            // Check field belongs to venue
            $field = Field::where('id', $fieldId)
                ->where('venue_id', $venueId)
                ->first();

            if (!$field) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lapangan tidak ditemukan',
                ], 404);
            }

            // Get all time slots
            $allSlots = TimeSlot::where('field_id', $fieldId)
                ->select('id', 'field_id', 'start_time', 'end_time', 'price')
                ->orderBy('start_time', 'asc')
                ->get();

            if ($allSlots->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tidak ada slot tersedia',
                    'data' => []
                ]);
            }

            // Get booked slots (ONLY those that are still relevant)
            // SMART LOGIC:
            // - For past dates: return empty (all slots available)
            // - For future dates: get all booked slots
            // - For today: only get slots that haven't ended yet
            $bookedSlotIds = [];

            if ($date > $today) {
                // Future dates - all booked slots count
                $bookedSlotIds = Booking::where('field_id', $fieldId)
                    ->where('booking_date', $date)
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->pluck('time_slot_id')
                    ->toArray();
            } elseif ($date === $today) {
                // Today - only slots that haven't ended
                $bookedSlotIds = Booking::where('field_id', $fieldId)
                    ->where('booking_date', $date)
                    ->whereIn('status', ['pending', 'confirmed', 'paid'])
                    ->whereHas('timeSlot', function ($query) use ($currentTime) {
                        $query->where('end_time', '>', $currentTime);
                    })
                    ->pluck('time_slot_id')
                    ->toArray();

                // Auto-complete past bookings (background cleanup)
                $this->autoCompletePastBookings($fieldId, $date, $currentTime);
            }
            // else: past dates ($date < $today) - $bookedSlotIds remains empty array

            Log::info('Booked slots', [
                'date' => $date,
                'is_past' => $date < $today,
                'is_today' => $date === $today,
                'is_future' => $date > $today,
                'booked_count' => count($bookedSlotIds),
                'booked_ids' => $bookedSlotIds
            ]);

            // Map availability with past slot detection
            $slotsWithStatus = $allSlots->map(function ($slot) use ($bookedSlotIds, $date, $today, $currentTime) {
                $isBooked = in_array($slot->id, $bookedSlotIds);

                // Check if slot is in the past
                $isPastSlot = false;
                if ($date < $today) {
                    $isPastSlot = true; // All slots on past dates
                } elseif ($date === $today && $slot->end_time <= $currentTime) {
                    $isPastSlot = true; // Today's slots that have ended
                }

                return [
                    'id' => $slot->id,
                    'field_id' => $slot->field_id,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'price' => $slot->price,
                    'is_available' => !$isBooked,
                    'booking_status' => $isBooked ? 'booked' : 'available',
                    'is_past_slot' => $isPastSlot,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Slots retrieved successfully',
                'data' => $slotsWithStatus->values(),
                'meta' => [
                    'total_slots' => $allSlots->count(),
                    'available_slots' => $slotsWithStatus->where('is_available', true)->count(),
                    'booked_slots' => $slotsWithStatus->where('is_available', false)->count(),
                    'date' => $date,
                    'current_time' => $currentTime,
                    'is_today' => $date === $today,
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching slots', [
                'venue_id' => $venueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil slot jadwal',
            ], 500);
        }
    }

    /**
     * Auto-complete past bookings (helper method)
     */
    private function autoCompletePastBookings($fieldId, $date, $currentTime)
    {
        try {
            // Find and auto-complete bookings that have ended
            $completed = Booking::where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->where('end_time', '<=', $currentTime)
                ->whereIn('status', ['confirmed', 'paid'])
                ->update(['status' => 'completed']);

            if ($completed > 0) {
                Log::info('Auto-completed past bookings', [
                    'field_id' => $fieldId,
                    'date' => $date,
                    'current_time' => $currentTime,
                    'completed_count' => $completed
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error auto-completing past bookings', [
                'error' => $e->getMessage()
            ]);
            // Don't throw - this is background cleanup
        }
    }
}
