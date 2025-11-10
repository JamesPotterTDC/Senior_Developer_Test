<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reservations\PurchaseReservation;
use App\Actions\Reservations\ReserveTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\ReserveRequest;
use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReservationController extends Controller
{
    public function reserve(Event $event, ReserveRequest $request, ReserveTicket $reserveTicket): JsonResponse
    {
        try {
            $reservation = $reserveTicket->handle($event);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'message' => 'Event is sold out.',
                ], 409);
            }
            throw $e;
        }

        return response()->json([
            'id' => $reservation->id,
            'event_id' => $reservation->event_id,
            'status' => $reservation->status,
            'expires_at' => $reservation->expires_at->toISOString(),
            'created_at' => $reservation->created_at->toISOString(),
        ], 201);
    }

    public function purchase(Reservation $reservation, PurchaseRequest $request, PurchaseReservation $purchaseReservation): JsonResponse
    {
        try {
            $updated = $purchaseReservation->handle($reservation);
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
            if ($status === 409) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'message' => $e->getMessage() === 'already_purchased'
                        ? 'Reservation already purchased.'
                        : 'Reservation is invalid or expired.',
                ], 409);
            }
            if ($status === 404) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'Reservation not found.',
                ], 404);
            }
            throw $e;
        }

        return response()->json([
            'id' => $updated->id,
            'event_id' => $updated->event_id,
            'status' => $updated->status,
            'expires_at' => $updated->expires_at->toISOString(),
            'created_at' => $updated->created_at->toISOString(),
            'updated_at' => $updated->updated_at->toISOString(),
        ], 200);
    }
}
