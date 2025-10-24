<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Field;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FieldController extends Controller
{
    /**
     * Menampilkan daftar lapangan (fields).
     * Disaring berdasarkan kepemilikan venue untuk role 'admin'.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Field::query();

        // Jika rolenya 'admin', filter lapangan berdasarkan venue yang dia miliki
        if ($user->role === 'admin') {
            $venueIds = $user->venues()->pluck('id');
            $query->whereIn('venue_id', $venueIds);
        }
        $fields = $query->with('venue:id,name')
            ->orderBy('venue_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $fields
        ]);
    }

    /**
     * Menyimpan lapangan baru.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'venue_id' => 'required|integer|exists:venues,id',
            'name' => 'required|string|max:255',
            'field_type' => [
                'required',
                Rule::in(['futsal', 'minisoccer', 'other']),
            ],
            'description' => 'nullable|string',
        ]);


        if ($user->role === 'admin') {
            $venue = Venue::find($validated['venue_id']);
            if (!$venue || $venue->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Anda tidak memiliki akses ke venue ini.'
                ], 403);
            }
        }

        $field = Field::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Lapangan berhasil dibuat',
            'data' => $field->load('venue:id,name')
        ], 201);
    }

    /**
     * Menampilkan detail satu lapangan.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $field = Field::with('venue:id,name', 'timeSlots')->find($id);

        if (!$field) {
            return response()->json(['success' => false, 'message' => 'Lapangan tidak ditemukan'], 404);
        }

        if ($user->role === 'admin' && $field->venue->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Anda tidak memiliki akses ke lapangan ini.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $field
        ]);
    }

    /**
     * Mengupdate data lapangan.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $field = Field::find($id);

        if (!$field) {
            return response()->json(['success' => false, 'message' => 'Lapangan tidak ditemukan'], 404);
        }
        $venue = Venue::find($field->venue_id);
        if ($user->role === 'admin' && $venue->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Anda tidak memiliki akses ke lapangan ini.'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'field_type' => [
                'sometimes',
                'required',
                Rule::in(['futsal', 'minisoccer', 'other']),
            ],
            'description' => 'nullable|string',
        ]);

        $field->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Lapangan berhasil diupdate',
            'data' => $field->load('venue:id,name')
        ]);
    }

    /**
     * Menghapus lapangan.
     **/
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $field = Field::find($id);

        if (!$field) {
            return response()->json(['success' => false, 'message' => 'Lapangan tidak ditemukan'], 404);
        }

        $venue = Venue::find($field->venue_id);
        if ($user->role === 'admin' && $venue->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Anda tidak memiliki akses ke lapangan ini.'
            ], 403);
        }

        try {
            $field->delete();

            return response()->json([
                'success' => true,
                'message' => 'Lapangan berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal hapus lapangan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus lapangan. Mungkin sudah ada booking terkait.'
            ], 500);
        }
    }
}
