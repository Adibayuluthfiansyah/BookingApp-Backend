<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    private string $serverKey;
    private bool $isProduction;
    private string $apiUrl;

    public function __construct()
    {
        $this->serverKey = config('midtrans.server_key');
        $this->isProduction = config('midtrans.is_production', false);
        $this->apiUrl = $this->isProduction
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    /**
     * Create Midtrans transaction
     */
    public function createTransaction(Booking $booking): string
    {
        $field = $booking->field()->with('venue:id,name')->first();

        $payload = $this->buildPayload($booking, $field);

        Log::info('Creating Midtrans transaction', [
            'order_id' => $payload['transaction_details']['order_id']
        ]);

        return $this->sendRequest($payload);
    }

    /**
     * Build payload untuk Midtrans
     */
    private function buildPayload(Booking $booking, $field): array
    {
        return [
            'transaction_details' => [
                'order_id' => $booking->booking_number,
                'gross_amount' => (int) $booking->total_amount,
            ],
            'item_details' => [
                [
                    'id' => 'FIELD_' . $booking->id,
                    'price' => (int) $booking->subtotal,
                    'quantity' => 1,
                    'name' => $this->sanitizeText($field->venue->name . ' - ' . $field->name, 50),
                ],
                [
                    'id' => 'ADMIN_' . $booking->id,
                    'price' => (int) $booking->admin_fee,
                    'quantity' => 1,
                    'name' => 'Biaya Admin',
                ],
            ],
            'customer_details' => [
                'first_name' => $this->sanitizeText($booking->customer_name, 50),
                'email' => $booking->customer_email,
                'phone' => $this->cleanPhoneNumber($booking->customer_phone),
            ],
        ];
    }

    /**
     * Send request to Midtrans API
     */
    private function sendRequest(array $payload): string
    {
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->serverKey . ':')
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception('cURL Error: ' . $curlError);
        }

        if ($httpCode !== 201) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error_messages'][0] ?? 'Unknown error';
            throw new \Exception("Midtrans API Error (HTTP {$httpCode}): {$errorMessage}");
        }

        $responseData = json_decode($response, true);

        if (!isset($responseData['token'])) {
            throw new \Exception('Snap token not found in response');
        }

        return $responseData['token'];
    }

    /**
     * Create payment record
     */
    public function createPaymentRecord(Booking $booking, string $snapToken): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'payment_method' => 'transfer_bank',
            'payment_status' => 'pending',
            'snap_token' => $snapToken,
        ]);
    }

    /**
     * Handle Midtrans callback
     */
    public function handleCallback(array $data): bool
    {
        if (!$this->verifySignature($data)) {
            throw new \Exception('Invalid signature');
        }

        $booking = Booking::where('booking_number', $data['order_id'])->firstOrFail();
        $payment = $booking->payment;

        $this->updateBookingStatus($booking, $payment, $data);

        return true;
    }

    /**
     * Verify Midtrans signature
     */
    private function verifySignature(array $data): bool
    {
        $hash = hash(
            'sha512',
            $data['order_id'] .
                $data['status_code'] .
                $data['gross_amount'] .
                $this->serverKey
        );

        return $hash === $data['signature_key'];
    }

    /**
     * Update booking status based on callback
     */
    private function updateBookingStatus(Booking $booking, Payment $payment, array $data): void
    {
        $transactionStatus = $data['transaction_status'];
        $fraudStatus = $data['fraud_status'] ?? null;

        if ($transactionStatus === 'capture' && $fraudStatus === 'accept') {
            $this->markAsConfirmed($booking, $payment);
        } elseif ($transactionStatus === 'settlement') {
            $this->markAsConfirmed($booking, $payment);
        } elseif ($transactionStatus === 'pending') {
            $booking->status = 'pending';
            $payment->payment_status = 'pending';
        } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
            $booking->status = 'cancelled';
            $payment->payment_status = 'rejected';
        }

        $booking->save();
        $payment->save();
    }

    /**
     * Mark booking as confirmed
     */
    private function markAsConfirmed(Booking $booking, Payment $payment): void
    {
        $booking->status = 'confirmed';
        $payment->payment_status = 'verified';
        $payment->paid_at = now();
    }

    /**
     * Clean phone number
     */
    private function cleanPhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (empty($clean)) {
            return '628000000000';
        }

        $clean = ltrim($clean, '0');

        if (substr($clean, 0, 2) !== '62') {
            $clean = '62' . $clean;
        }

        if (strlen($clean) < 10 || strlen($clean) > 15) {
            return '628000000000';
        }

        return $clean;
    }

    /**
     * Sanitize text
     */
    private function sanitizeText(string $text, int $maxLength = 50): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9 \-]/', '', $text);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = preg_replace('/\-+/', '-', $clean);
        $clean = trim($clean, ' -');

        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength - 3) . '...';
        }

        return empty($clean) ? 'Booking Lapangan' : $clean;
    }
}
