<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FacilityController extends Controller
{
    /**
     * Mengambil SEMUA fasilitas yang tersedia di sistem (master data)
     */
    public function index()
    {
        $facilities = Facility::orderBy('name')->get(['id', 'name']);
        return response()->json([
            'success' => true,
            'data' => $facilities
        ]);
    }

    /**
     * Mengambil fasilitas yang dimiliki oleh SATU venue (berupa array ID)
     */
    public function getVenueFacilities($venueId)
    {
        $venue = Venue::find($venueId);
        if (!$venue) {
            return response()->json(['success' => false, 'message' => 'Venue tidak ditemukan'], 404);
        }

        // Cek kepemilikan
        $user = Auth::user();
        if ($user->role === 'admin' && $venue->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
        }

        $facilityIds = $venue->facilities()->pluck('facilities.id');

        return response()->json([
            'success' => true,
            'data' => $facilityIds
        ]);
    }

    /**
     * Mensinkronkan (update) fasilitas untuk SATU venue
     */
    public function syncVenueFacilities(Request $request, $venueId)
    {
        $venue = Venue::find($venueId);
        if (!$venue) {
            return response()->json(['success' => false, 'message' => 'Venue tidak ditemukan'], 404);
        }

        // Cek kepemilikan
        $user = Auth::user();
        if ($user->role === 'admin' && $venue->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak'], 403);
        }

        try {
            $validated = $request->validate([
                'facility_ids' => 'present|array', // 'present' berarti field harus ada, meski kosong
                'facility_ids.*' => 'integer|exists:facilities,id' // Pastikan semua ID valid
            ]);

            $venue->facilities()->sync($validated['facility_ids']);

            Log::info('Facilities synced for venue', ['venue_id' => $venueId, 'facilities' => $validated['facility_ids']]);

            return response()->json([
                'success' => true,
                'message' => 'Fasilitas venue berhasil diperbarui',
                'data' => $validated['facility_ids']
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error syncing facilities', ['venue_id' => $venueId, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui fasilitas: ' . $e->getMessage()
            ], 500);
        }
    }
}
