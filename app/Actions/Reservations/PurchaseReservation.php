<?php

/**
 * Purchase a reservation idempotently.
 * We guard with whereNull('purchased_at') so retries and races cannot double-purchase.
 */

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

            // We reject wrong state or expired here to keep the hot path honest under retries.
            if ($lockedReservation->status !== Reservation::STATUS_RESERVED) {
                if ($lockedReservation->status === Reservation::STATUS_PURCHASED) {
                    throw new HttpException(409, 'already_purchased');
                }
                throw new HttpException(409, 'invalid_or_expired_reservation');
            }

            if ($lockedReservation->expires_at->isPast()) {
                throw new HttpException(409, 'invalid_or_expired_reservation');
            }

            // Idempotent update: only flip to purchased if not already purchased.
            $updated = Reservation::whereKey($lockedReservation->getKey())
                ->whereNull('purchased_at')
                ->update([
                    'status' => Reservation::STATUS_PURCHASED,
                    'purchased_at' => Carbon::now(),
                ]);

            if ($updated === 0) {
                // Another request won the race; signal a safe conflict.
                throw new HttpException(409, 'already_purchased');
            }

            return $lockedReservation->refresh();
        });
    }
}
