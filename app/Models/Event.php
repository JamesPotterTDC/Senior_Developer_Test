<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'capacity',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function purchasedCount(): int
    {
        return $this->reservations()
            ->where('status', Reservation::STATUS_PURCHASED)
            ->count();
    }

    public function validReservedCount(): int
    {
        return $this->reservations()
            ->where('status', Reservation::STATUS_RESERVED)
            ->where('expires_at', '>', Carbon::now())
            ->count();
    }
}
