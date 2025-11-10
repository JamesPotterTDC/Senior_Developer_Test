<?php

/**
 * Reserve a ticket within a DB transaction.
 * We lock the event row so availability maths is correct under pressure (no oversell).
 * Availability = capacity - purchased - active reserved (not expired).
 */

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
            // Lock event pessimistically; simple and predictable.
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

            // 409 signals a state conflict (sold out). It is not a schema error (422) or a bad request (400).
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
