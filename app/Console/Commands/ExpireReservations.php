<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Mark reservations as expired when they pass their expiration time (idempotent)';

    public function handle(): int
    {
        $now = Carbon::now();
        $total = 0;

        Reservation::query()
            ->where('status', Reservation::STATUS_RESERVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$total) {
                /** @var \Illuminate\Support\Collection $chunk */
                $ids = $chunk->pluck('id')->all();
                $updated = Reservation::whereIn('id', $ids)
                    ->where('status', Reservation::STATUS_RESERVED)
                    ->update(['status' => Reservation::STATUS_EXPIRED]);
                $total += $updated;
            });

        $this->info("Expired {$total} reservations.");

        return self::SUCCESS;
    }
}
