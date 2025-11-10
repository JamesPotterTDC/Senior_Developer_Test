<?php

namespace App\Actions\Reservations;

use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReserveTicket
{
    public function handle(Event $event): Reservation
    {
        return DB::transaction(function () use ($event) {
            // Lock the event row to prevent overselling under concurrency.
            /** @var Event $lockedEvent */
            $lockedEvent = Event::whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $purchasedCount = $lockedEvent->reservations()
                ->where('status', Reservation::STATUS_PURCHASED)
                ->count();

            $validReservedCount = $lockedEvent->reservations()
                ->where('status', Reservation::STATUS_RESERVED)
                ->where('expires_at', '>', Carbon::now())
                ->count();

            $available = $lockedEvent->capacity - $purchasedCount - $validReservedCount;
            if ($available <= 0) {
                throw new HttpException(409, 'sold_out');
            }

            /** @var Reservation $reservation */
            $reservation = $lockedEvent->reservations()->create([
                'status' => Reservation::STATUS_RESERVED,
                'expires_at' => Carbon::now()->addMinutes((int) config('reservations.expiry_minutes')),
            ]);

            return $reservation;
        });
    }
}
