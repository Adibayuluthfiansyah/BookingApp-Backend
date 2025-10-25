<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\Field;
use App\Models\TimeSlot;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class VenueController extends Controller
{
    /**
     * Get all venues (filtered by owner for admin)
     */
    public function index(Request $request)
    {
        try {
            $query = Venue::with(['fields.timeSlots', 'facilities', 'images', 'owner:id,name,email']);

            // Filter by owner jika bukan super admin
            $user = Auth::user();
            if ($user && $user->role === 'admin') {
                $query->where('owner_id', $user->id);
            }

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

            Log::info('Venues fetched', [
                'count' => $venues->count(),
                'user_id' => $user?->id,
                'role' => $user?->role
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Venues retrieved successfully',
                'data' => $venues,
                'meta' => [
                    'total' => $venues->count(),
                    'is_filtered' => $user && $user->role === 'admin'
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
     * Get venue by ID or slug (with owner check)
     */
    public function show($identifier)
    {
        try {
            Log::info('Fetching venue', ['identifier' => $identifier]);

            $query = Venue::with([
                'fields.timeSlots',
                'facilities',
                'images',
                'owner:id,name,email'
            ])
                ->where('id', $identifier)
                ->orWhere('slug', $identifier);

            // Filter by owner jika admin
            $user = Auth::user();
            if ($user && $user->role === 'admin') {
                $query->where('owner_id', $user->id);
            }

            $venue = $query->first();

            if (!$venue) {
                Log::warning('Venue not found or access denied', [
                    'identifier' => $identifier,
                    'user_id' => $user?->id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan atau Anda tidak memiliki akses',
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
     * Create new venue (Admin only)
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'required|string',
                'address' => 'required|string',
                'phone' => 'required|string|max:20',
                'email' => 'required|email',
                // ... validasi lainnya
            ]);

            $user = Auth::user();

            // Auto-assign owner_id
            $validated['owner_id'] = $user->id;

            // Generate slug
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);

            $venue = Venue::create($validated);

            Log::info('Venue created', [
                'venue_id' => $venue->id,
                'owner_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Venue berhasil dibuat',
                'data' => $venue->load('owner')
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating venue', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat venue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update venue (Admin only, must own the venue)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();

            $venue = Venue::find($id);

            if (!$venue) {
                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan'
                ], 404);
            }

            // Check ownership (kecuali super admin)
            if ($user->role !== 'super_admin' && $venue->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengupdate venue ini'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'address' => 'sometimes|string',
                // ... validasi lainnya
            ]);

            $venue->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Venue berhasil diupdate',
                'data' => $venue->load('owner')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating venue', [
                'venue_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate venue'
            ], 500);
        }
    }

    /**
     * Delete venue (Admin only, must own the venue)
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();

            $venue = Venue::find($id);

            if (!$venue) {
                return response()->json([
                    'success' => false,
                    'message' => 'Venue tidak ditemukan'
                ], 404);
            }

            // Check ownership
            if ($user->role !== 'super_admin' && $venue->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus venue ini'
                ], 403);
            }

            $venue->delete();

            return response()->json([
                'success' => true,
                'message' => 'Venue berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting venue', [
                'venue_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus venue'
            ], 500);
        }
    }


    /**
     * Get a simple list of venues (ID and Name) owned by the admin.
     * Digunakan untuk dropdown form.
     */
    public function getMyVenuesList(Request $request)
    {
        try {
            $user = $request->user();
            $query = Venue::query();

            if ($user->role === 'admin') {
                $query->where('owner_id', $user->id);
            }
            // super_admin akan mendapatkan semua

            $venues = $query->select('id', 'name')->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $venues
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching my-venues list', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar venue'
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
            $bookedSlotIds = [];

            if ($date > $today) {
                // Future dates - all booked slots count
                $bookedSlotIds = Booking::where('field_id', $fieldId)
                    ->where('booking_date', $date)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->pluck('time_slot_id')
                    ->toArray();
            } elseif ($date === $today) {
                // Today - only slots that haven't ended
                $bookedSlotIds = Booking::where('field_id', $fieldId)
                    ->where('booking_date', $date)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->whereHas('timeSlot', function ($query) use ($currentTime) {
                        $query->where('end_time', '>', $currentTime);
                    })
                    ->pluck('time_slot_id')
                    ->toArray();

                // Auto-complete past bookings (background cleanup)
                $this->autoCompletePastBookings($fieldId, $date, $currentTime);
            } else {
                $bookedSlotIds = Booking::where('field_id', $fieldId)
                    ->where('booking_date', $date)
                    ->whereIn('status', ['confirmed', 'completed'])
                    ->pluck('time_slot_id')
                    ->toArray();
            }

            // Map availability with past slot detection
            $slotsWithStatus = $allSlots->map(function ($slot) use ($bookedSlotIds, $date, $today, $currentTime) {
                $isBooked = in_array($slot->id, $bookedSlotIds);

                // Check if slot is in the past
                $isPastSlot = false;
                if ($date < $today) {
                    $isPastSlot = true;
                } elseif ($date === $today && $slot->end_time <= $currentTime) {
                    $isPastSlot = true;
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
        } catch (\Exception $e) {
            Log::error('Error fetching slots', [
                'venue_id' => $venueId,
                'error' => $e->getMessage()
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
            $completed = Booking::where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->where('end_time', '<=', $currentTime)
                ->whereIn('status', ['confirmed', 'paid'])
                ->update(['status' => 'completed']);

            if ($completed > 0) {
                Log::info('Auto-completed past bookings', [
                    'field_id' => $fieldId,
                    'date' => $date,
                    'completed_count' => $completed
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error auto-completing past bookings', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
