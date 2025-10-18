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
     * Get all venues (OPTIMIZED - tanpa time slots)
     */
    public function index(Request $request)
    {
        try {
            // Load fields tapi TANPA time slots untuk performa
            $query = Venue::with([
                'fields:id,venue_id,name,field_type,description',
                'facilities',
                'images'
            ]);

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // City filter
            if ($request->filled('city')) {
                $query->where('address', 'like', "%{$request->city}%");
            }

            // Sort
            $sortBy = $request->get('sort', 'created_at');
            if ($sortBy === 'name') {
                $query->orderBy('name', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $venues = $query->get();

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
                'message' => 'Failed to fetch venues',
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
            Log::info('Fetching venue detail', ['identifier' => $identifier]);

            // Load fields dengan detail lengkap tapi TANPA time slots
            // Time slots akan diload saat user pilih tanggal di available-slots endpoint
            $venue = Venue::with([
                'fields:id,venue_id,name,field_type,description',
                'facilities',
                'images'
            ])
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->first();

            if (!$venue) {
                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan',
                ], 404);
            }

            Log::info('Venue found', [
                'id' => $venue->id,
                'name' => $venue->name,
                'fields_count' => $venue->fields->count()
            ]);

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
                'message' => 'Gagal mengambil data venue',
            ], 500);
        }
    }

    /**
     * Get available slots (OPTIMIZED dengan index)
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

            Log::info('Getting available slots', [
                'venue_id' => $venueId,
                'field_id' => $fieldId,
                'date' => $date
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

            // Get booked slots (OPTIMIZED dengan index)
            $bookedSlotIds = Booking::where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->whereIn('status', ['pending', 'confirmed', 'completed'])
                ->pluck('time_slot_id')
                ->toArray();

            Log::info('Booked slots', [
                'date' => $date,
                'booked_count' => count($bookedSlotIds),
                'booked_ids' => $bookedSlotIds
            ]);

            // Map availability
            $slotsWithStatus = $allSlots->map(function ($slot) use ($bookedSlotIds) {
                $isBooked = in_array($slot->id, $bookedSlotIds);

                return [
                    'id' => $slot->id,
                    'field_id' => $slot->field_id,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'price' => $slot->price,
                    'is_available' => !$isBooked,
                    'booking_status' => $isBooked ? 'booked' : 'available',
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
}
