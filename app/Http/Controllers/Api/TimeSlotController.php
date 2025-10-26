<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeSlot;
use App\Models\Field; // Diperlukan untuk cek kepemilikan
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TimeSlotController extends Controller
{
    /**
     * Helper untuk mengecek kepemilikan field
     */
    private function checkFieldOwnership($fieldId)
    {
        $user = Auth::user();
        if ($user->role === 'super_admin') {
            return Field::find($fieldId);
        }

        $field = Field::where('id', $fieldId)
            ->whereHas('venue', function ($query) use ($user) {
                $query->where('owner_id', $user->id);
            })
            ->first();

        return $field;
    }

    /**
     * Helper untuk mengecek kepemilikan time slot
     */
    private function checkSlotOwnership($slotId)
    {
        $user = Auth::user();
        if ($user->role === 'super_admin') {
            return TimeSlot::find($slotId);
        }

        $slot = TimeSlot::where('id', $slotId)
            ->whereHas('field.venue', function ($query) use ($user) {
                $query->where('owner_id', $user->id);
            })
            ->first();

        return $slot;
    }

    /**
     * Menampilkan semua time slot, bisa difilter by field_id
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = TimeSlot::with('field:id,name', 'field.venue:id,name');

        // Filter berdasarkan field_id jika ada
        if ($request->has('field_id')) {
            $query->where('field_id', $request->field_id);
        }

        // Admin hanya bisa melihat slot milik venuenya
        if ($user->role === 'admin') {
            $query->whereHas('field.venue', function ($q) use ($user) {
                $q->where('owner_id', $user->id);
            });
        }

        $timeSlots = $query->orderBy('field_id')->orderBy('start_time')->get();

        return response()->json([
            'success' => true,
            'data' => $timeSlots,
        ]);
    }

    /**
     * Menyimpan time slot baru
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'field_id' => 'required|integer|exists:fields,id',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s|after:start_time',
                'price' => 'required|numeric|min:0',
            ]);

            // Cek apakah admin memiliki field tersebut
            $field = $this->checkFieldOwnership($validated['field_id']);
            if (!$field) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke lapangan ini.'
                ], 403);
            }

            // Cek overlapping slot
            $exists = TimeSlot::where('field_id', $validated['field_id'])
                ->where(function ($query) use ($validated) {
                    $query->where(function ($q) use ($validated) {
                        $q->where('start_time', '<', $validated['end_time'])
                            ->where('end_time', '>', $validated['start_time']);
                    });
                })->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal slot tumpang tindih dengan slot yang sudah ada.',
                    'errors' => ['start_time' => ['Jadwal slot tumpang tindih']]
                ], 422);
            }

            $timeSlot = TimeSlot::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Time slot berhasil dibuat',
                'data' => $timeSlot->load('field:id,name')
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating time slot', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat time slot: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail time slot
     */
    public function show($id)
    {
        $slot = $this->checkSlotOwnership($id);

        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'Time slot tidak ditemukan atau Anda tidak memiliki akses'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $slot->load('field:id,name', 'field.venue:id,name')
        ]);
    }

    /**
     * Update time slot
     */
    public function update(Request $request, $id)
    {
        try {
            $slot = $this->checkSlotOwnership($id);

            if (!$slot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Time slot tidak ditemukan atau Anda tidak memiliki akses'
                ], 404);
            }

            $validated = $request->validate([
                'field_id' => 'sometimes|required|integer|exists:fields,id',
                'start_time' => 'sometimes|required|date_format:H:i:s',
                'end_time' => 'sometimes|required|date_format:H:i:s|after:start_time',
                'price' => 'sometimes|required|numeric|min:0',
            ]);

            // Jika field_id diubah, cek kepemilikan field baru
            if ($request->has('field_id') && $validated['field_id'] != $slot->field_id) {
                $field = $this->checkFieldOwnership($validated['field_id']);
                if (!$field) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke lapangan baru tersebut.'
                    ], 403);
                }
            }

            // Cek overlapping slot (kecuali slot ini sendiri)
            $fieldId = $validated['field_id'] ?? $slot->field_id;
            $startTime = $validated['start_time'] ?? $slot->start_time;
            $endTime = $validated['end_time'] ?? $slot->end_time;

            $exists = TimeSlot::where('field_id', $fieldId)
                ->where('id', '!=', $id) // <-- Kecualikan diri sendiri
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);
                    });
                })->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal slot tumpang tindih dengan slot yang sudah ada.',
                    'errors' => ['start_time' => ['Jadwal slot tumpang tindih']]
                ], 422);
            }

            $slot->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Time slot berhasil diupdate',
                'data' => $slot->load('field:id,name')
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating time slot', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate time slot: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hapus time slot
     */
    public function destroy($id)
    {
        $slot = $this->checkSlotOwnership($id);

        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'Time slot tidak ditemukan atau Anda tidak memiliki akses'
            ], 404);
        }

        // TODO: Cek apakah slot ini sudah dibooking?
        // if ($slot->bookings()->whereIn('status', ['confirmed', 'pending'])->exists()) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Tidak bisa menghapus slot yang sudah memiliki data booking aktif.'
        //     ], 403);
        // }

        $slot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Time slot berhasil dihapus'
        ]);
    }

    /**
     * Dapatkan daftar simple {id, name} dari fields milik admin
     */
    public function getMyFieldsList(Request $request)
    {
        try {
            $user = $request->user();
            $query = Field::with('venue:id,name');

            if ($user->role === 'admin') {
                $query->whereHas('venue', function ($q) use ($user) {
                    $q->where('owner_id', $user->id);
                });
            }

            $fields = $query->select('id', 'venue_id', 'name')->orderBy('venue_id')->orderBy('name')->get();

            // Format nama agar menyertakan nama venue
            $formattedFields = $fields->map(function ($field) {
                // Pastikan relasi venue ter-load
                if (!$field->venue) {
                    return null;
                }
                return [
                    'id' => $field->id,
                    'name' => $field->venue->name . ' - ' . $field->name
                ];
            })->filter(); // Hapus item null jika ada

            return response()->json([
                'success' => true,
                'data' => $formattedFields
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching my-fields list', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar lapangan'
            ], 500);
        }
    }
}
