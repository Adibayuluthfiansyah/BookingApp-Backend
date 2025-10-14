<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Field;
use App\Models\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Midtrans\Config;
use Midtrans\Snap;

class BookingController extends Controller
{
    public function __construct()
    {
        // Set Midtrans configuration
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        // Disable SSL verify for development (remove in production!)
        Config::$curlOptions = [
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ];
    }

    public function createBooking(Request $request)
    {
        try {
            $validated = $request->validate([
                'field_id' => 'required|exists:fields,id',
                'time_slot_id' => 'required|exists:time_slots,id',
                'booking_date' => 'required|date|after_or_equal:today',
                'customer_name' => 'required|string|max:255',
                'customer_phone' => 'required|string|max:20',
                'customer_email' => 'required|email|max:255',
                'notes' => 'nullable|string',
            ]);

            // Check if slot is available
            $existingBooking = Booking::where('field_id', $validated['field_id'])
                ->where('time_slot_id', $validated['time_slot_id'])
                ->where('booking_date', $validated['booking_date'])
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->exists();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slot sudah dibooking, silakan pilih slot lain',
                ], 422);
            }

            // Get time slot and calculate price
            $timeSlot = TimeSlot::findOrFail($validated['time_slot_id']);
            $subtotal = $timeSlot->price;
            $adminFee = 5000; // Rp 5.000
            $totalAmount = $subtotal + $adminFee;

            DB::beginTransaction();

            // Create booking
            $booking = Booking::create([
                'field_id' => $validated['field_id'],
                'time_slot_id' => $validated['time_slot_id'],
                'user_id' => Auth::check() ? Auth::id() : null,  // null if not logged in
                'booking_date' => $validated['booking_date'],
                'start_time' => $timeSlot->start_time,
                'end_time' => $timeSlot->end_time,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'],
                'notes' => $validated['notes'],
                'subtotal' => $subtotal,
                'admin_fee' => $adminFee,
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);

            // Create Midtrans transaction
            $field = Field::with('venue')->find($validated['field_id']);

            $transactionDetails = [
                'order_id' => $booking->booking_number,
                'gross_amount' => (int) $totalAmount,
            ];

            $itemDetails = [
                [
                    'id' => 'booking-' . $booking->id,
                    'price' => (int) $subtotal,
                    'quantity' => 1,
                    'name' => "{$field->venue->name} - {$field->name}",
                ],
                [
                    'id' => 'admin-fee',
                    'price' => (int) $adminFee,
                    'quantity' => 1,
                    'name' => 'Biaya Admin',
                ],
            ];

            $customerDetails = [
                'first_name' => $validated['customer_name'],
                'email' => $validated['customer_email'],
                'phone' => $validated['customer_phone'],
            ];

            $params = [
                'transaction_details' => $transactionDetails,
                'item_details' => $itemDetails,
                'customer_details' => $customerDetails,
                'callbacks' => [
                    'finish' => env('FRONTEND_URL') . '/booking/success?order_id=' . $booking->booking_number,
                ],
            ];

            $snapToken = Snap::getSnapToken($params);

            // Create payment record
            Payment::create([
                'booking_id' => $booking->id,
                'amount' => $totalAmount,
                'payment_method' => 'midtrans',
                'status' => 'pending',
                'snap_token' => $snapToken,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Booking berhasil dibuat',
                'data' => [
                    'booking' => $booking->load('field.venue', 'timeSlot'),
                    'snap_token' => $snapToken,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking creation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function handleCallback(Request $request)
    {
        try {
            $orderID = $request->order_id;
            $statusCode = $request->status_code;
            $grossAmount = $request->gross_amount;
            $signatureKey = $request->signature_key;

            // Verify signature
            $serverKey = config('midtrans.server_key');
            $hash = hash('sha512', $orderID . $statusCode . $grossAmount . $serverKey);

            if ($hash !== $signatureKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 403);
            }

            $booking = Booking::where('booking_number', $orderID)->firstOrFail();
            $payment = $booking->payment;

            $transactionStatus = $request->transaction_status;
            $fraudStatus = $request->fraud_status ?? null;

            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'accept') {
                    $booking->status = 'paid';
                    $payment->status = 'paid';
                }
            } elseif ($transactionStatus == 'settlement') {
                $booking->status = 'paid';
                $payment->status = 'paid';
            } elseif ($transactionStatus == 'pending') {
                $booking->status = 'pending';
                $payment->status = 'pending';
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $booking->status = 'cancelled';
                $payment->status = 'failed';
            }

            $booking->save();
            $payment->save();

            return response()->json([
                'success' => true,
                'message' => 'Callback processed',
            ]);
        } catch (\Exception $e) {
            Log::error('Midtrans callback error', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Callback processing failed',
            ], 500);
        }
    }



    public function getBookingStatus($bookingNumber)
    {
        try {
            $booking = Booking::with(['field.venue', 'timeSlot', 'payment'])
                ->where('booking_number', $bookingNumber)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $booking,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Booking tidak ditemukan',
            ], 404);
        }
    }
}
