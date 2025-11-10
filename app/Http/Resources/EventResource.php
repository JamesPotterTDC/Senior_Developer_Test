<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Event */
class EventResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $purchased = $this->reservations()
            ->where('status', Reservation::STATUS_PURCHASED)
            ->count();

        $reserved = $this->reservations()
            ->active()
            ->count();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'purchased' => $purchased,
            'reserved' => $reserved,
            'available' => max(0, $this->capacity - $purchased - $reserved),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
