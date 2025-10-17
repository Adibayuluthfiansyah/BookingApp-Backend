<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification
{
    use Queueable;

    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $booking = $this->booking->load('field.venue');

        return (new MailMessage)
            ->subject('Booking Dikonfirmasi - ' . $booking->booking_number)
            ->greeting('Halo ' . $booking->customer_name . '!')
            ->line('Pembayaran booking Anda telah berhasil dikonfirmasi.')
            ->line('**Detail Booking:**')
            ->line('Nomor Booking: **' . $booking->booking_number . '**')
            ->line('Venue: ' . $booking->field->venue->name)
            ->line('Lapangan: ' . $booking->field->name)
            ->line('Tanggal: ' . $booking->booking_date->format('d F Y'))
            ->line('Waktu: ' . substr($booking->start_time, 0, 5) . ' - ' . substr($booking->end_time, 0, 5))
            ->line('Total Bayar: Rp ' . number_format($booking->total_amount, 0, ',', '.'))
            ->action('Lihat Detail Booking', url('/booking/success?order_id=' . $booking->booking_number))
            ->line('Terima kasih telah menggunakan layanan kami!')
            ->line('Harap tunjukkan nomor booking ini saat datang ke venue.')
            ->salutation('Salam, Tim ' . config('app.name'));
    }
}
