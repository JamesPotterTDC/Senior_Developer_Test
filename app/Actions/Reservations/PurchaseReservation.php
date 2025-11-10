<?php

namespace App\Actions\Reservations;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseReservation
{
    public function handle(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation) {
            /** @var Reservation $lockedReservation */
            $lockedReservation = Reservation::whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->status !== Reservation::STATUS_RESERVED) {
                if ($lockedReservation->status === Reservation::STATUS_PURCHASED) {
                    throw new HttpException(409, 'already_purchased');
                }
                throw new HttpException(409, 'invalid_or_expired_reservation');
            }

            if ($lockedReservation->expires_at->isPast()) {
                throw new HttpException(409, 'invalid_or_expired_reservation');
            }

            // Idempotent update: only set purchased_at if it is currently NULL.
            $updated = Reservation::whereKey($lockedReservation->getKey())
                ->whereNull('purchased_at')
                ->update([
                    'status' => Reservation::STATUS_PURCHASED,
                    'purchased_at' => Carbon::now(),
                ]);

            if ($updated === 0) {
                // Likely already purchased concurrently
                throw new HttpException(409, 'already_purchased');
            }

            return $lockedReservation->refresh();
        });
    }
}
